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

/**
 * Pushes that wait (Step 5): grouped likes and follows, and the summary after
 * quiet hours. Active only while the platform setting notifications.smart_push
 * is on; booking, message and mention pushes never come through here.
 *
 * How a push moves: a row in notification_pushes is made (dedupe_key is unique,
 * so asking twice makes one), becomes due at send_after, and is CLAIMED with one
 * atomic update (pending to sending) before anything is sent, so two scheduler
 * runs, a request that nudges the flush and a retry can't send it twice. After
 * the send it is sent, skipped (with the reason), put back for a retry with a
 * growing wait (bounded), or given up.
 *
 * Guarantees, honestly: a push is sent at most once to a device that accepted
 * it (a retry happens only when no device accepted it). If the process dies
 * between sending and writing "sent", the claim is made ready again after
 * claim_recovery_minutes and may send once more (the phone shows one
 * notification, as pushes carry a tag). Browsers, phones and permissions can
 * still stop a push arriving; nothing here can promise otherwise.
 *
 * The flush runs from the scheduler (workmate:notifications-flush, every
 * minute). If the scheduler isn't running, a like or follow anywhere on the
 * site nudges a small flush after its response, so pushes still go out, only
 * later than their time and without a retry until something nudges again.
 */
class PushDelivery
{
    private const NUDGE_SECONDS = 20;
    private const NUDGE_BATCH = 20;

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

    /* ---------------- Making pushes ---------------- */

    /**
     * Makes sure a grouped like or follow notification has a push waiting: due
     * after the window, or straight away once enough different people are in it.
     * Call inside the transaction that updated the group.
     */
    public function scheduleGroup(UserNotification $row, string $type, int $count): void
    {
        $policy = config($type === NotificationPush::TYPE_LIKES ? 'notification_policy.likes' : 'notification_policy.follows');
        $last = NotificationPush::where('user_notification_id', $row->id)->orderByDesc('id')->first();
        $now = now()->startOfSecond();

        if (! $last) {
            $due = $row->group_started_at->copy()->addMinutes($policy['window_minutes']);
            $this->make($row->user_id, $type, 'g:'.$row->group_key.':1', $row->id, $due);
        } elseif ($last->status === NotificationPush::SENT) {
            // More people after a push: another, once the cooldown for this content has passed
            $due = $last->sent_at->copy()->addHours($policy['cooldown_hours']);
            $this->make($row->user_id, $type, 'g:'.$row->group_key.':'.($last->id + 1), $row->id, $due->max($now));
        }

        $early = false;
        if ($count >= $policy['early_at']) {
            $early = NotificationPush::where('user_notification_id', $row->id)->where('status', NotificationPush::PENDING)
                ->where('send_after', '>', $now)->update(['send_after' => $now]) > 0;
        }
        // Once the transaction is really saved: a push that just became due goes out at once
        $this->afterCommit->run(function () use ($early) {
            $this->nudge($early);
        });
    }

    /** One summary push when the quiet hours end, for what arrived meanwhile */
    public function deferDigest(User $user, Carbon $endsAt): void
    {
        $this->make($user->getKey(), NotificationPush::TYPE_DIGEST, 'digest:'.$user->getKey().':'.$endsAt->timestamp, null, $endsAt);
    }

    private function make(int $userId, string $type, string $key, ?int $notificationId, Carbon $due): void
    {
        DB::table('notification_pushes')->insertOrIgnore([
            'user_id' => $userId, 'type' => $type, 'dedupe_key' => mb_substr($key, 0, 120), 'user_notification_id' => $notificationId,
            'status' => NotificationPush::PENDING, 'send_after' => $due, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Without a scheduler: a small flush after this response, at most one every
     * few seconds (unless $now: a push just became due, and claims make it safe)
     */
    public function nudge(bool $now = false): void
    {
        if ($now || Cache::add('notifications:nudge', 1, self::NUDGE_SECONDS)) {
            FlushNotificationPushes::dispatchAfterResponse(self::NUDGE_BATCH);
        }
    }

    /* ---------------- Sending what is due ---------------- */

    /**
     * Sends what is due, up to $limit pushes (and at most one per person per
     * run, so a long quiet night can't become a burst).
     *
     * @return array<string, int> how each push ended, and what was tidied
     */
    public function flushDue(int $limit): array
    {
        $stats = ['recovered' => 0, 'sent' => 0, 'skipped' => 0, 'retry' => 0, 'gave_up' => 0, 'held' => 0, 'busy' => 0, 'pruned' => 0];
        $stats['recovered'] = $this->recoverStuck();

        $now = now();
        $due = NotificationPush::where('status', NotificationPush::PENDING)->where('send_after', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->orderBy('send_after')->orderBy('id')->limit($limit * 3)->get(['id', 'user_id']);

        $people = [];
        $handled = 0;
        foreach ($due as $row) {
            if ($handled >= $limit) {
                break;
            }
            if (isset($people[$row->user_id])) {
                continue;   // one per person per run; the next run takes the rest
            }
            $outcome = $this->process($row->id);
            if ($outcome === 'busy') {
                $stats['busy']++;

                continue;
            }
            $handled++;
            $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
            if ($outcome === 'sent') {
                $people[$row->user_id] = true;
            }
        }

        $stats['pruned'] = $this->prune();
        Cache::put('notifications:flush:last_run', ['at' => now()->toIso8601String(), 'stats' => $stats], now()->addDays(14));
        if ($handled || $stats['recovered']) {
            Log::info('Notification pushes flushed', $stats);
        }

        return $stats;
    }

    /** Makes ready again a push claimed by a process that never finished it */
    private function recoverStuck(): int
    {
        return NotificationPush::where('status', NotificationPush::SENDING)
            ->where('claimed_at', '<', now()->subMinutes((int) config('notification_policy.claim_recovery_minutes')))
            ->update(['status' => NotificationPush::PENDING, 'claimed_at' => null, 'note' => 'claim recovered']);
    }

    /** Finished rows are kept for a while for support, then removed in small batches */
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
        $claimed = NotificationPush::whereKey($id)->where('status', NotificationPush::PENDING)->where('send_after', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->update(['status' => NotificationPush::SENDING, 'claimed_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);
        if (! $claimed) {
            return 'busy';   // someone else has it, or it isn't due
        }

        $push = NotificationPush::find($id);
        try {
            return $this->decideAndSend($push);
        } catch (\Throwable $e) {
            Log::warning('Notification push failed: '.$e->getMessage(), ['push' => $id, 'type' => $push->type]);

            return $this->failed($push, 'error');
        }
    }

    private function decideAndSend(NotificationPush $push): string
    {
        $now = now();
        if (! $this->smartEnabled()) {
            return $this->finish($push, NotificationPush::SKIPPED, 'smart push off');
        }
        $user = User::find($push->user_id);
        if (! $user || ! $user->isActive()) {
            return $this->finish($push, NotificationPush::SKIPPED, 'account inactive');
        }
        if ($push->send_after->lt($now->copy()->subHours((int) config('notification_policy.stale_after_hours')))) {
            return $this->finish($push, NotificationPush::SKIPPED, 'stale');
        }

        $prefs = $user->notification_preferences ?: [];
        $kind = $push->type === NotificationPush::TYPE_LIKES ? EngagementNotifications::LIKES
            : ($push->type === NotificationPush::TYPE_FOLLOWS ? EngagementNotifications::FOLLOWS : null);
        if ($kind && (($prefs[$kind] ?? true) === false || ($prefs['push:'.$kind] ?? true) === false)) {
            return $this->finish($push, NotificationPush::SKIPPED, 'turned off');
        }

        // Quiet hours: wait for them to end (low and medium priority only; this class carries nothing else)
        $quiet = QuietHours::forUser($user);
        if ($quiet && $quiet->isQuiet($now)) {
            return $this->hold($push, $quiet->endsAt($now), 'quiet hours');
        }

        if ($push->type === NotificationPush::TYPE_DIGEST) {
            $message = $this->digestMessage($push, $user);
        } else {
            $row = UserNotification::find($push->user_notification_id);
            if (! $row || $row->read_at !== null) {
                return $this->finish($push, NotificationPush::SKIPPED, 'already read');
            }
            $message = $this->engagement()->messageNow($row);
            if (! $message) {
                return $this->finish($push, NotificationPush::SKIPPED, 'no one left');
            }

            $policy = config($push->type === NotificationPush::TYPE_LIKES ? 'notification_policy.likes' : 'notification_policy.follows');
            // The same content, or the same kind of news, not again too soon
            $lastSame = $this->lastSentFor($push, $row);
            if ($lastSame && $lastSame->copy()->addHours($policy['cooldown_hours'])->gt($now)) {
                return $this->hold($push, $lastSame->copy()->addHours($policy['cooldown_hours']), 'cooldown');
            }
            $today = NotificationPush::where('user_id', $user->id)->where('type', $push->type)->where('status', NotificationPush::SENT)
                ->where('sent_at', '>=', QuietHours::dayStart($user, $now))->count();
            if ($today >= $policy['max_per_day']) {
                return $this->hold($push, QuietHours::nextDayStart($user, $now), 'daily limit');
            }
        }
        if (! $message) {
            return $this->finish($push, NotificationPush::SKIPPED, 'nothing new');
        }
        if (! PushSubscription::where('user_id', $user->id)->exists()) {
            return $this->finish($push, NotificationPush::SKIPPED, 'no devices');
        }

        [$delivered, $failed, $expired] = $this->push->deliverNow([$user->id], $message, 'low');
        if ($delivered > 0) {
            $push->forceFill(['status' => NotificationPush::SENT, 'sent_at' => $now, 'devices_delivered' => $delivered, 'claimed_at' => null, 'note' => null])->save();

            return 'sent';
        }
        if ($failed > 0) {
            return $this->failed($push, 'not accepted');
        }

        return $this->finish($push, NotificationPush::SKIPPED, 'no devices');
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

    /** When the last push of this kind about this content was sent */
    private function lastSentFor(NotificationPush $push, UserNotification $row): ?Carbon
    {
        $types = $row->kind === EngagementNotifications::LIKES ? ['post', 'video'] : ['account'];
        $at = NotificationPush::where('notification_pushes.user_id', $push->user_id)->where('notification_pushes.type', $push->type)
            ->where('notification_pushes.status', NotificationPush::SENT)->where('notification_pushes.id', '!=', $push->id)
            ->join('user_notifications as n', 'n.id', '=', 'notification_pushes.user_notification_id')
            ->whereIn('n.subject_type', $types)->where('n.subject_id', $row->subject_id)
            ->max('notification_pushes.sent_at');

        return $at ? Carbon::parse($at) : null;
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

    private function finish(NotificationPush $push, string $status, string $why): string
    {
        $push->forceFill(['status' => $status, 'claimed_at' => null, 'note' => $why])->save();

        return $status === NotificationPush::SKIPPED ? 'skipped' : $status;
    }

    /** No device accepted it: try again later (bounded), then give up */
    private function failed(NotificationPush $push, string $why): string
    {
        $waits = config('notification_policy.retry_minutes');
        if ($push->attempts > count($waits)) {
            $push->forceFill(['status' => NotificationPush::GAVE_UP, 'claimed_at' => null, 'note' => $why])->save();

            return 'gave_up';
        }
        $push->forceFill([
            'status' => NotificationPush::PENDING, 'claimed_at' => null, 'note' => $why,
            'next_attempt_at' => now()->addMinutes($waits[$push->attempts - 1]),
        ])->save();

        return 'retry';
    }
}
