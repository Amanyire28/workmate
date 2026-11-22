<?php

namespace App\Services;

use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A member's own community numbers, for their Account and My Space pages. Everything is counted from
 * real records, for ONE member, in a fixed handful of queries (nothing runs per post):
 *  - posts, followers and following use the same definition as the public community profile
 *    (published, not deleted; followers and following of accounts that still exist);
 *  - videos are the posts among those that contain a video (a subset of posts, not extra content);
 *  - likes and comments "received" are other people's: the owner liking or replying under their own
 *    post is activity, not audience, so it is left out;
 *  - nothing here is a view count or a play count: those aren't tracked.
 */
class CommunityStats
{
    /** The few numbers the Account page shows */
    public function summary(User $user): array
    {
        $own = $this->ownCounts($user);

        return [
            'posts' => $own['posts'],
            'videos' => $own['videos'],
            'likes_received' => $this->likesReceived($user),
            'followers' => $own['followers'],
        ];
    }

    /** Everything My Space shows: audience (other people) apart from the member's own activity */
    public function detail(User $user): array
    {
        $own = $this->ownCounts($user);
        $id = $user->getKey();

        return [
            'audience' => [
                'posts' => $own['posts'],
                'videos' => $own['videos'],
                'likes_received' => $this->likesReceived($user),
                'comments_received' => $this->commentsReceived($user),
                'saves_received' => $this->savesReceived($user),
                'followers' => $own['followers'],
            ],
            'activity' => [
                'following' => $own['following'],
                'likes_given' => (int) DB::table('community_post_likes')->where('user_id', $id)->count(),
                'comments_written' => (int) CommunityComment::where('user_id', $id)->where('status', CommunityComment::STATUS_PUBLISHED)->count(),
                'posts_saved' => (int) DB::table('community_post_saves')->where('user_id', $id)->count(),
                'providers_saved' => (int) DB::table('saved_providers')->where('user_id', $id)->count(),
            ],
        ];
    }

    /** Posts (all published, videos included), the ones with a video, followers and following: one query */
    private function ownCounts(User $user): array
    {
        $row = User::query()->whereKey($user->getKey())
            ->withCount([
                'followers', 'following',
                'communityPosts as posts_count' => function ($p) {
                    $p->where('status', CommunityPost::STATUS_PUBLISHED);
                },
                'communityPosts as videos_count' => function ($p) {
                    $p->where('status', CommunityPost::STATUS_PUBLISHED)->whereExists(function ($m) {
                        $m->select(DB::raw(1))->from('community_post_media as m')
                            ->whereColumn('m.community_post_id', 'community_posts.id')->where('m.type', CommunityPostMedia::TYPE_VIDEO);
                    });
                },
            ])->first();

        return [
            'posts' => (int) $row->posts_count, 'videos' => (int) $row->videos_count,
            'followers' => (int) $row->followers_count, 'following' => (int) $row->following_count,
        ];
    }

    /** Other people's likes on this member's published posts */
    private function likesReceived(User $user): int
    {
        return (int) DB::table('community_post_likes as l')
            ->join('community_posts as p', 'p.id', '=', 'l.community_post_id')
            ->where('p.user_id', $user->getKey())->where('p.status', CommunityPost::STATUS_PUBLISHED)->whereNull('p.deleted_at')
            ->where('l.user_id', '!=', $user->getKey())
            ->count();
    }

    /** Other people's comments and replies on this member's published posts (still visible ones) */
    private function commentsReceived(User $user): int
    {
        return (int) DB::table('community_comments as c')
            ->join('community_posts as p', 'p.id', '=', 'c.community_post_id')
            ->where('p.user_id', $user->getKey())->where('p.status', CommunityPost::STATUS_PUBLISHED)->whereNull('p.deleted_at')
            ->where('c.status', CommunityComment::STATUS_PUBLISHED)->whereNull('c.deleted_at')
            ->where('c.user_id', '!=', $user->getKey())
            ->count();
    }

    /** Other people who saved one of this member's published posts */
    private function savesReceived(User $user): int
    {
        return (int) DB::table('community_post_saves as s')
            ->join('community_posts as p', 'p.id', '=', 's.community_post_id')
            ->where('p.user_id', $user->getKey())->where('p.status', CommunityPost::STATUS_PUBLISHED)->whereNull('p.deleted_at')
            ->where('s.user_id', '!=', $user->getKey())
            ->count();
    }
}
