<?php

namespace Tests\Feature\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Community;
use App\Services\EventNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase A: the write paths of a post's comments keep one lock order (the post first, then its
 * comments), so a crowd commenting, replying, liking and deleting at once can't deadlock, and
 * the counters stay exact. (The real simultaneous runs against MariaDB are described in the README;
 * PHPUnit's single connection checks the logic around them.)
 */
class CommunityConcurrencyTest extends ApiTestCase
{
    private function member(string $name): User
    {
        return User::factory()->create(['name' => $name]);
    }

    private function newPost(User $author): CommunityPost
    {
        $post = new CommunityPost();
        $post->forceFill(['uuid' => (string) Str::uuid(), 'user_id' => $author->id, 'category' => 'tips', 'body' => 'Some advice'])->save();

        return $post;
    }

    /** The counter people see, against the comments that really exist */
    private function assertCounterIsExact(CommunityPost $post): void
    {
        $actual = CommunityComment::where('community_post_id', $post->id)->where('status', 'published')->count();
        $this->assertSame($actual, (int) CommunityPost::find($post->id)->comments_count, 'comments_count differs from the published comments');
    }

    public function test_the_counter_stays_exact_through_comments_replies_deletes_and_removals()
    {
        $community = app(Community::class);
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $a = $this->member('Alice One');
        $b = $this->member('Brian Two');
        $admin = $this->userWithRoles(['super_admin']);

        $top = $community->comment($a, $post, 'First');
        $reply = $community->comment($b, $post, 'A reply', $top->id);
        $other = $community->comment($b, $post, 'Another');
        $this->assertSame(3, (int) CommunityPost::find($post->id)->comments_count);

        $community->deleteComment($b, $reply);                               // its own author
        $this->assertSame(2, (int) CommunityPost::find($post->id)->comments_count);
        $community->removeComment($other, $admin, 'Rules');                  // a moderator
        $this->assertSame(1, (int) CommunityPost::find($post->id)->comments_count);
        $community->deleteComment($author, $top->fresh());                   // the post's author
        $this->assertSame(0, (int) CommunityPost::find($post->id)->comments_count);
        $this->assertCounterIsExact($post);
    }

    public function test_deleting_the_same_comment_twice_counts_once()
    {
        $community = app(Community::class);
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $fan = $this->member('Alice One');
        $keep = $community->comment($fan, $post, 'Stays');
        $gone = $community->comment($fan, $post, 'Goes');

        // The commenter and the post's author both press delete: the second sees it is already gone
        $stale = CommunityComment::find($gone->id);
        $community->deleteComment($fan, $gone);
        $community->deleteComment($author, $stale);                          // a copy loaded before the first delete

        $this->assertSame(1, (int) CommunityPost::find($post->id)->comments_count);
        $this->assertNotNull(CommunityComment::find($keep->id));
        $this->assertCounterIsExact($post);
    }

    public function test_a_post_removed_a_moment_before_the_lock_takes_no_comment()
    {
        $community = app(Community::class);
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $fan = $this->member('Alice One');
        $stale = CommunityPost::find($post->id);                              // looked fine when it was loaded
        CommunityPost::whereKey($post->id)->update(['status' => CommunityPost::STATUS_REMOVED]);

        try {
            $community->comment($fan, $stale, 'Too late');
            $this->fail('A comment was added to a removed post.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('post_not_found', $e->errorCode());
        }
        $this->assertSame(0, CommunityComment::count());
        $this->assertSame(0, (int) CommunityPost::find($post->id)->comments_count);
    }

    public function test_a_reply_to_a_comment_deleted_meanwhile_is_refused_and_counts_nothing()
    {
        $community = app(Community::class);
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $fan = $this->member('Alice One');
        $parent = $community->comment($author, $post, 'Parent');
        $community->deleteComment($author, $parent);

        $this->expectException(BusinessRuleException::class);
        try {
            $community->comment($fan, $post, 'Too late', $parent->id);
        } finally {
            $this->assertSame(0, (int) CommunityPost::find($post->id)->comments_count);
            $this->assertSame(0, CommunityComment::where('body', 'Too late')->count());
        }
    }

    public function test_a_failure_part_way_leaves_no_comment_no_count_and_no_notice()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $fan = $this->member('Alice One');

        // Telling the author fails after the comment was saved: all of it is rolled back
        $notify = \Mockery::mock(EventNotifications::class)->shouldIgnoreMissing();
        $notify->shouldReceive('commented')->andThrow(new \RuntimeException('notice failed'));
        $this->app->instance(EventNotifications::class, $notify);
        $this->app->forgetInstance(Community::class);

        try {
            app(Community::class)->comment($fan, $post, 'Will not stay');
            $this->fail('The failure was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('notice failed', $e->getMessage());
        }
        $this->assertSame(0, CommunityComment::count());
        $this->assertSame(0, (int) CommunityPost::find($post->id)->comments_count);
        $this->assertSame(0, UserNotification::count());
    }

    public function test_liking_a_comment_counts_once_and_takes_back_cleanly()
    {
        $community = app(Community::class);
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $fan = $this->member('Alice One');
        $comment = $community->comment($author, $post, 'Nice');

        $this->assertSame(1, $community->likeComment($fan, $comment, true));
        $this->assertSame(1, $community->likeComment($fan, $comment, true));    // again: still one
        $this->assertSame(2, $community->likeComment($this->member('Brian Two'), $comment, true));
        $this->assertSame(1, $community->likeComment($fan, $comment, false));
        $this->assertSame(1, $community->likeComment($fan, $comment, false));  // again: not below the real number
        $this->assertSame(1, DB::table('community_comment_likes')->where('community_comment_id', $comment->id)->count());
    }

    public function test_following_each_other_and_following_again_keeps_one_notification_group()
    {
        $a = $this->member('Alice One');
        $b = $this->member('Brian Two');
        $this->newPost($a);
        $this->newPost($b);
        $people = app(\App\Services\CommunityPeople::class);

        $people->follow($a, $b, true);
        $people->follow($b, $a, true);                                          // the opposite direction at once
        $people->follow($a, $b, false);
        $people->follow($a, $b, true);                                          // unfollow, follow again

        $this->assertSame(1, UserNotification::where('user_id', $b->id)->where('kind', 'community_follows')->count());
        $this->assertSame(1, UserNotification::where('user_id', $a->id)->where('kind', 'community_follows')->count());
        $this->assertSame(1, UserNotification::where('user_id', $b->id)->where('kind', 'community_follows')->value('group_count'));
    }

    public function test_the_notices_of_a_crowd_commenting_are_all_written_without_loss()
    {
        $community = app(Community::class);
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        foreach (range(1, 12) as $i) {
            $community->comment($this->member('Fan Number'.$i), $post, 'Comment '.$i);
        }
        $this->assertSame(12, (int) CommunityPost::find($post->id)->comments_count);
        $this->assertSame(12, CommunityComment::where('community_post_id', $post->id)->count());
        $this->assertCounterIsExact($post);
        // The author was told (today: the latest, as before; Phase C changes how)
        $this->assertGreaterThanOrEqual(1, UserNotification::where('user_id', $author->id)->where('kind', 'community_comments')->count());
    }
}
