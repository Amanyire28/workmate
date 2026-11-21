<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The WorkMate community: members share their work, tips, experiences,
 * questions and opportunities. Posts publish straight away; reports go to the
 * safety team, and moderators (content.view) remove what breaks the rules,
 * telling the author why. Authors can edit their words and delete their own
 * posts and comments; a post's author can also delete comments on it.
 */
class Community
{
    /** @var AuditLogger */
    private $audit;

    /** @var PhotoVariants */
    private $photos;

    /** @var EventNotifications */
    private $notify;

    /** @var CommunityMentions */
    private $mentions;

    /** @var EngagementNotifications */
    private $engagement;

    public function __construct(AuditLogger $audit, PhotoVariants $photos, EventNotifications $notify, CommunityMentions $mentions, EngagementNotifications $engagement)
    {
        $this->engagement = $engagement;
        $this->audit = $audit;
        $this->photos = $photos;
        $this->notify = $notify;
        $this->mentions = $mentions;
    }

    /* ---------------- Posts ---------------- */

    /**
     * @param  array{category: string, body: ?string, topic: ?string}  $data
     * @param  UploadedFile[]  $photos
     */
    public function publish(User $author, array $data, array $photos = []): CommunityPost
    {
        $this->guardCanTakePart($author);
        $body = $this->clean($data['body'] ?? null);
        if ($body === null && ! $photos) {
            throw new BusinessRuleException('post_empty', 'Write something or add a photo.', 422);
        }
        // Checked before any photo is stored
        [$body, $mentions] = $this->mentions->expand($body, $author, CommunityPost::MAX_BODY);

        $stored = [];
        try {
            foreach ($photos as $photo) {
                $stored[] = $this->photos->store($photo, 'community', 'community');
            }

            $post = DB::transaction(function () use ($author, $data, $body, $stored, $mentions) {
                return $this->createPost($author, $data, $body, $stored, ['photos' => count($stored)], $mentions);
            });
        } catch (\Throwable $e) {
            $this->photos->discardStored($stored);
            throw $e;
        }

        return $post;
    }

    /**
     * A video post, once CommunityVideos has checked the upload. Call inside
     * a transaction; $video is the media row (type video, key, size, poster paths).
     * People mentioned are told once that transaction commits.
     */
    public function publishVideo(User $author, array $data, array $video): CommunityPost
    {
        $this->guardCanTakePart($author);
        [$body, $mentions] = $this->mentions->expand($this->clean($data['body'] ?? null), $author, CommunityPost::MAX_BODY);

        return $this->createPost($author, $data, $body, [$video], ['videos' => 1], $mentions);
    }

    /** A caption's mentions, checked before its video is uploaded (nothing is stored) */
    public function checkCaption(User $author, ?string $body): void
    {
        $this->mentions->check($this->clean($body), $author, CommunityPost::MAX_BODY);
    }


    /** Members who may post at all (confirmed email, no maintenance) */
    public function assertCanPost(User $user): void
    {
        $this->guardCanTakePart($user);
    }

    private function createPost(User $author, array $data, ?string $body, array $media, array $auditExtra, array $mentions = []): CommunityPost
    {
        $profile = $author->providerProfile;
        $topic = ! empty($data['topic']) ? Category::active()->where('slug', $data['topic'])->value('id') : null;

        $post = new CommunityPost();
        $post->forceFill([
            'uuid' => (string) Str::uuid(),
            'user_id' => $author->getKey(),
            'provider_profile_id' => $profile ? $profile->id : null,
            'category' => $data['category'],
            'body' => $body,
            // Defaults to the provider's main profession and base area
            'topic_category_id' => $topic ?: ($profile ? $profile->mainCategoryId() : null),
            'area_id' => $profile ? $profile->base_area_id : null,
        ])->save();

        foreach ($media as $i => $file) {
            $row = new CommunityPostMedia();
            $row->forceFill($file + ['community_post_id' => $post->id, 'position' => $i])->save();
        }

        $post->mentionedNow = $this->mentions->record(CommunityMentions::TYPE_POST, $post->id, $post->id, $author, $mentions);
        $this->audit->record('community.post_published', $post, [], ['category' => $post->category] + $auditExtra + ($mentions ? ['mentions' => count($mentions)] : []), null, $author);
        // People mentioned hear about that instead
        $this->tellFollowers($post, $author, $post->mentionedNow);

        return $post;
    }

    /** The author changes the words, category or topic. Photos stay as they are. */
    public function edit(User $author, CommunityPost $post, array $data): CommunityPost
    {
        $this->guardAuthor($author, $post);
        if ($post->status !== CommunityPost::STATUS_PUBLISHED) {
            throw new BusinessRuleException('post_removed', 'This post was removed by WorkMate and can’t be changed.', 409);
        }
        $newText = array_key_exists('body', $data);
        $body = $newText ? $this->clean($data['body']) : $post->body;
        if ($body === null && ! $post->media()->exists()) {
            throw new BusinessRuleException('post_empty', 'Write something or add a photo.', 422);
        }
        $mentions = [];
        if ($newText) {
            [$body, $mentions] = $this->mentions->expand($body, $author, CommunityPost::MAX_BODY);
        }

        $post = DB::transaction(function () use ($author, $post, $data, $body, $newText, $mentions) {
            $before = $post->only(['category', 'body', 'topic_category_id']);
            $changes = ['body' => $body, 'edited_at' => now()];
            if (! empty($data['category'])) {
                $changes['category'] = $data['category'];
            }
            if (array_key_exists('topic', $data)) {
                $changes['topic_category_id'] = $data['topic'] ? Category::active()->where('slug', $data['topic'])->value('id') : null;
            }
            $post->forceFill($changes)->save();
            if ($newText) {
                // Only people not told about this post before hear about it
                $this->mentions->record(CommunityMentions::TYPE_POST, $post->id, $post->id, $author, $mentions);
            }
            $this->audit->record('community.post_edited', $post, $before, $post->only(['category', 'body', 'topic_category_id']), null, $author);

            return $post;
        });
        $post->unsetRelation('mentions');

        return $post;
    }

    public function delete(User $author, CommunityPost $post): void
    {
        $this->guardAuthor($author, $post);

        DB::transaction(function () use ($author, $post) {
            $post->delete();
            $this->audit->record('community.post_deleted', $post, [], [], null, $author);
        });
    }

    /* ---------------- Likes and saves ---------------- */

    /** @return int the post's like count afterwards */
    public function like(User $user, CommunityPost $post, bool $on): int
    {
        $this->guardCanTakePart($user, false);
        $this->guardVisible($post);

        return DB::transaction(function () use ($user, $post, $on) {
            // Likes on one post queue here, one at a time. (Adding a like takes a shared lock on the post through
            // its foreign key, and two of those then deadlock on the counter update below: most of a crowd
            // liking one post at once failed.) The notification group is written under this same lock.
            CommunityPost::whereKey($post->id)->lockForUpdate()->first(['id']);
            $where = ['community_post_id' => $post->id, 'user_id' => $user->getKey()];
            // The like's own moment: its notification group starts here, so the like is always inside it
            $at = now()->startOfSecond();
            // Only a real change moves the count, so repeated taps can't inflate it
            $changed = $on
                ? DB::table('community_post_likes')->insertOrIgnore($where + ['created_at' => $at])
                : DB::table('community_post_likes')->where($where)->delete();

            if ($changed) {
                CommunityPost::whereKey($post->id)->update([
                    'likes_count' => DB::raw($on ? 'likes_count + 1' : 'GREATEST(likes_count, 1) - 1'),
                ]);
            }
            $count = (int) CommunityPost::withTrashed()->whereKey($post->id)->value('likes_count');

            // The author's bell groups everyone who liked it; whether a push is sent is EngagementNotifications' rule
            if ($changed && $post->user_id !== $user->getKey()) {
                if ($on) {
                    $this->engagement->likeAdded($post, $user, $at);
                } else {
                    $this->engagement->likeRemoved($post, $user);
                }
            }

            return $count;
        }, 3);
    }

    /**
     * Likes a comment (or takes the like back). Comment likes don't notify
     * anyone: they're shown on the comment.
     *
     * @return int the comment's like count afterwards
     */
    public function likeComment(User $user, CommunityComment $comment, bool $on): int
    {
        $this->guardCanTakePart($user, false);
        if (! $comment->isVisible() || ! $comment->post) {
            throw new BusinessRuleException('comment_not_found', 'That comment isn’t there any more.', 404);
        }
        $this->guardVisible($comment->post);

        return DB::transaction(function () use ($user, $comment, $on) {
            $where = ['community_comment_id' => $comment->id, 'user_id' => $user->getKey()];
            // Only a real change moves the count, so repeated taps can't inflate it
            $changed = $on
                ? DB::table('community_comment_likes')->insertOrIgnore($where + ['created_at' => now()])
                : DB::table('community_comment_likes')->where($where)->delete();
            if ($changed) {
                CommunityComment::withTrashed()->whereKey($comment->id)->update([
                    'likes_count' => DB::raw($on ? 'likes_count + 1' : 'GREATEST(likes_count, 1) - 1'),
                ]);
            }

            return (int) CommunityComment::withTrashed()->whereKey($comment->id)->value('likes_count');
        });
    }

    public function save(User $user, CommunityPost $post, bool $on): void
    {
        $this->guardCanTakePart($user, false);
        if ($on) {
            $this->guardVisible($post);
            DB::table('community_post_saves')->insertOrIgnore(['community_post_id' => $post->id, 'user_id' => $user->getKey(), 'created_at' => now()]);
        } else {
            DB::table('community_post_saves')->where(['community_post_id' => $post->id, 'user_id' => $user->getKey()])->delete();
        }
    }

    /* ---------------- Comments ---------------- */

    public function comment(User $user, CommunityPost $post, string $body, ?int $parentId = null): CommunityComment
    {
        $this->guardCanTakePart($user);
        $this->guardVisible($post);
        $body = $this->clean($body);
        if ($body === null) {
            throw new BusinessRuleException('comment_empty', 'Write a comment first.', 422);
        }
        [$body, $mentions] = $this->mentions->expand($body, $user, CommunityComment::MAX_BODY);

        return DB::transaction(function () use ($user, $post, $body, $parentId, $mentions) {
            $parent = null;
            if ($parentId) {
                $parent = CommunityComment::where('community_post_id', $post->id)->whereKey($parentId)->first();
                if (! $parent || ! $parent->isVisible()) {
                    throw new BusinessRuleException('comment_not_found', 'That comment isn’t there any more.', 404);
                }
                // Replies stay one level deep: a reply to a reply joins the same thread
                if ($parent->parent_id) {
                    $parent = $parent->parent;
                }
            }

            $comment = new CommunityComment();
            $comment->forceFill([
                'community_post_id' => $post->id,
                'user_id' => $user->getKey(),
                'parent_id' => $parent ? $parent->id : null,
                'body' => $body,
            ])->save();
            CommunityPost::whereKey($post->id)->increment('comments_count');
            $this->notify->commented($post, $comment, $user);
            // The post's author and the person replied to already hear about this comment
            $this->mentions->record(CommunityMentions::TYPE_COMMENT, $comment->id, $post->id, $user, $mentions,
                array_values(array_filter([$post->user_id, $parent ? $parent->user_id : null])));

            return $comment;
        });
    }

    /** By the comment's author, or the author of the post it's on */
    public function deleteComment(User $user, CommunityComment $comment): void
    {
        $post = $comment->post;
        if ($comment->user_id !== $user->getKey() && (! $post || $post->user_id !== $user->getKey())) {
            throw new BusinessRuleException('not_allowed', 'You can only delete your own comments, or comments on your posts.', 403);
        }

        DB::transaction(function () use ($user, $comment) {
            $counted = $comment->status === CommunityComment::STATUS_PUBLISHED;
            $comment->delete();
            if ($counted) {
                CommunityPost::withTrashed()->whereKey($comment->community_post_id)->update(['comments_count' => DB::raw('GREATEST(comments_count, 1) - 1')]);
            }
            $this->audit->record('community.comment_deleted', $comment, [], [], null, $user);
        });
    }

    /* ---------------- Moderators ---------------- */

    public function removePost(CommunityPost $post, User $admin, string $reason): CommunityPost
    {
        return DB::transaction(function () use ($post, $admin, $reason) {
            $post = CommunityPost::withTrashed()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            if ($post->status === CommunityPost::STATUS_REMOVED) {
                throw new BusinessRuleException('already_removed', 'This post is already removed.', 409);
            }
            $post->forceFill(['status' => CommunityPost::STATUS_REMOVED, 'removed_reason' => $reason, 'removed_by' => $admin->getKey(), 'removed_at' => now()])->save();
            $this->audit->record('community.post_removed', $post, ['status' => CommunityPost::STATUS_PUBLISHED], ['status' => $post->status], $reason, $admin);
            $this->notify->communityContentRemoved($post->user_id, 'post', '/community/post/'.$post->uuid);

            return $post;
        });
    }

    public function restorePost(CommunityPost $post, User $admin, string $reason): CommunityPost
    {
        return DB::transaction(function () use ($post, $admin, $reason) {
            $post = CommunityPost::withTrashed()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            if ($post->status !== CommunityPost::STATUS_REMOVED) {
                throw new BusinessRuleException('not_removed', 'This post isn’t removed.', 409);
            }
            $post->forceFill(['status' => CommunityPost::STATUS_PUBLISHED, 'removed_reason' => null, 'removed_by' => null, 'removed_at' => null])->save();
            $this->audit->record('community.post_restored', $post, ['status' => CommunityPost::STATUS_REMOVED], ['status' => $post->status], $reason, $admin);

            return $post;
        });
    }

    public function removeComment(CommunityComment $comment, User $admin, string $reason): CommunityComment
    {
        return DB::transaction(function () use ($comment, $admin, $reason) {
            $comment = CommunityComment::withTrashed()->whereKey($comment->id)->lockForUpdate()->firstOrFail();
            if ($comment->status === CommunityComment::STATUS_REMOVED) {
                throw new BusinessRuleException('already_removed', 'This comment is already removed.', 409);
            }
            $comment->forceFill(['status' => CommunityComment::STATUS_REMOVED, 'removed_reason' => $reason, 'removed_by' => $admin->getKey(), 'removed_at' => now()])->save();
            if (! $comment->trashed()) {
                CommunityPost::withTrashed()->whereKey($comment->community_post_id)->update(['comments_count' => DB::raw('GREATEST(comments_count, 1) - 1')]);
            }
            $this->audit->record('community.comment_removed', $comment, ['status' => CommunityComment::STATUS_PUBLISHED], ['status' => $comment->status], $reason, $admin);
            $this->notify->communityContentRemoved($comment->user_id, 'comment', '/community/post/'.optional($comment->post)->uuid);

            return $comment;
        });
    }

    /* ---------------- Helpers ---------------- */

    // Followers hear about an author's posts at most this often
    private const FOLLOWER_ALERT_HOURS = 3;
    private const FOLLOWER_ALERT_MAX = 1000;

    /** Followers hear about a new post, but not about every post a busy author makes */
    private function tellFollowers(CommunityPost $post, User $author, array $except = []): void
    {
        if (! Cache::add('community:followers-told:'.$author->getKey(), 1, now()->addHours(self::FOLLOWER_ALERT_HOURS))) {
            return;
        }
        $followers = DB::table('follows')->where('followed_id', $author->getKey())
            ->latest('created_at')->limit(self::FOLLOWER_ALERT_MAX)->pluck('follower_id')
            ->reject(function ($id) use ($except) {
                return in_array((int) $id, $except, true);
            })->values();
        if ($followers->isNotEmpty()) {
            $this->notify->followedPosted($post, $author, $followers);
        }
    }

    /** Signed-in members with a confirmed email take part; maintenance pauses new posts and comments */
    private function guardCanTakePart(User $user, bool $writing = true): void
    {
        if (! $user->hasVerifiedEmail()) {
            throw new BusinessRuleException('email_not_verified', 'Confirm your email address to take part in the community.', 403);
        }
        if ($writing && app(PlatformSettings::class)->get('platform.maintenance_mode')) {
            throw new BusinessRuleException('maintenance', 'WorkMate is having maintenance, so new posts and comments are paused. Please try again later.', 503);
        }
    }

    private function guardAuthor(User $user, CommunityPost $post): void
    {
        if ($post->user_id !== $user->getKey()) {
            throw new BusinessRuleException('not_allowed', 'You can only change your own posts.', 403);
        }
    }

    private function guardVisible(CommunityPost $post): void
    {
        if (! $post->isVisible()) {
            throw new BusinessRuleException('post_not_found', 'This post isn’t available any more.', 404);
        }
    }

    /** Trimmed text without control characters, or null when nothing is left */
    private function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = preg_replace('/[^\P{C}\n\t]+/u', '', str_replace("\r\n", "\n", $text));
        $text = trim(preg_replace("/\n{3,}/", "\n\n", (string) $text));

        return $text === '' ? null : $text;
    }
}
