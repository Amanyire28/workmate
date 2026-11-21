<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\AfterCommit;
use App\Support\QuietHours;
use Illuminate\Support\Facades\DB;

/**
 * Delivers what EventNotifications writes: into each account's notification
 * list (the bell), and as a push to devices that turned notifications on.
 * Each account chooses which kinds it wants in the bell, and separately
 * whether each kind may also push; account and safety notices always arrive.
 * An unread notification with the same tag is updated and moved to the top
 * instead of repeated, as phones do with push.
 *
 * Priority decides how pushes are treated while smart push is on
 * (notifications.smart_push): high is never held back, medium and low wait
 * for the end of the account's quiet hours (then arrive as one summary).
 */
class UserNotifications
{
    /** Kinds an account can turn off, as shown in its settings */
    public const CHOICES = [
        'requests' => ['Requests and jobs', 'New requests, replies, applications and hiring'],
        'job_alerts' => ['New jobs near you', 'Jobs posted near you in your line of work'],
        'messages' => ['Messages', 'New messages in your chats'],
        'community_comments' => ['Comments and replies', 'On your posts and comments in the community'],
        'community_mentions' => ['Mentions', 'When someone mentions you in a post or comment'],
        'community_posts' => ['Posts from people you follow', 'When someone you follow shares something (at most every few hours each)'],
        'community_likes' => ['Likes', 'When people like your posts'],
        'community_follows' => ['New followers', 'When someone follows you in the community'],
        'referrals' => ['Invites and badges', 'When someone you invited joins, and when you earn a badge'],
    ];

    /** Decisions, documents, reports, warnings and removals: always sent */
    public const ACCOUNT = 'account';

    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /** How urgent each kind is. Anything not listed is medium. */
    private const PRIORITY = [
        'requests' => self::HIGH,
        'messages' => self::HIGH,
        self::ACCOUNT => self::HIGH,
        'job_alerts' => self::MEDIUM,
        'community_comments' => self::MEDIUM,
        'community_mentions' => self::MEDIUM,
        'community_posts' => self::MEDIUM,
        'referrals' => self::MEDIUM,
        'community_likes' => self::LOW,
        'community_follows' => self::LOW,
    ];

    /** Web Push "Urgency" for each priority */
    private const URGENCY = [self::HIGH => 'high', self::MEDIUM => 'normal', self::LOW => 'low'];

    // Older notifications are cleared as new ones arrive
    private const KEEP_DAYS = 90;

    /** @var PushNotifications */
    private $push;

    /** @var AfterCommit */
    private $afterCommit;

    public function __construct(PushNotifications $push, AfterCommit $afterCommit)
    {
        $this->push = $push;
        $this->afterCommit = $afterCommit;
    }

    public static function priority(string $kind): string
    {
        return self::PRIORITY[$kind] ?? self::MEDIUM;
    }

    /**
     * @param  iterable<int>  $userIds
     * @param  array{title: string, body: string, url: string, tag: ?string, important?: bool, push?: bool, actor_id?: int}  $message
     *         push false: the bell only (for things the person just did in the app)
     */
    public function send(string $kind, iterable $userIds, array $message): void
    {
        $ids = collect($userIds)->filter()->map(function ($id) {
            return (int) $id;
        })->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $wanted = $kind === self::ACCOUNT ? $ids : $this->wanting($kind, $ids->all());
        if ($wanted->isEmpty()) {
            return;
        }

        $push = ($message['push'] ?? true) !== false;
        unset($message['push']);
        foreach ($wanted as $id) {
            $this->record($id, $kind, $message);
        }
        if ($push) {
            $this->pushOnly($kind, $wanted, $message);
        }
    }

    /**
     * Pushes to devices without writing a bell row (the row was written already).
     * Per person: a kind they set to "bell only" isn't pushed, and while smart
     * push is on, medium and low priority wait out their quiet hours (one
     * summary push when those end). Sent once the outermost transaction commits.
     *
     * @param  iterable<int>  $userIds
     */
    public function pushOnly(string $kind, iterable $userIds, array $message): void
    {
        $ids = collect($userIds)->map(function ($id) {
            return (int) $id;
        })->unique()->values();
        if ($ids->isEmpty() || ! PushNotifications::configured()) {
            return;
        }
        // Not part of what a phone is sent: who it was (the picture is `icon`), and which notification a
        // push belongs to (a later push for the same one replaces the phone's notification silently)
        $groupRowId = isset($message['group_row_id']) ? (int) $message['group_row_id'] : null;
        $message = array_diff_key($message, ['actor_id' => 1, 'group_row_id' => 1]);

        $priority = self::priority($kind);
        $delivery = app(PushDelivery::class);
        $smart = $priority !== self::HIGH && $delivery->smartEnabled();
        // Likes, follows, comments and mentions go through the push outbox (saved with the event, tried
        // at once after the commit, retried by the scheduler); quiet hours make those silent, not later
        $outbox = $delivery->usesOutbox($kind);
        $now = now();
        $immediate = [];
        $queued = [];
        $people = User::whereIn('id', $ids->all())->get(['id', 'notification_preferences', 'quiet_hours_start', 'quiet_hours_end', 'timezone'])->keyBy('id');
        foreach ($ids as $id) {
            $user = $people->get($id);
            if (! $user || ($kind !== self::ACCOUNT && (($user->notification_preferences['push:'.$kind] ?? true) === false))) {
                continue;
            }
            if ($outbox) {
                if ($pushId = $delivery->enqueueDirect($id, $kind, $message, $groupRowId)) {
                    $queued[] = $pushId;
                }

                continue;
            }
            $quiet = $smart ? QuietHours::forUser($user) : null;
            if ($quiet && $quiet->isQuiet($now)) {
                app(PushDelivery::class)->deferDigest($user, $quiet->endsAt($now));

                continue;
            }
            $immediate[] = $id;
        }

        $delivery->attempt($queued);
        if ($immediate) {
            $urgency = self::URGENCY[$priority];
            $this->afterCommit->run(function () use ($immediate, $message, $urgency) {
                $this->push->toUsers($immediate, $message, $urgency);
            });
        }
    }

    /** @return array<string, array{label: string, hint: string, on: bool, push: bool, priority: string}> */
    public function choices(User $user): array
    {
        $prefs = $user->notification_preferences ?: [];
        $out = [];
        foreach (self::CHOICES as $kind => [$label, $hint]) {
            // Only providers are told about new jobs
            if ($kind === 'job_alerts' && ! $user->isProvider()) {
                continue;
            }
            $out[$kind] = [
                'label' => $label, 'hint' => $hint,
                'on' => ($prefs[$kind] ?? true) !== false,
                // Whether it may also push to this account's devices (the bell keeps it either way)
                'push' => ($prefs['push:'.$kind] ?? true) !== false,
                'priority' => self::priority($kind),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, bool> $changes  kinds to turn on or off in the bell
     * @param array<string, bool> $push     kinds to allow or stop pushing to devices
     */
    public function choose(User $user, array $changes, array $push = []): void
    {
        $prefs = $user->notification_preferences ?: [];
        foreach ($changes as $kind => $on) {
            if (isset(self::CHOICES[$kind])) {
                // Only what's turned off is stored
                if ($on) {
                    unset($prefs[$kind]);
                } else {
                    $prefs[$kind] = false;
                }
            }
        }
        foreach ($push as $kind => $on) {
            if (isset(self::CHOICES[$kind])) {
                if ($on) {
                    unset($prefs['push:'.$kind]);
                } else {
                    $prefs['push:'.$kind] = false;
                }
            }
        }
        $user->forceFill(['notification_preferences' => $prefs ?: null])->save();
    }

    /** @return array{enabled: bool, start: ?string, end: ?string, timezone: string} */
    public function quietHours(User $user): array
    {
        return [
            'enabled' => QuietHours::forUser($user) !== null,
            'start' => $user->quiet_hours_start,
            'end' => $user->quiet_hours_end,
            'timezone' => QuietHours::validZone($user->timezone) ? $user->timezone : config('notification_policy.default_timezone'),
        ];
    }

    /**
     * @param  array{enabled: bool, start?: ?string, end?: ?string, timezone?: ?string}  $data
     *
     * @throws BusinessRuleException
     */
    public function setQuietHours(User $user, array $data): void
    {
        if (empty($data['enabled'])) {
            // Switching off clears the times (the settings page remembers the last ones for turning it on again)
            $user->forceFill(['timezone' => QuietHours::validZone($data['timezone'] ?? null) ? $data['timezone'] : $user->timezone])
                ->forceFill(['quiet_hours_start' => null, 'quiet_hours_end' => null])->save();

            return;
        }
        $start = $data['start'] ?? null;
        $end = $data['end'] ?? null;
        if (! QuietHours::validTime($start) || ! QuietHours::validTime($end) || $start === $end) {
            throw new BusinessRuleException('quiet_hours_invalid', 'Choose a start and an end time that are different.', 422);
        }
        $user->forceFill([
            'quiet_hours_start' => $start,
            'quiet_hours_end' => $end,
            'timezone' => QuietHours::validZone($data['timezone'] ?? null) ? $data['timezone'] : $user->timezone,
        ])->save();
    }

    /** Unread notifications (a like or follow group with no one left in it isn't counted) */
    public function unreadCount(User $user): int
    {
        return $this->visible(UserNotification::where('user_id', $user->getKey())->whereNull('read_at'))->count();
    }

    /** A post anyone can see: published, not deleted, its author's account active (the post's id is the expression) */
    private const POST_SEEN = "exists (select 1 from community_posts p join users pa on pa.id = p.user_id where p.id = %s and p.status = 'published' and p.deleted_at is null and pa.status = 'active' and pa.deleted_at is null)";

    /**
     * What the list and the unread count show: leaves out
     *  - the notifications inside a group (they are shown as the group),
     *  - a like or follow group whose people have all taken it back,
     *  - anything about a post that is removed, deleted or from a suspended account,
     *  - a comment or reply that was deleted or moderated, or whose author is suspended,
     *  - a comment group with no comment left to see.
     * Notifications from before this (no subject) and everything else are shown as always.
     */
    public function visible($query)
    {
        $post = function (string $id) {
            return sprintf(self::POST_SEEN, $id);
        };
        $comment = "exists (select 1 from community_comments c join users cu on cu.id = c.user_id where c.id = user_notifications.subject_id and c.status = 'published' and c.deleted_at is null and cu.status = 'active' and cu.deleted_at is null and ".$post('c.community_post_id').')';
        $someComment = "exists (select 1 from user_notifications ch join community_comments c on c.id = ch.subject_id join users cu on cu.id = c.user_id where ch.parent_id = user_notifications.id and c.status = 'published' and c.deleted_at is null and cu.status = 'active' and cu.deleted_at is null)";

        return $query->whereNull('parent_id')
            ->where(function ($q) {
                $q->whereNull('group_count')->orWhere('group_count', '>', 0);
            })
            ->whereRaw('(user_notifications.subject_type is null'
                .' or (user_notifications.subject_type in (\'post\', \'video\', \'photo\', \'question\') and '.$post('user_notifications.subject_id').')'
                .' or (user_notifications.subject_type in (\'comment\', \'reply\') and '.$comment.')'
                .' or (user_notifications.subject_type = \'burst\' and '.$post('user_notifications.subject_id').' and '.$someComment.')'
                .' or user_notifications.subject_type not in (\'post\', \'video\', \'photo\', \'question\', \'comment\', \'reply\', \'burst\'))');
    }

    /** Marks one notification, or all of them, as read */
    public function markRead(User $user, ?int $id = null): void
    {
        UserNotification::where('user_id', $user->getKey())->whereNull('read_at')
            ->when($id, function ($q) use ($id) {
                // A group, and the notifications that were folded into it
                $q->where(function ($w) use ($id) {
                    $w->whereKey($id)->orWhere('parent_id', $id);
                });
            })
            ->update(['read_at' => now()]);
    }

    /** Whether this person wants this kind of notification (account notices always) */
    public function wants(int $userId, string $kind): bool
    {
        return $kind === self::ACCOUNT || $this->wanting($kind, [$userId])->isNotEmpty();
    }

    /** The accounts among $ids that haven't turned this kind off */
    private function wanting(string $kind, array $ids)
    {
        $off = User::whereIn('id', $ids)->whereNotNull('notification_preferences')->get(['id', 'notification_preferences'])
            ->filter(function (User $u) use ($kind) {
                return ($u->notification_preferences[$kind] ?? true) === false;
            })->pluck('id')->all();

        return collect($ids)->diff($off)->values();
    }

    private function record(int $userId, string $kind, array $message): void
    {
        $fields = [
            'kind' => $kind,
            'title' => mb_substr($message['title'], 0, 120),
            'body' => mb_substr($message['body'], 0, 300),
            'url' => mb_substr($message['url'], 0, 300),
            'actor_id' => isset($message['actor_id']) ? (int) $message['actor_id'] : null,
        ];
        $tag = $message['tag'] ?? null;

        DB::transaction(function () use ($userId, $fields, $tag) {
            $same = $tag ? UserNotification::where('user_id', $userId)->where('tag', $tag)->whereNull('read_at')->whereNull('group_key')->lockForUpdate()->first() : null;
            if ($same) {
                // Moved to the top with the newest wording
                $same->forceFill($fields + ['created_at' => now()])->save();
            } else {
                $row = new UserNotification();
                $row->forceFill($fields + ['user_id' => $userId, 'tag' => $tag])->save();
            }
        });

        // Now and then, clear out old ones
        if (random_int(1, 20) === 1) {
            UserNotification::where('user_id', $userId)->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        }
    }
}
