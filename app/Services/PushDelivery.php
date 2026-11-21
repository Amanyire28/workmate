<?php

namespace App\Services;

use App\Jobs\FlushNotificationPushes;
use App\Models\NotificationPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\AfterCommit;
use App\Support\QuietHours;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The push outbox (Phase B). Used for likes, follows, comments and mentions while the platform
 * setting notifications.smart_push is on; booking, message, account and job notices keep their own
 * immediate path and never come through here.
 *
 * Nothing waits. When something happens, a row in notification_pushes is saved IN THE SAME
 * TRANSACTION as the event (so a rolled-back event leaves no push), and once the outermost
 * transaction commits it is tried at once, after the response has gone back. The row, not that
 * after-response call, is what makes it durable: the scheduler (workmate:notifications-flush,
 * every minute) retries anything that did not finish and recovers a push whose process died.
 *
 * How a push moves: it is CLAIMED with one atomic update (pending to sending) before anything is
 * sent, so a request, the scheduler and a retry can't send it twice. What happened to each device
 * is kept (notification_push_deliveries), so a retry goes only to devices that have not accepted it.
 * Then it is sent (done), skipped (with the reason), put back for a bounded retry, or given up.
 *
 * Delivery, precisely: "accepted" means the push service took the message; it says nothing about
 * the phone showing it (browsers, battery settings and permissions decide that). A push is sent at
 * most once to a device that accepted it. If the process dies after a device accepted a push but
 * before that was written down, the claim is made ready again after claim_recovery_minutes and that
 * device may get it once more: it carries the same tag, so the phone shows one notification.
 *
 * Grouping controls presentation, not timing: all the likes on a post are one notification in the
 * list, and the phone's notification for it is REPLACED (same tag) as more arrive, silently after
 * the first. Quiet hours make phone alerts silent; the list is always updated at once.
 *
 * The flush also runs without the scheduler's help in one way: every event tries its own push
 * immediately, so the scheduler is for retries, not for first sends.
 */
class PushDelivery
{
    /** Notification kinds that use the outbox (everything else keeps its own immediate path) */
    public const OUTBOX_KINDS = ['community_likes', 'community_follows', 'community_comments', 'community_mentions'];

    /** @var PushNotifications */
    private $push;

    /** @var EngagementNotifications|null */
    private $engagement;

    /** @var PlatformSettings */
    private $settings;

    /** @var AfterCommit */
    private $afterCommit;

    public function __construct(PushNotifications $push, PlatformSettings $settings, AfterCommit $afterCommit)
    {
        $this->push = $push;
        $this->settings = $settings;
        $this->afterCommit = $afterCommit;
    }

    /** Resolved when needed: EngagementNotifications needs this class too */
    private function engagement(): EngagementNotifications
    {
        return $this->engagement ?: $this->engagement = app(EngagementNotifications::class);
    }

    public function smartEnabled(): bool
    {
        try {
            return $this->settings->get('notifications.smart_push') === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Whether notices of this kind go through the outbox now */
    public function usesOutbox(string $kind): bool
    {
        return in_array($kind, self::OUTBOX_KINDS, true) && $this->smartEnabled();
    }

    /* ---------------- Making pushes (inside the event's transaction) ---------------- */

    /**
     * Makes sure a grouped like or follow notification has a push on its way. Call inside the
     * transaction that updated the group. If one is already waiting, it will say how the group
     * is when it goes (it reads the group as it is then), so nothing more is needed; if one is
     * in flight, a new one follows it.
     */
    public function enqueueGroup(UserNotification $row, string $type): void
    {
        // No device, nothing to push (and no row to write, for the many people who never turned notifications on)
        if (! PushSubscription::where('user_id', $row->user_id)->exists()) {
            return;
        }
        if (NotificationPush::where('user_notification_id', $row->id)->where('status', NotificationPush::PENDING)->exists()) {
            return;
        }
        $seq = NotificationPush::where('user_notification_id', $row->id)->count() + 1;
        $id = $this->make($row->user_id, $type, 'g:'.$row->group_key.':'.$seq, $row->id, now(), $row->kind);
        $this->attempt($id ? [$id] : []);
    }

    /**
     * An event that is pushed as it happened (a comment, a mention). Its wording is kept only
     * until the push is finished. Call inside the transaction that saved the event, then
     * attempt() with the ids once for the whole event. Returns 0 when the person has no device (nothing is saved).
     */
    public function enqueueDirect(int $userId, string $kind, array $message, ?int $groupRowId = null): int
    {
        if (! PushSubscription::where('user_id', $userId)->exists()) {
            return 0;
        }
        $payload = array_intersect_key($message, array_flip(['title', 'body', 'url', 'tag', 'important', 'icon']));
        if (empty($payload['icon'])) {
            unset($payload['icon']);   // no photo: the phone uses the WorkMate icon
        }
        $id = DB::table('notification_pushes')->insertGetId([
            'user_id' => $userId, 'type' => NotificationPush::TYPE_DIRECT, 'kind' => $kind, 'dedupe_key' => 'd:'.Str::uuid(),
            // $groupRowId: the notification (the first of a window of comments) this push is about; a later push
            // for the same one is an update to what the phone already shows, so it is silent
            'user_notification_id' => $groupRowId, 'payload' => json_encode($payload), 'status' => NotificationPush::PENDING, 'send_after' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) $id;
    }

    /** One summary push when the quiet hours end (for notices that are not engagement), for what arrived meanwhile */
    public function deferDigest(User $user, Carbon $endsAt): void
    {
        $this->make($user->getKey(), NotificationPush::TYPE_DIGEST, 'digest:'.$user->getKey().':'.$endsAt->timestamp, null, $endsAt, null);
    }

    private function make(int $userId, string $type, string $key, ?int $notificationId, Carbon $due, ?string $kind): ?int
    {
        $inserted = DB::table('notification_pushes')->insertOrIgnore([
            'user_id' => $userId, 'type' => $type, 'kind' => $kind, 'dedupe_key' => mb_substr($key, 0, 120), 'user_notification_id' => $notificationId,
            'status' => NotificationPush::PENDING, 'send_after' => $due, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $inserted ? (int) DB::table('notification_pushes')->where('dedupe_key', mb_substr($key, 0, 120))->value('id') : null;
    }

    /**
     * Tries these pushes once the outermost transaction has committed (and never if it rolls
     * back), after the response has gone back. The rows are already saved, so if this call is
     * lost (the process dies, the server restarts), the scheduler's next run finds them.
     *
     * @param  int[]  $ids
     */
    public function attempt(array $ids): void
    {
        if (! $ids) {
            return;
        }
        $this->afterCommit->run(function () use ($ids) {
            FlushNotificationPushes::dispatchAfterResponse(0, $ids);
        });
    }

    /* ---------------- Sending what is waiting ---------------- */

    /**
     * Sends what is due, up to $limit pushes: new ones the immediate attempt missed, and retries.
     *
     * @return array<string, int> how each push ended, and what was tidied
     */
    public function flushDue(int $limit): array
    {
        $stats = ['recovered' => 0, 'sent' => 0, 'skipped' => 0, 'retry' => 0, 'gave_up' => 0, 'held' => 0, 'busy' => 0, 'pruned' => 0];
        $stats['recovered'] = $this->recoverStuck();

        $now = now();
        $due = NotificationPush::where('status', NotificationPush::PENDING)->where(function ($q) use ($now) {
            $q->where('send_after', '<=', $now)->orWhereIn('type', [NotificationPush::TYPE_LIKES, NotificationPush::TYPE_FOLLOWS]);
        })
            ->where(function ($q) use ($now) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->orderBy('send_after')->orderBy('id')->limit($limit)->pluck('id');

        foreach ($due as $id) {
            $outcome = $this->process((int) $id);
            $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
        }

        $stats['pruned'] = $this->prune();
        Cache::put('notifications:flush:last_run', ['at' => now()->toIso8601String(), 'stats' => $stats], now()->addDays(14));
        if (array_sum($stats) > 0) {
            Log::info('Notification pushes flushed', $stats);
        }

        return $stats;
    }

    /** Tries these particular pushes now (the immediate attempt after a commit) */
    public function processIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $outcome = $this->process((int) $id);
            $out[$outcome] = ($out[$outcome] ?? 0) + 1;
        }

        return $out;
    }

    /** Makes ready again a push claimed by a process that never finished it */
    private function recoverStuck(): int
    {
        return NotificationPush::where('status', NotificationPush::SENDING)
            ->where('claimed_at', '<', now()->subMinutes((int) config('notification_policy.claim_recovery_minutes')))
            ->update(['status' => NotificationPush::PENDING, 'claimed_at' => null, 'note' => 'claim recovered']);
    }

    /** Finished rows are kept for a while for support, then removed in small batches (with their per-device rows) */
    private function prune(): int
    {
        $ids = NotificationPush::whereIn('status', [NotificationPush::SENT, NotificationPush::SKIPPED, NotificationPush::GAVE_UP])
            ->where('updated_at', '<', now()->subDays((int) config('notification_policy.keep_days')))->limit(500)->pluck('id');

        return $ids->isEmpty() ? 0 : NotificationPush::whereIn('id', $ids)->delete();
    }

    /**
     * Claims and sends one push.
     *
     * @return string sent | skipped | retry | gave_up | held | busy
     */
    public function process(int $id): string
    {
        $now = now();
        // Likes and follows are never "not yet due": a row saved by the earlier version may still carry its
        // old 15 or 30 minute wait, and nothing waits any more
        $claimed = NotificationPush::whereKey($id)->where('status', NotificationPush::PENDING)->where(function ($q) use ($now) {
            $q->where('send_after', '<=', $now)->orWhereIn('type', [NotificationPush::TYPE_LIKES, NotificationPush::TYPE_FOLLOWS]);
        })
            ->where(function ($q) use ($now) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->update(['status' => NotificationPush::SENDING, 'claimed_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);
        if (! $claimed) {
            return 'busy';   // someone else has it, it is finished, or it isn't due
        }

        $push = NotificationPush::find($id);
        try {
            return $this->decideAndSend($push);
        } catch (\Throwable $e) {
            // The wording is never put in the log
            Log::warning('Notification push failed: '.get_class($e).': '.$e->getMessage(), ['push' => $id, 'type' => $push->type]);

            return $this->retryOrGiveUp($push, 'error');
        }
    }

    private function decideAndSend(NotificationPush $push): string
    {
        $now = now();
        // Not checked: the setting decides whether NEW events use the outbox (usesOutbox). A push already saved is an
        // event that has happened, so it is still delivered and retried after the setting is switched off.
        $user = User::find($push->user_id);
        if (! $user || ! $user->isActive()) {
            return $this->finish($push, NotificationPush::SKIPPED, 'account inactive');
        }
        $isDigest = $push->type === NotificationPush::TYPE_DIGEST;
        $staleMinutes = $isDigest ? 1440 : (int) config('notification_policy.stale_after_minutes');
        $since = $isDigest ? $push->send_after : $push->created_at;
        if ($since->lt($now->copy()->subMinutes($staleMinutes))) {
            return $this->finish($push, NotificationPush::SKIPPED, 'stale');
        }

        // The person's choices are looked at again now: they may have changed since the event
        $kind = $push->kind ?: ($push->type === NotificationPush::TYPE_LIKES ? EngagementNotifications::LIKES
            : ($push->type === NotificationPush::TYPE_FOLLOWS ? EngagementNotifications::FOLLOWS : null));
        $prefs = $user->notification_preferences ?: [];
        if ($kind && (($prefs[$kind] ?? true) === false || ($prefs['push:'.$kind] ?? true) === false)) {
            return $this->finish($push, NotificationPush::SKIPPED, 'turned off');
        }

        $quietHours = QuietHours::forUser($user);
        $quiet = $quietHours && $quietHours->isQuiet($now);

        // What to say
        if ($isDigest) {
            if ($quiet) {
                return $this->hold($push, $quietHours->endsAt($now), 'quiet hours');
            }
            $message = $this->digestMessage($push, $user);
        } elseif ($push->type === NotificationPush::TYPE_DIRECT) {
            $message = json_decode((string) $push->payload, true) ?: null;
        } else {
            $row = UserNotification::find($push->user_notification_id);
            if (! $row || $row->read_at !== null) {
                return $this->finish($push, NotificationPush::SKIPPED, 'already read');
            }
            $message = $this->engagement()->messageNow($row);
            if (! $message) {
                return $this->finish($push, NotificationPush::SKIPPED, 'no one left');
            }
        }
        if (! $message) {
            return $this->finish($push, NotificationPush::SKIPPED, 'nothing new');
        }

        // Anti-abuse: a hard limit on how many pushes one person is sent in a minute. Past it the push is not
        // dropped: it is folded into ONE summary push for the minute ("You have 40 new notifications"). The
        // list is not affected either way.
        // It counts the pushes this person was actually sent in the last minute (or that are being sent right now),
        // not the ones merely waiting, so a crowd of waiting pushes is let through in order up to the limit.
        if (! $isDigest && NotificationPush::where('user_id', $user->id)->where('id', '!=', $push->id)->where('type', '!=', NotificationPush::TYPE_DIGEST)
            ->where(function ($q) use ($now) {
                $q->where('status', NotificationPush::SENDING)->orWhere(function ($q) use ($now) {
                    $q->where('status', NotificationPush::SENT)->where('sent_at', '>=', $now->copy()->subMinute());
                });
            })->count() >= (int) config('notification_policy.pushes_per_minute')) {
            return $this->summarize($push, $user);
        }

        // Silent or with a sound. Never lost: only quieter.
        $silent = false;
        if (! $isDigest) {
            $silent = $quiet;                                                           // their quiet hours
            if (! $silent && $push->user_notification_id) {
                $silent = NotificationPush::where('user_notification_id', $push->user_notification_id)
                    ->where('status', NotificationPush::SENT)->where('id', '!=', $push->id)->exists();   // an update to what the phone already shows
            }
            if (! $silent && NotificationPush::where('user_id', $user->id)->where('status', NotificationPush::SENT)->where('silent', false)
                ->where('sent_at', '>=', $now->copy()->subHour())->count() >= (int) config('notification_policy.audible_per_hour')) {
                $silent = true;                                                         // too many sounds in the last hour
            }
        }
        $push->forceFill(['silent' => $silent])->save();
        if ($silent) {
            $message['silent'] = true;
        }

        // Which devices still need it
        $devices = PushSubscription::where('user_id', $user->id)->get();
        if ($devices->isEmpty()) {
            return $this->finish($push, NotificationPush::SKIPPED, 'no devices');
        }
        $resolved = DB::table('notification_push_deliveries')->where('notification_push_id', $push->id)
            ->whereIn('status', ['accepted', 'rejected', 'expired'])->pluck('push_subscription_id')->all();
        $todo = $devices->reject(function ($d) use ($resolved) {
            return in_array($d->id, $resolved, true);
        })->values();
        if ($todo->isEmpty()) {
            return $this->conclude($push, 0, 0, 0, null);
        }

        $urgency = $silent || in_array($kind, [EngagementNotifications::LIKES, EngagementNotifications::FOLLOWS], true) || $isDigest ? 'low' : 'normal';
        $results = $this->push->sendToSubscriptions($todo, $message, $urgency);
        $existing = PushSubscription::whereIn('id', array_keys($results))->pluck('id')->all();   // some were removed (gone, or failing too long)

        $accepted = $failed = $rejected = 0;
        $http = null;
        foreach ($results as $subscriptionId => $r) {
            $http = $r['http'] ?? $http;
            $accepted += $r['status'] === 'accepted' ? 1 : 0;
            $rejected += $r['status'] === 'rejected' ? 1 : 0;
            $failed += $r['status'] === 'failed' && in_array($subscriptionId, $existing, true) ? 1 : 0;
            if (in_array($subscriptionId, $existing, true)) {
                $this->record($push->id, $subscriptionId, $r['status'], $r['http']);
            }
        }
        $expired = count(array_filter($results, function ($r) {
            return $r['status'] === 'expired';
        }));

        return $this->conclude($push, $accepted, $expired, $failed, $http, $rejected);
    }

    /**
     * Past the hard limit: this push is replaced by one summary push for the person, sent a minute later
     * (and counting everything unread since the first push that went over). Many over-the-limit pushes make one.
     */
    private function summarize(NotificationPush $push, User $user): string
    {
        $due = now()->addMinute();
        $key = 'digest:overflow:'.$user->id.':'.$due->copy()->startOfMinute()->timestamp;
        $id = $this->make($user->id, NotificationPush::TYPE_DIGEST, $key, null, $due, null);
        if ($id) {
            // The summary counts from the first push it stands in for
            DB::table('notification_pushes')->where('id', $id)->update(['created_at' => $push->created_at]);
        }

        return $this->finish($push, NotificationPush::SKIPPED, 'summarized');
    }

    /** What happened to one device, kept for the retry (statuses and codes only) */
    private function record(int $pushId, int $subscriptionId, string $status, ?int $http): void
    {
        $where = ['notification_push_id' => $pushId, 'push_subscription_id' => $subscriptionId];
        if (DB::table('notification_push_deliveries')->where($where)->exists()) {
            DB::table('notification_push_deliveries')->where($where)->update(['status' => $status, 'http_status' => $http, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        } else {
            DB::table('notification_push_deliveries')->insert($where + ['status' => $status, 'http_status' => $http, 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** After an attempt: done, or a retry for the devices that could not be reached */
    private function conclude(NotificationPush $push, int $accepted, int $expired, int $failed, ?int $http, int $rejected = 0): string
    {
        $push->forceFill([
            'devices_delivered' => $push->devices_delivered + $accepted, 'devices_expired' => $push->devices_expired + $expired,
            'devices_failed' => $failed, 'last_http_status' => $http ?: $push->last_http_status,
        ])->save();

        if ($failed === 0) {
            if ($push->devices_delivered > 0) {
                return $this->finish($push, NotificationPush::SENT, null);
            }

            return $rejected > 0 ? $this->finish($push, NotificationPush::GAVE_UP, 'rejected') : $this->finish($push, NotificationPush::SKIPPED, 'devices gone');
        }

        return $this->retryOrGiveUp($push, 'not reached');
    }

    private function digestMessage(NotificationPush $push, User $user): ?array
    {
        $count = UserNotification::where('user_id', $user->id)->whereNull('read_at')
            ->where('created_at', '>=', $push->created_at->copy()->subSeconds(5))
            ->where(function ($q) {
                $q->whereNull('group_count')->orWhere('group_count', '>', 0);
            })->count();
        if ($count < 1) {
            return null;
        }

        return [
            'title' => 'WorkMate', 'body' => 'You have '.$count.' new '.($count === 1 ? 'notification' : 'notifications').'.',
            'url' => '/notifications', 'tag' => 'digest',
        ];
    }

    /** Not sent yet: wait, without using up an attempt */
    private function hold(NotificationPush $push, Carbon $until, string $why): string
    {
        $push->forceFill([
            'status' => NotificationPush::PENDING, 'send_after' => $until, 'claimed_at' => null,
            'attempts' => max(0, $push->attempts - 1), 'note' => $why,
        ])->save();

        return 'held';
    }

    /** Finished. The wording is not kept. */
    private function finish(NotificationPush $push, string $status, ?string $why): string
    {
        $push->forceFill([
            'status' => $status, 'claimed_at' => null, 'note' => $why, 'payload' => null,
            'sent_at' => $status === NotificationPush::SENT ? now() : $push->sent_at,
        ])->save();

        return $status === NotificationPush::SKIPPED ? 'skipped' : ($status === NotificationPush::SENT ? 'sent' : 'gave_up');
    }

    /** Some device could not be reached: try those again later (bounded), then give up */
    private function retryOrGiveUp(NotificationPush $push, string $why): string
    {
        $waits = config('notification_policy.retry_seconds');
        if ($push->attempts > count($waits)) {
            // Out of tries. If a device did take it, that counts as sent.
            return $push->devices_delivered > 0 ? $this->finish($push, NotificationPush::SENT, 'partial') : $this->finish($push, NotificationPush::GAVE_UP, $why);
        }
        $push->forceFill([
            'status' => NotificationPush::PENDING, 'claimed_at' => null, 'note' => $why,
            'next_attempt_at' => now()->addSeconds($waits[$push->attempts - 1]),
        ])->save();

        return 'retry';
    }
}
