<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

/**
 * Delivers what EventNotifications writes: into each account's notification
 * list (the bell), and as a push to devices that turned notifications on.
 * Each account chooses which kinds it wants; account and safety notices
 * always arrive. An unread notification with the same tag is updated and
 * moved to the top instead of repeated, as phones do with push.
 */
class UserNotifications
{
    /** Kinds an account can turn off, as shown in its settings */
    public const CHOICES = [
        'requests' => ['Requests and jobs', 'New requests, replies, applications and hiring'],
        'job_alerts' => ['New jobs near you', 'Jobs posted near you in your line of work'],
        'messages' => ['Messages', 'New messages in your chats'],
        'community_comments' => ['Comments and replies', 'On your posts and comments in the community'],
        'community_posts' => ['Posts from people you follow', 'When someone you follow shares something (at most every few hours each)'],
        'community_likes' => ['Likes', 'When people like your posts'],
        'community_follows' => ['New followers', 'When someone follows you in the community'],
        'referrals' => ['Invites and badges', 'When someone you invited joins, and when you earn a badge'],
    ];

    /** Decisions, documents, reports, warnings and removals: always sent */
    public const ACCOUNT = 'account';

    // Older notifications are cleared as new ones arrive
    private const KEEP_DAYS = 90;

    /** @var PushNotifications */
    private $push;

    public function __construct(PushNotifications $push)
    {
        $this->push = $push;
    }

    /**
     * @param  iterable<int>  $userIds
     * @param  array{title: string, body: string, url: string, tag: ?string, important?: bool}  $message
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

        foreach ($wanted as $id) {
            $this->record($id, $kind, $message);
        }
        $this->push->toUsers($wanted, $message);
    }

    /** @return array<string, array{label: string, hint: string, on: bool}> */
    public function choices(User $user): array
    {
        $prefs = $user->notification_preferences ?: [];
        $out = [];
        foreach (self::CHOICES as $kind => [$label, $hint]) {
            // Only providers are told about new jobs
            if ($kind === 'job_alerts' && ! $user->isProvider()) {
                continue;
            }
            $out[$kind] = ['label' => $label, 'hint' => $hint, 'on' => ($prefs[$kind] ?? true) !== false];
        }

        return $out;
    }

    /** @param array<string, bool> $changes */
    public function choose(User $user, array $changes): void
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
        $user->forceFill(['notification_preferences' => $prefs ?: null])->save();
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::where('user_id', $user->getKey())->whereNull('read_at')->count();
    }

    /** Marks one notification, or all of them, as read */
    public function markRead(User $user, ?int $id = null): void
    {
        UserNotification::where('user_id', $user->getKey())->whereNull('read_at')
            ->when($id, function ($q) use ($id) {
                $q->whereKey($id);
            })
            ->update(['read_at' => now()]);
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
        ];
        $tag = $message['tag'] ?? null;

        DB::transaction(function () use ($userId, $fields, $tag) {
            $same = $tag ? UserNotification::where('user_id', $userId)->where('tag', $tag)->whereNull('read_at')->lockForUpdate()->first() : null;
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
