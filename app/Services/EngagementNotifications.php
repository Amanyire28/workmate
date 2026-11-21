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
        $type = $post->media()->where('type', 'video')->exists() ? 'video' : 'post';
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
        $row = UserNotification::where('user_id', $recipient)->where('kind', self::LIKES)->whereIn('subject_type', ['post', 'video'])
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
        $types = $type === 'account' ? ['account'] : ['post', 'video'];
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
     * Name and picture as the community shows them (a provider's business name,
     * or "Aisha N."), for these accounts.
     *
     * @param  int[]  $ids
     * @return array<int, array{id: int, name: string, avatar_url: ?string}>
     */
    public function users(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        User::whereIn('id', array_values(array_unique($ids)))->with(['providerProfile.owner', 'providerProfile.professions'])->get()->each(function (User $u) use (&$out) {
            $a = CommunityPostResource::author($u, $u->providerProfile);
            $out[$u->id] = ['id' => $u->id, 'name' => $a['name'], 'avatar_url' => $a['avatar_url']];
        });

        return $out;
    }

    /* ---------------- Words ---------------- */

    private function nameOf(User $user): string
    {
        return $this->users([$user->getKey()])[$user->getKey()]['name'];
    }

    /**
     * "Brian, Sarah and 28 others liked your video." Two names are shown;
     * everyone else is counted.
     *
     * @param  string[]  $names  the newest people first (at least the first two, if there are that many)
     * @return array{0: string, 1: string} title and body
     */
    public function compose(string $kind, string $type, array $names, int $count): array
    {
        $count = max($count, 1);
        $who = count($names) >= 2 && $count >= 2
            ? ($count === 2 ? $names[0].' and '.$names[1] : $names[0].', '.$names[1].' and '.($count - 2).' '.($count - 2 === 1 ? 'other' : 'others'))
            : ($count === 1 ? ($names[0] ?? 'Someone') : ($names[0] ?? 'Someone').' and '.($count - 1).' '.($count - 1 === 1 ? 'other' : 'others'));

        if ($kind === self::LIKES) {
            $what = $type === 'video' ? 'video' : 'post';

            return ['Your '.$what.' was liked', $who.' liked your '.$what.'.'];
        }

        return [$count === 1 ? 'New follower' : 'New followers', $who.' started following you.'];
    }

    /** The wording from the people read from the tables (for the scheduled push) */
    public function messageNow(UserNotification $row): ?array
    {
        $people = $this->peopleFor(collect([$row]))[$row->id] ?? null;
        if (! $people || $people['count'] < 1) {
            return null;
        }
        $names = array_map(function ($a) {
            return $a['name'];
        }, array_slice($people['actors'], 0, 2));
        [$title, $body] = $this->compose($row->kind, $row->subject_type, $names, $people['count']);
        // A single new follower opens their page; several open the newest's
        $url = $row->kind === self::FOLLOWS && $people['actors'] ? '/community/people/'.$people['actors'][0]['id'] : $row->url;

        return ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $row->tag];
    }
}
