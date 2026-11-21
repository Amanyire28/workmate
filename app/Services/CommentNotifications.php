<?php

namespace App\Services;

use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comment notifications (Phase C).
 *
 * Comments say what was said, so they are not grouped like likes:
 *  - Each comment on a post is its own notification for the post's author (who, the comment, a link
 *    to that comment), and a reply is its own notification for the author of the comment it answers.
 *  - Within a fixed 60 second window, anchored on the FIRST comment (it never restarts), the first five
 *    comments stay individual. When the sixth arrives, the notifications that are still UNREAD are
 *    consolidated into one grouped notification ("Sarah, John and 3 others commented on your video",
 *    "6 new comments"), and further comments in the window update that group at once. A comment after
 *    the window starts a new window.
 *  - Nothing is destroyed. The consolidated notifications stay as hidden children of the group
 *    (parent_id), so the group is read from them: who commented, how many comments, which ones, and
 *    a deleted or moderated comment simply stops counting. Notifications the person had already READ
 *    are never touched or merged. Reading the group reads its children.
 *  - A group the person has read is finished: later comments in the same window are individual again.
 *  - It all runs inside the transaction that saves the comment, under the lock on the post that
 *    Community::comment() already holds, so simultaneous comments are handled one after another and
 *    can't make two groups or miscount. Each comment can have only one notification (a retried request
 *    is recognised).
 *  - Nothing is delayed: the notification and its phone push are made as the comment is saved; the window
 *    only decides what is shown as a group. The phone gets one notification per window, replaced as more
 *    arrive (silently after the first), and it never carries the words of a comment.
 */
class CommentNotifications
{
    public const KIND = 'community_comments';
    public const WINDOW_SECONDS = 60;
    /** The comment that turns the window into one grouped notification */
    public const GROUP_AT = 6;

    /** @var UserNotifications */
    private $notifications;

    /** @var EngagementNotifications */
    private $engagement;

    public function __construct(UserNotifications $notifications, EngagementNotifications $engagement)
    {
        $this->notifications = $notifications;
        $this->engagement = $engagement;
    }

    /* ---------------- Writing (inside the comment's transaction) ---------------- */

    /** Someone commented on this person's post (the post's author) */
    public function commentedOnPost(CommunityPost $post, CommunityComment $comment, User $by): void
    {
        $recipient = (int) $post->user_id;
        if ($recipient === $by->getKey() || ! $this->notifications->wants($recipient, self::KIND)) {
            return;
        }
        // A retried request: this comment already has its notification
        if (UserNotification::where('user_id', $recipient)->where('kind', self::KIND)->where('subject_type', 'comment')->where('subject_id', $comment->id)->exists()) {
            return;
        }
        $at = ($comment->created_at ?: now())->copy()->startOfSecond();

        // The window this comment belongs to: the one the latest comment notification for this post
        // started, if that was less than a minute ago; otherwise this comment opens a new one
        $anchor = UserNotification::where('user_id', $recipient)->where('kind', self::KIND)->where('context_id', $post->id)
            ->whereIn('subject_type', ['comment', 'burst'])->whereNull('parent_id')->orderByDesc('id')->lockForUpdate()->first();
        $joins = $anchor && $anchor->group_started_at && $at->lt($anchor->group_started_at->copy()->addSeconds(self::WINDOW_SECONDS));
        $start = $joins ? $anchor->group_started_at->copy() : $at;

        $burst = $joins ? $this->openBurst($recipient, $post->id, $start) : null;
        if ($burst) {
            // Already one group for this window, still unread: this comment joins it
            $this->insertRow($recipient, $post, $comment, $by, $start, 'comment', $burst->id);
            $this->refreshBurst($burst, $post, $by);
            $this->push($recipient, $post, $start, $this->burstMessage($burst, $post, $by));

            return;
        }

        if ($joins && $this->commentsInWindow($post->id, $recipient, $start) >= self::GROUP_AT && $this->unreadIndividuals($post->id, $recipient, $start) > 0) {
            // The sixth: the unread individual notifications of this window become one group
            $burst = $this->makeBurst($recipient, $post, $start, $by);
            UserNotification::where('user_id', $recipient)->where('kind', self::KIND)->where('context_id', $post->id)->where('subject_type', 'comment')
                ->where('group_started_at', $start)->whereNull('read_at')->whereNull('parent_id')->where('id', '!=', $burst->id)->update(['parent_id' => $burst->id]);
            $this->insertRow($recipient, $post, $comment, $by, $start, 'comment', $burst->id);
            $this->refreshBurst($burst, $post, $by);
            $this->push($recipient, $post, $start, $this->burstMessage($burst, $post, $by));

            return;
        }

        $this->insertRow($recipient, $post, $comment, $by, $start, 'comment', null);
        $label = $this->label($post);
        [$who, $did] = EngagementNotifications::phrase('comment', [$this->engagement->users([$by->getKey()])[$by->getKey()]['name'] ?? User::shortNameOf($by->name)], 1, ['label' => $label]);
        $this->push($recipient, $post, $start, [
            'title' => $post->category === 'question' ? 'New answer' : 'New comment', 'body' => $who.$did,
            'url' => '/community/post/'.$post->uuid.'#comment-'.$comment->id, 'icon' => $by->avatarPath(128),
        ]);
    }

    /** Someone replied to this person's comment (never grouped: each reply is its own notification) */
    public function repliedTo(CommunityPost $post, CommunityComment $comment, User $by, int $recipient): void
    {
        if ($recipient === $by->getKey() || ! $this->notifications->wants($recipient, self::KIND)) {
            return;
        }
        if (UserNotification::where('user_id', $recipient)->where('kind', self::KIND)->where('subject_type', 'reply')->where('subject_id', $comment->id)->exists()) {
            return;
        }
        $this->insertRow($recipient, $post, $comment, $by, null, 'reply', null);
        $name = $this->engagement->users([$by->getKey()])[$by->getKey()]['name'] ?? User::shortNameOf($by->name);
        [$who, $did] = EngagementNotifications::phrase('reply', [$name], 1);
        $this->notifications->pushOnly(self::KIND, [$recipient], [
            'title' => 'New reply', 'body' => $who.$did, 'url' => '/community/post/'.$post->uuid.'#comment-'.$comment->id,
            // The thread's replies replace each other on the phone
            'tag' => 'community-reply-'.($comment->parent_id ?: $comment->id), 'icon' => $by->avatarPath(128),
        ]);
    }

    /* ---------------- Rows ---------------- */

    /** One notification for one comment (a reply, a comment, or a hidden member of a group) */
    private function insertRow(int $recipient, CommunityPost $post, CommunityComment $comment, User $by, ?Carbon $start, string $type, ?int $parent): void
    {
        $now = now();
        $name = $this->engagement->users([$by->getKey()])[$by->getKey()]['name'] ?? User::shortNameOf($by->name);
        [$who, $did] = EngagementNotifications::phrase($type === 'reply' ? 'reply' : 'comment', [$name], 1, ['label' => $this->label($post)]);
        DB::table('user_notifications')->insert([
            'user_id' => $recipient, 'kind' => self::KIND,
            'title' => $type === 'reply' ? 'New reply' : ($post->category === 'question' ? 'New answer' : 'New comment'),
            'body' => mb_substr($who.$did, 0, 300),   // the list makes the sentence again from what is true when it is shown
            'url' => '/community/post/'.$post->uuid.'#comment-'.$comment->id,
            'tag' => 'community-'.$type.'-'.$comment->id,                        // its own, so it is never merged into another
            'subject_type' => $type, 'subject_id' => $comment->id, 'context_id' => $post->id, 'actor_id' => $by->getKey(),
            'parent_id' => $parent, 'group_started_at' => $start, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** This window's group, if it exists and is still unread */
    private function openBurst(int $recipient, int $postId, Carbon $start): ?UserNotification
    {
        return UserNotification::where('user_id', $recipient)->where('kind', self::KIND)->where('context_id', $postId)->where('subject_type', 'burst')
            ->where('group_started_at', $start)->whereNull('read_at')->orderByDesc('id')->lockForUpdate()->first();
    }

    private function makeBurst(int $recipient, CommunityPost $post, Carbon $start, User $latest): UserNotification
    {
        $seq = UserNotification::where('user_id', $recipient)->where('kind', self::KIND)->where('context_id', $post->id)->where('subject_type', 'burst')
            ->where('group_started_at', $start)->count() + 1;
        $now = now();
        $key = 'comments:'.$post->id.':'.$start->timestamp.':'.$seq;
        DB::table('user_notifications')->insertOrIgnore([
            'user_id' => $recipient, 'kind' => self::KIND, 'title' => 'New comments', 'body' => 'New comments.',
            'url' => '/community/post/'.$post->uuid.'#comments', 'tag' => 'comments-'.$post->id.'-'.$start->timestamp.($seq > 1 ? '-'.$seq : ''),
            'group_key' => $key, 'subject_type' => 'burst', 'subject_id' => $post->id, 'context_id' => $post->id, 'actor_id' => $latest->getKey(),
            'group_started_at' => $start, 'group_count' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return UserNotification::where('user_id', $recipient)->where('group_key', $key)->lockForUpdate()->firstOrFail();
    }

    /** The group's wording and counts as they are now, and moved to the top */
    private function refreshBurst(UserNotification $burst, CommunityPost $post, User $latest): void
    {
        $state = $this->stateOf([$burst->id])[$burst->id] ?? ['total' => 0, 'distinct' => 0, 'actors' => []];
        $label = $this->label($post);
        [$who, $did] = EngagementNotifications::phrase('burst', array_column($state['actors'], 'name'), max($state['distinct'], 1), ['label' => $label, 'total' => $state['total']]);
        $burst->forceFill([
            'title' => 'New comments', 'body' => $who.$did, 'actor_id' => $state['actors'][0]['id'] ?? $latest->getKey(),
            'group_count' => $state['total'], 'created_at' => now(),
        ])->save();
    }

    /** The phone's wording for a group: who and how many, never what was said */
    private function burstMessage(UserNotification $burst, CommunityPost $post, User $latest): array
    {
        $burst->refresh();

        return [
            'title' => 'New comments', 'body' => $burst->body, 'url' => '/community/post/'.$post->uuid.'#comments',
            'icon' => $latest->avatarPath(128),
        ];
    }

    /** One phone notification per window: first one alerts, the rest replace it silently (see PushDelivery) */
    private function push(int $recipient, CommunityPost $post, Carbon $start, array $message): void
    {
        $first = UserNotification::where('user_id', $recipient)->where('kind', self::KIND)->where('context_id', $post->id)
            ->where('group_started_at', $start)->min('id');
        $this->notifications->pushOnly(self::KIND, [$recipient], $message + [
            'tag' => 'comments-'.$post->id.'-'.$start->timestamp, 'group_row_id' => $first ?: null,
        ]);
    }

    /** Unread individual comment notifications of this window (what a new group would gather up) */
    private function unreadIndividuals(int $postId, int $recipient, Carbon $start): int
    {
        return UserNotification::where('user_id', $recipient)->where('kind', self::KIND)->where('context_id', $postId)->where('subject_type', 'comment')
            ->where('group_started_at', $start)->whereNull('read_at')->whereNull('parent_id')->count();
    }

    /** Comments by other people on the post within the window (published, from active accounts) */
    private function commentsInWindow(int $postId, int $recipient, Carbon $start): int
    {
        return (int) DB::table('community_comments as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.community_post_id', $postId)->where('c.user_id', '!=', $recipient)->where('c.status', CommunityComment::STATUS_PUBLISHED)->whereNull('c.deleted_at')
            ->where('u.status', User::STATUS_ACTIVE)->whereNull('u.deleted_at')
            ->where('c.created_at', '>=', $start)->where('c.created_at', '<', $start->copy()->addSeconds(self::WINDOW_SECONDS))->count();
    }

    private function label(CommunityPost $post): string
    {
        return $this->engagement->contentLabels([$post->id])[$post->id] ?? 'post';
    }

    /* ---------------- Reading groups (for the list, the phone and the "who" sheet) ---------------- */

    /**
     * For grouped comment notifications: how many comments, how many different people, and the three
     * newest people, read from the comments that are still there (a deleted or moderated comment, or a
     * suspended commenter, stops counting). A fixed number of queries however many groups there are.
     *
     * @param  int[]  $burstIds
     * @return array<int, array{total: int, distinct: int, actors: array<int, array>}>
     */
    public function stateOf(array $burstIds): array
    {
        if (! $burstIds) {
            return [];
        }
        $rows = DB::table('user_notifications as ch')->join('community_comments as c', 'c.id', '=', 'ch.subject_id')->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereIn('ch.parent_id', $burstIds)->where('c.status', CommunityComment::STATUS_PUBLISHED)->whereNull('c.deleted_at')
            ->where('u.status', User::STATUS_ACTIVE)->whereNull('u.deleted_at')
            ->selectRaw('ch.parent_id as nid, c.user_id as uid, count(*) as n, max(c.created_at) as last_at, max(c.id) as last_id')
            ->groupBy('ch.parent_id', 'c.user_id')->get();
        $people = $this->engagement->users($rows->pluck('uid')->all());

        $out = [];
        foreach ($rows->groupBy('nid') as $nid => $group) {
            $sorted = $group->sort(function ($a, $b) {
                return [$b->last_at, $b->last_id] <=> [$a->last_at, $a->last_id];     // newest first
            })->values();
            $out[(int) $nid] = [
                'total' => (int) $sorted->sum('n'), 'distinct' => $sorted->count(),
                'actors' => $sorted->take(3)->map(function ($r) use ($people) {
                    return $people[$r->uid] ?? null;
                })->filter()->values()->all(),
            ];
        }
        foreach ($burstIds as $id) {
            $out[$id] = $out[$id] ?? ['total' => 0, 'distinct' => 0, 'actors' => []];
        }

        return $out;
    }

    /**
     * The comments of a group (or one notification), newest first, for the "who" sheet: who, a short
     * preview of what they said, and where it is. Only comments that can be seen.
     *
     * @return array<int, array>
     */
    public function commentsOf(UserNotification $burst, int $limit = 50): array
    {
        $rows = DB::table('user_notifications as ch')->join('community_comments as c', 'c.id', '=', 'ch.subject_id')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('ch.parent_id', $burst->id)->where('c.status', CommunityComment::STATUS_PUBLISHED)->whereNull('c.deleted_at')
            ->where('u.status', User::STATUS_ACTIVE)->whereNull('u.deleted_at')
            ->orderByDesc('c.created_at')->orderByDesc('c.id')->limit($limit)->get(['c.id', 'c.user_id', 'c.body', 'c.created_at']);
        $people = $this->engagement->users($rows->pluck('user_id')->all());
        $uuid = $rows->isEmpty() ? '' : $this->postUuid((int) $burst->context_id);   // once, not once per comment

        return $rows->map(function ($r) use ($people, $uuid) {
            return [
                'id' => (int) $r->id, 'actor' => $people[$r->user_id] ?? null, 'preview' => self::preview($r->body),
                'url' => '/community/post/'.$uuid.'#comment-'.$r->id, 'at' => Carbon::parse($r->created_at)->toIso8601String(),
            ];
        })->filter(function ($c) {
            return $c['actor'] !== null;
        })->values()->all();
    }

    private function postUuid(int $postId): string
    {
        return (string) CommunityPost::withTrashed()->whereKey($postId)->value('uuid');
    }

    /** A short plain-text preview: whitespace collapsed, no markup kept, cut at a word where it can be */
    public static function preview(?string $body, int $max = 140): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $body)));
        if ($text === '') {
            return null;
        }

        return Str::limit($text, $max, '…');
    }

    /** Previews for these comments, only for ones that can be seen, in one query */
    public function previewsFor(Collection $commentIds): array
    {
        $ids = $commentIds->filter()->unique()->values()->all();
        if (! $ids) {
            return [];
        }

        return CommunityComment::whereIn('id', $ids)->where('status', CommunityComment::STATUS_PUBLISHED)->get(['id', 'body'])
            ->mapWithKeys(function ($c) {
                return [$c->id => self::preview($c->body)];
            })->all();
    }
}
