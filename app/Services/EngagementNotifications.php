<?php

namespace App\Services;

use App\Http\Resources\CommunityPostResource;
use App\Models\CommunityPost;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Likes and follows, grouped (Step 5).
 *
 * In the bell, all the likes on one post or video (or all new followers)
 * since the person last looked are ONE notification: "Brian, Sarah and 28
 * others liked your video". It is one row per recipient and group window
 * (group_key), created with an insert that ignores duplicates, so concurrent
 * requests can't make two. A group is a window: once the person has read it,
 * the next like starts a new one.
 *
 * Who is in a group is never copied: the people and the count are read from
 * community_post_likes / follows (since the group started, active accounts
 * only, never the recipient). So:
 *  - the count is the number of DIFFERENT people who currently like it. Taking
 *    a like back lowers it; liking again raises it again, without a new push;
 *  - a group with no one left is hidden and not counted as unread;
 *  - names, pictures and counts are always real, and a suspended account stops
 *    appearing the next time the list is read.
 *
 * Whether a push is sent is PushDelivery's job (or, while smart push is off,
 * the old rule: a like push at most once an hour per post, a follow push at
 * most once a week per follower).
 */
class EngagementNotifications
{
    public const LIKES = 'community_likes';

    /** What a like group can be about (stored as the group's subject_type, read again from the post when shown) */
    public const LIKE_TYPES = ['post', 'video', 'photo', 'question'];
    public const FOLLOWS = 'community_follows';

    /** @internal tests only: behave as if the database had no window functions */
    public static $withoutWindowFunctions = false;

    /** @var bool the slow path was already logged in this process */
    private static $warned = false;

    /** @var UserNotifications */
    private $notifications;

    /** @var PushDelivery */
    private $delivery;

    public function __construct(UserNotifications $notifications, PushDelivery $delivery)
    {
        $this->notifications = $notifications;
        $this->delivery = $delivery;
    }

    /* ---------------- Writing (inside the like / follow transaction) ---------------- */

    /**
     * Someone liked a post. Call after a like was really added, with the moment
     * it was stamped ($at): a new group starts at that moment, so the like is
     * always inside it (taking "now" here instead could land a second later).
     * Concurrent likes on one post are queued by the lock Community::like takes on
     * the post row first, so two can't start two groups.
     */
    public function likeAdded(CommunityPost $post, User $by, Carbon $at): void
    {
        $recipient = (int) $post->user_id;
        if ($recipient === $by->getKey() || ! $this->notifications->wants($recipient, self::LIKES)) {
            return;
        }
        $type = $this->contentLabels([$post->id])[$post->id] ?? 'post';       // video, photo, question or post
        $row = $this->touch(self::LIKES, $type, $post->id, $recipient, $by, '/community/post/'.$post->uuid, 'community-post-'.$post->uuid.'-likes', $at);
        if ($row->read_at !== null) {
            return;   // (only if a group read in this very second was found again: nothing new to announce)
        }
        $count = $this->count($row);

        if ($this->delivery->smartEnabled()) {
            // Immediately: the push goes out as the like is committed; later likes update the same group
            // and the same phone notification (silently), they don't wait for more people
            $this->delivery->enqueueGroup($row, \App\Models\NotificationPush::TYPE_LIKES);

            return;
        }
        // Smart push off: the old rule, a push at most once an hour per post
        $claimed = CommunityPost::whereKey($post->id)->where(function ($q) {
            $q->whereNull('likes_notified_at')->orWhere('likes_notified_at', '<', now()->subHour());
        })->update(['likes_notified_at' => now()]);
        if ($claimed) {
            $message = $this->messageNow($row);
            if ($message) {
                $this->notifications->pushOnly(self::LIKES, [$recipient], $message);
            }
        }
    }

    /** Someone took their like back */
    public function likeRemoved(CommunityPost $post, User $by): void
    {
        $recipient = (int) $post->user_id;
        $row = UserNotification::where('user_id', $recipient)->where('kind', self::LIKES)->whereIn('subject_type', self::LIKE_TYPES)
            ->where('subject_id', $post->id)->orderByDesc('id')->lockForUpdate()->first();
        if ($row) {
            $row->forceFill(['group_count' => $this->count($row)])->save();
        }
    }

    /** Someone followed this account. Call after a follow was really added, with the moment it was stamped. */
    public function followAdded(User $follower, int $followedId, Carbon $at): void
    {
        if ($followedId === $follower->getKey() || ! $this->notifications->wants($followedId, self::FOLLOWS)) {
            return;
        }
        // (CommunityPeople::follow holds the followed account's row lock around this, so followers queue)
        $row = $this->touch(self::FOLLOWS, 'account', $followedId, $followedId, $follower, '/community/people/'.$follower->getKey(), 'community-followers', $at);
        if ($row->read_at !== null) {
            return;
        }
        $count = $this->count($row);

        if ($this->delivery->smartEnabled()) {
            $this->delivery->enqueueGroup($row, \App\Models\NotificationPush::TYPE_FOLLOWS);

            return;
        }
        // Smart push off: following, unfollowing and following again doesn't keep pushing
        if (Cache::add('community:followed:'.$follower->getKey().':'.$followedId, 1, now()->addDays(7))) {
            $message = $this->messageNow($row);
            if ($message) {
                $this->notifications->pushOnly(self::FOLLOWS, [$followedId], $message);
            }
        }
    }

    public function followRemoved(User $follower, int $followedId): void
    {
        $row = UserNotification::where('user_id', $followedId)->where('kind', self::FOLLOWS)->where('subject_type', 'account')
            ->where('subject_id', $followedId)->orderByDesc('id')->lockForUpdate()->first();
        if ($row) {
            $row->forceFill(['group_count' => $this->count($row)])->save();
        }
    }

    /**
     * The recipient's current group for this content (a new one if they have
     * read it), moved to the top with the newest person as its picture.
     */
    private function touch(string $kind, string $type, int $subjectId, int $recipient, User $latest, string $url, string $tag, Carbon $at): UserNotification
    {
        $types = $type === 'account' ? ['account'] : self::LIKE_TYPES;
        $find = function () use ($kind, $types, $subjectId, $recipient) {
            return UserNotification::where('user_id', $recipient)->where('kind', $kind)->whereIn('subject_type', $types)
                ->where('subject_id', $subjectId)->orderByDesc('id')->lockForUpdate()->first();
        };

        $row = $find();
        if (! $row || $row->read_at !== null) {
            $started = $at->copy()->startOfSecond();
            DB::table('user_notifications')->insertOrIgnore([
                'user_id' => $recipient, 'kind' => $kind, 'title' => '-', 'body' => '-', 'url' => $url, 'tag' => $tag,
                'group_key' => mb_substr($kind.':'.$type.':'.$subjectId.':'.$started->timestamp, 0, 120),
                'subject_type' => $type, 'subject_id' => $subjectId, 'actor_id' => $latest->getKey(),
                'group_started_at' => $started, 'group_count' => 0, 'created_at' => $started, 'updated_at' => $started,
            ]);
            $row = $find();
        }

        $count = $this->count($row);
        [$title, $body] = $this->compose($kind, $type, [$this->nameOf($latest)], $count);
        $row->forceFill([
            'title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag,
            'actor_id' => $latest->getKey(), 'group_count' => $count, 'created_at' => now(),
        ])->save();

        return $row;
    }

    /* ---------------- Counting and reading people ---------------- */

    /**
     * The people in one group: different, active accounts, never the recipient,
     * since the group started (and, once it was read, only up to then, so later
     * likes belong to the next group). Returns the query and the person's column.
     *
     * @return array{0: \Illuminate\Database\Query\Builder, 1: string}
     */
    private function members(UserNotification $row): array
    {
        if ($row->kind === self::LIKES) {
            $q = DB::table('community_post_likes as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.community_post_id', $row->subject_id)->where('m.user_id', '!=', $row->user_id);
            $who = 'm.user_id';
        } else {
            $q = DB::table('follows as m')->join('users as u', 'u.id', '=', 'm.follower_id')
                ->where('m.followed_id', $row->subject_id)->where('m.follower_id', '!=', $row->user_id);
            $who = 'm.follower_id';
        }
        $q->where('m.created_at', '>=', $row->group_started_at)
            ->when($row->read_at, function ($q) use ($row) {
                $q->where('m.created_at', '<=', $row->read_at);
            })
            ->where('u.status', User::STATUS_ACTIVE)->whereNull('u.deleted_at');

        return [$q, $who];
    }

    /** How many different, active people are in this group right now */
    public function count(UserNotification $row): int
    {
        return (int) $this->members($row)[0]->count();
    }

    /**
     * For the grouped notifications among $rows: the live count and the three
     * newest people (name and picture), in a constant number of queries.
     *
     * @param  Collection<int, UserNotification>  $rows
     * @return array<int, array{count: int, actors: array<int, array{id: int, name: string, avatar_url: ?string}>}>
     */
    public function peopleFor(Collection $rows): array
    {
        $out = [];
        $likes = $rows->where('kind', self::LIKES)->whereNotNull('group_key')->pluck('id')->all();
        $follows = $rows->where('kind', self::FOLLOWS)->whereNotNull('group_key')->pluck('id')->all();

        $found = collect();
        if (self::$withoutWindowFunctions) {
            $found = $this->perGroup($rows->whereNotNull('group_key')->whereIn('kind', [self::LIKES, self::FOLLOWS]));
            $likes = $follows = [];
        }
        try {
        if ($likes) {
            $found = $found->concat($this->window(
                DB::table('community_post_likes as l')->join('user_notifications as n', 'n.subject_id', '=', 'l.community_post_id')
                    ->join('users as u', 'u.id', '=', 'l.user_id')->whereIn('n.id', $likes)->whereColumn('l.user_id', '!=', 'n.user_id')
                    ->whereColumn('l.created_at', '>=', 'n.group_started_at')->where('u.status', User::STATUS_ACTIVE)->whereNull('u.deleted_at')
                    ->where(function ($q) {
                        $q->whereNull('n.read_at')->orWhereColumn('l.created_at', '<=', 'n.read_at');
                    }),
                'l.user_id', 'l.created_at', 'l.id'
            ));
        }
        if ($follows) {
            $found = $found->concat($this->window(
                DB::table('follows as f')->join('user_notifications as n', 'n.subject_id', '=', 'f.followed_id')
                    ->join('users as u', 'u.id', '=', 'f.follower_id')->whereIn('n.id', $follows)->whereColumn('f.follower_id', '!=', 'n.user_id')
                    ->whereColumn('f.created_at', '>=', 'n.group_started_at')->where('u.status', User::STATUS_ACTIVE)->whereNull('u.deleted_at')
                    ->where(function ($q) {
                        $q->whereNull('n.read_at')->orWhereColumn('f.created_at', '<=', 'n.read_at');
                    }),
                'f.follower_id', 'f.created_at', 'f.id'
            ));
        }
        } catch (QueryException $e) {
            // An older database without window functions (MariaDB before 10.2, MySQL before 8): a few more queries instead
            $this->warnOnce($e);
            $found = $this->perGroup($rows->whereNotNull('group_key')->whereIn('kind', [self::LIKES, self::FOLLOWS]));
            $likes = $follows = [];
        }

        $people = $this->users($found->pluck('uid')->all());
        foreach ($found as $f) {
            $out[$f->nid]['count'] = (int) $f->total;
            if (isset($people[$f->uid])) {
                $out[$f->nid]['actors'][] = $people[$f->uid];
            }
        }
        foreach ($rows->whereNotNull('group_key')->whereIn('kind', [self::LIKES, self::FOLLOWS])->pluck('id') as $id) {
            $out[$id] = ($out[$id] ?? []) + ['count' => 0, 'actors' => []];
            $out[$id] += ['actors' => []];
        }

        return $out;
    }

    /**
     * The same answer without window functions: two small queries per group (a page
     * has at most 20 notifications). Used only if the database can't do the one query.
     *
     * @param  Collection<int, UserNotification>  $rows
     */
    private function perGroup(Collection $rows): Collection
    {
        $found = collect();
        foreach ($rows as $row) {
            [$query, $who] = $this->members($row);
            $total = (clone $query)->count();
            foreach ($query->orderByDesc('m.created_at')->orderByDesc('m.id')->limit(3)->pluck($who) as $uid) {
                $found->push((object) ['nid' => $row->id, 'uid' => (int) $uid, 'total' => $total]);
            }
        }

        return $found;
    }

    private function warnOnce(\Throwable $e): void
    {
        if (! self::$warned) {
            self::$warned = true;
            Log::warning('Window functions unavailable, grouped notifications use the slower path: '.$e->getMessage());
        }
    }

    /** The newest three per notification, and how many there are in all */
    private function window($query, string $userColumn, string $timeColumn, string $idColumn): Collection
    {
        $inner = $query->selectRaw(
            // A running COUNT as the position (not ROW_NUMBER(), which some MariaDB 10.4 builds reject); the order is unique, so it counts 1, 2, 3...
            "n.id as nid, {$userColumn} as uid, COUNT(*) OVER (PARTITION BY n.id ORDER BY {$timeColumn} DESC, {$idColumn} DESC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as rn, COUNT(*) OVER (PARTITION BY n.id) as total"
        );

        return DB::table(DB::raw('('.$inner->toSql().') as t'))->mergeBindings($inner)->where('rn', '<=', 3)->orderBy('nid')->orderBy('rn')->get();
    }

    /**
     * Name and picture as the community shows them (a provider's business name, or "Aisha N."),
     * for these ACTIVE accounts (a suspended or deleted one is simply not returned, so it never
     * appears). The picture is the small copy (96 px); `icon` is the address a phone notification
     * may use (128 px, on this site).
     *
     * @param  int[]  $ids
     * @return array<int, array{id: int, name: string, avatar_url: ?string, icon: ?string, provider_id: ?int}>
     */
    public function users(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        User::whereIn('id', array_values(array_unique($ids)))->where('status', User::STATUS_ACTIVE)
            ->with(['providerProfile.owner', 'providerProfile.professions'])->get()->each(function (User $u) use (&$out) {
                $a = CommunityPostResource::author($u, $u->providerProfile);
                $out[$u->id] = ['id' => $u->id, 'name' => $a['name'], 'avatar_url' => $u->avatarUrl(96), 'icon' => $u->avatarPath(128), 'provider_id' => $a['provider_id']];
            });

        return $out;
    }

    /**
     * What each post is, as a person would say it: video, photo, question or post. Read from the post as it
     * is now (the media does not change after posting), in two queries however many posts there are.
     *
     * @param  int[]  $postIds
     * @return array<int, string>
     */
    public function contentLabels(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter($postIds)));
        if (! $postIds) {
            return [];
        }
        $media = DB::table('community_post_media')->whereIn('community_post_id', $postIds)->select('community_post_id', 'type')->distinct()->get()
            ->groupBy('community_post_id')->map(function ($rows) {
                return $rows->pluck('type')->all();
            });
        $out = [];
        CommunityPost::withTrashed()->whereIn('id', $postIds)->get(['id', 'category'])->each(function ($post) use (&$out, $media) {
            $types = $media[$post->id] ?? [];
            $out[$post->id] = in_array('video', $types, true) ? 'video' : (in_array('photo', $types, true) ? 'photo' : ($post->category === 'question' ? 'question' : 'post'));
        });

        return $out;
    }

    /* ---------------- Words ---------------- */

    private function nameOf(User $user): string
    {
        return $this->users([$user->getKey()])[$user->getKey()]['name'] ?? User::shortNameOf($user->name);
    }

    /**
     * The people, as a sentence: "A", "A and B", "A, B and C", then "A, B and 2 others" (two names, the
     * rest counted, never a hard-coded number). Up to three names are needed; fewer are fine.
     *
     * @param  string[]  $names  the newest people first
     */
    public static function who(array $names, int $count): string
    {
        $count = max($count, 1);
        $names = array_values(array_filter($names, 'strlen'));
        $first = $names[0] ?? 'Someone';
        $others = function (int $n) {
            return $n.' '.($n === 1 ? 'other' : 'others');
        };
        if ($count === 1) {
            return $first;
        }
        if ($count === 2) {
            return isset($names[1]) ? $first.' and '.$names[1] : $first.' and 1 other';
        }
        if ($count === 3 && isset($names[2])) {
            return $first.', '.$names[1].' and '.$names[2];
        }

        return isset($names[1]) ? $first.', '.$names[1].' and '.$others($count - 2) : $first.' and '.$others($count - 1);
    }

    /**
     * The sentence for one notification, in two parts: who, and what they did. ("Brian P." + " liked your video.")
     *
     * @param  string  $what  like | follow | comment | reply | burst
     * @param  array{label?: string, total?: int}  $extra
     * @return array{0: string, 1: string} who, what they did
     */
    public static function phrase(string $what, array $names, int $people, array $extra = []): array
    {
        $label = $extra['label'] ?? 'post';
        $who = self::who($names, $people);
        switch ($what) {
            case 'like':
                return [$who, ' liked your '.$label.'.'];
            case 'follow':
                return [$who, ' started following you.'];
            case 'reply':
                return [$who, ' replied to your comment.'];
            case 'burst':
                $total = (int) ($extra['total'] ?? $people);
                if ($total > $people) {
                    return [$who, ' posted '.$total.' comments on your '.$label.'.'];
                }
                // fall through: as many comments as people
            case 'comment':
            default:
                return [$who, $label === 'question' ? ' answered your question.' : ' commented on your '.$label.'.'];
        }
    }

    /**
     * Title and sentence of a like or follow group, as the notification list and the phone say it.
     *
     * @param  string[]  $names  the newest people first (three are used)
     * @param  string  $type  the content: video, photo, question or post
     * @return array{0: string, 1: string} title and body
     */
    public function compose(string $kind, string $type, array $names, int $count): array
    {
        $count = max($count, 1);
        $label = in_array($type, ['video', 'photo', 'question'], true) ? $type : 'post';
        [$who, $did] = self::phrase($kind === self::LIKES ? 'like' : 'follow', $names, $count, ['label' => $label]);

        if ($kind === self::LIKES) {
            return ['Your '.$label.' was liked', $who.$did];
        }

        return [$count === 1 ? 'New follower' : 'New followers', $who.$did];
    }

    /** The wording from the people read from the tables (for the scheduled push), with the latest person's picture */
    public function messageNow(UserNotification $row): ?array
    {
        $people = $this->peopleFor(collect([$row]))[$row->id] ?? null;
        if (! $people || $people['count'] < 1) {
            return null;
        }
        $names = array_map(function ($a) {
            return $a['name'];
        }, array_slice($people['actors'], 0, 3));
        $label = $row->kind === self::LIKES ? ($this->contentLabels([$row->subject_id])[$row->subject_id] ?? 'post') : $row->subject_type;
        [$title, $body] = $this->compose($row->kind, $label, $names, $people['count']);
        // A single new follower opens their page; several open the newest's
        $url = $row->kind === self::FOLLOWS && $people['actors'] ? '/community/people/'.$people['actors'][0]['id'] : $row->url;
        $latest = $people['actors'][0]['id'] ?? null;
        $icon = $latest ? ($this->users([$latest])[$latest]['icon'] ?? null) : null;

        return array_filter(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $row->tag, 'icon' => $icon], function ($v) {
            return $v !== null;
        });
    }

    /**
     * Who is in a like or follow group, newest first, for the "who" sheet: up to $limit people.
     *
     * @return array<int, array{id: int, name: string, avatar_url: ?string, provider_id: ?int}>
     */
    public function actorsOf(UserNotification $row, int $limit = 50): array
    {
        [$query, $who] = $this->members($row);
        $ids = $query->orderByDesc('m.created_at')->orderByDesc('m.id')->limit($limit)->pluck($who)->all();
        $people = $this->users($ids);
        $out = [];
        foreach ($ids as $id) {
            if (isset($people[$id])) {
                $out[] = $people[$id];
            }
        }

        return $out;
    }
}
