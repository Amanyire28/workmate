<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\Report;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CommunityTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function member(array $attributes = []): User
    {
        return $this->signIn(User::factory()->create($attributes + ['name' => 'Aisha Nakato']));
    }

    /** A signed-in, approved provider whose main skill is plumbing */
    private function provider(): User
    {
        $user = $this->signIn(User::factory()->create(['name' => 'Okello Peter']));
        $this->putJson('/api/v1/me/provider', [
            'type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']],
        ])->assertOk();
        $user->providerProfile->forceFill(['status' => 'approved', 'document_verified_at' => now()])->save();

        return $user;
    }

    private function publish(array $data = [], array $photos = [])
    {
        // Past the three-a-minute posting limit
        $this->travel(21)->seconds();

        return $this->post('/api/v1/community/posts', $data + ['category' => 'tips', 'body' => 'Always test a joint before closing the wall.'] + ($photos ? ['photos' => $photos] : []), ['Accept' => 'application/json']);
    }

    private function postId(): string
    {
        return CommunityPost::latest('id')->value('uuid');
    }

    public function test_a_member_publishes_a_post_with_photos_that_are_redrawn()
    {
        $this->provider();
        $response = $this->publish(['category' => 'my_work', 'body' => "Finished a bathroom in Ntinda.\n\n\n\nNew pipes throughout."], [
            UploadedFile::fake()->image('before.jpg', 3000, 2000),
            UploadedFile::fake()->image('after.png', 800, 1200),
        ])->assertCreated()
            ->assertJsonPath('data.category', 'my_work')
            ->assertJsonPath('data.body', "Finished a bathroom in Ntinda.\n\nNew pipes throughout.")
            ->assertJsonPath('data.author.name', 'Okello Plumbing')
            ->assertJsonPath('data.author.title', 'Plumber')
            ->assertJsonPath('data.author.document_verified', true)
            ->assertJsonPath('data.topic.slug', 'plumbing')
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.media.0.width', 1600)
            ->assertJsonPath('data.media.0.height', 1067)
            ->assertJsonPath('data.media.1.width', 800);

        // No storage paths leave the server; files are on the local disk
        $this->assertStringNotContainsString('community/', str_replace('/community/media/', '', $response->getContent()));
        $media = CommunityPost::first()->media;
        Storage::disk('local')->assertExists([$media[0]->path, $media[0]->thumb_path]);

        // Anyone can see the photo, as a JPEG
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/community/media/'.$media[0]->uuid.'.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/api/v1/community/media/'.$media[0]->uuid.'-t.jpg')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'community.post_published')->exists());
    }

    public function test_posts_are_checked()
    {
        $this->member();
        $this->publish(['category' => 'gossip'])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->publish(['body' => '   '])->assertStatus(422)->assertJson(['code' => 'post_empty']);
        $this->publish(['body' => str_repeat('a', 3001)])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->publish([], [UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422)->assertJsonValidationErrors('photos.0');
        $this->publish([], [UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')])->assertStatus(422);
        $this->publish([], array_fill(0, 7, UploadedFile::fake()->image('p.jpg')))->assertStatus(422)->assertJsonValidationErrors('photos');

        // Posting quickly is limited
        $this->publish()->assertCreated();
        $this->post('/api/v1/community/posts', ['category' => 'tips', 'body' => 'Again'], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/community/posts', ['category' => 'tips', 'body' => 'Again'], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/community/posts', ['category' => 'tips', 'body' => 'Again'], ['Accept' => 'application/json'])->assertStatus(429);
        CommunityPost::query()->forceDelete();
        $this->assertSame(0, CommunityPost::count());
        // Nothing left behind by the failed ones
        $this->assertSame([], Storage::disk('local')->allFiles('community'));
    }

    public function test_taking_part_needs_a_confirmed_email_and_no_maintenance()
    {
        $this->postJson('/api/v1/community/posts', ['category' => 'tips', 'body' => 'Hello'])->assertUnauthorized();

        $this->member(['email_verified_at' => null]);
        $this->publish()->assertStatus(403)->assertJson(['code' => 'email_not_verified']);

        $admin = $this->userWithRoles(['super_admin']);
        app(PlatformSettings::class)->set('platform.maintenance_mode', true, $admin);
        $this->member();
        $this->publish()->assertStatus(503)->assertJson(['code' => 'maintenance']);
    }

    public function test_anyone_reads_the_feed_newest_first_with_filters_and_pages()
    {
        $this->provider();
        for ($i = 1; $i <= 12; $i++) {
            $this->travel(1)->minutes();
            $this->publish(['body' => "Tip number {$i}"])->assertCreated();
        }
        $this->member(['name' => 'Grace Achieng']);
        $this->publish(['category' => 'question', 'body' => 'Who fixes fridges in Kira?'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $first = $this->getJson('/api/v1/community/posts?tab=latest')->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.body', 'Who fixes fridges in Kira?')
            ->assertJsonPath('data.0.author.name', 'Grace A.')
            ->assertJsonPath('data.0.author.provider_id', null)
            ->assertJsonPath('data.0.liked', false)
            ->assertJsonPath('data.0.mine', false);
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);
        $this->getJson('/api/v1/community/posts?tab=latest&cursor='.urlencode($cursor))->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.body', 'Tip number 1')->assertJsonPath('meta.next_cursor', null);

        $this->getJson('/api/v1/community/posts?category=question')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/community/posts?topic=plumbing')->assertJsonCount(10, 'data');
        $this->getJson('/api/v1/community/posts?topic=cleaning')->assertJsonCount(0, 'data');
    }

    public function test_for_you_shows_a_providers_own_field()
    {
        $this->provider();
        $this->publish(['body' => 'Plumbing tip'])->assertCreated();
        $this->member();
        $this->publish(['body' => 'A cleaning question', 'topic' => 'cleaning'])->assertCreated();

        $this->provider();
        $this->getJson('/api/v1/community/posts?tab=for_you')->assertJsonPath('meta.personalised', true)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Plumbing tip');
        // Without a profession, "for you" is everything
        $this->member();
        $this->getJson('/api/v1/community/posts?tab=for_you')->assertJsonPath('meta.personalised', false)->assertJsonCount(2, 'data');
    }

    public function test_likes_and_saves_count_once_per_person()
    {
        $this->member();
        $this->publish()->assertCreated();
        $id = $this->postId();

        $fan = $this->member(['name' => 'Fan One']);
        $this->postJson("/api/v1/community/posts/{$id}/like")->assertOk()->assertJson(['data' => ['liked' => true, 'likes_count' => 1]]);
        $this->postJson("/api/v1/community/posts/{$id}/like")->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->member(['name' => 'Fan Two']);
        $this->postJson("/api/v1/community/posts/{$id}/like")->assertJsonPath('data.likes_count', 2);
        $this->deleteJson("/api/v1/community/posts/{$id}/like")->assertJsonPath('data.likes_count', 1);
        $this->deleteJson("/api/v1/community/posts/{$id}/like")->assertJsonPath('data.likes_count', 1);

        $this->signIn($fan);
        $this->postJson("/api/v1/community/posts/{$id}/save")->assertOk();
        $this->postJson("/api/v1/community/posts/{$id}/save")->assertOk();
        $this->getJson("/api/v1/community/posts/{$id}")->assertJsonPath('data.liked', true)->assertJsonPath('data.saved', true);
        $this->getJson('/api/v1/community/saved')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->deleteJson("/api/v1/community/posts/{$id}/save")->assertOk();
        $this->getJson('/api/v1/community/saved')->assertJsonCount(0, 'data');
    }

    public function test_comments_and_one_level_of_replies()
    {
        $author = $this->member();
        $this->publish(['category' => 'question', 'body' => 'Best way to clean grout?'])->assertCreated();
        $id = $this->postId();

        $first = $this->member(['name' => 'Moses Kato']);
        $top = $this->postJson("/api/v1/community/posts/{$id}/comments", ['body' => 'Baking soda and a brush.'])->assertCreated()
            ->assertJsonPath('data.author.name', 'Moses K.')->assertJsonPath('meta.comments_count', 1)->json('data.id');
        $reply = $this->postJson("/api/v1/community/posts/{$id}/comments", ['body' => 'Thanks!', 'parent' => $top])->assertCreated()->json('data');
        $this->assertSame($top, $reply['parent_id']);
        // A reply to a reply joins the same thread
        $this->postJson("/api/v1/community/posts/{$id}/comments", ['body' => 'Agreed.', 'parent' => $reply['id']])->assertCreated()->assertJsonPath('data.parent_id', $top);
        $this->postJson("/api/v1/community/posts/{$id}/comments", ['body' => ''])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/community/posts/{$id}/comments")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonCount(2, 'data.0.replies')->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.can_delete', false);

        // The post's author may delete a comment; it keeps its place while it has replies
        $this->signIn($author);
        $this->deleteJson("/api/v1/community/comments/{$top}")->assertOk()->assertJsonPath('meta.comments_count', 2);
        $this->getJson("/api/v1/community/posts/{$id}/comments")->assertJsonPath('data.0.gone', true)->assertJsonPath('data.0.body', null)
            ->assertJsonCount(2, 'data.0.replies');

        // Someone else can't delete a comment that isn't theirs
        $this->member(['name' => 'Stranger']);
        $this->deleteJson('/api/v1/community/comments/'.$reply['id'])->assertForbidden();
        $this->signIn($first);
        $this->deleteJson('/api/v1/community/comments/'.$reply['id'])->assertOk();
    }

    public function test_comments_and_replies_are_liked_once_per_person_and_quietly()
    {
        $this->member();
        $this->publish(['category' => 'question', 'body' => 'Best way to clean grout?'])->assertCreated();
        $id = $this->postId();
        $commenter = $this->member(['name' => 'Moses Kato']);
        $top = $this->postJson("/api/v1/community/posts/{$id}/comments", ['body' => 'Baking soda and a brush.'])->json('data.id');
        $reply = $this->postJson("/api/v1/community/posts/{$id}/comments", ['body' => 'Thanks!', 'parent' => $top])->json('data.id');
        $this->getJson("/api/v1/community/posts/{$id}/comments")->assertJsonPath('data.0.likes_count', 0)->assertJsonPath('data.0.liked', false);
        $notices = UserNotification::count();

        $fan = $this->member(['name' => 'Fan One']);
        $this->postJson("/api/v1/community/comments/{$top}/like")->assertOk()->assertJson(['data' => ['liked' => true, 'likes_count' => 1]]);
        $this->postJson("/api/v1/community/comments/{$top}/like")->assertJsonPath('data.likes_count', 1);   // twice counts once
        $this->postJson("/api/v1/community/comments/{$reply}/like")->assertJsonPath('data.likes_count', 1);
        $this->member(['name' => 'Fan Two']);
        $this->postJson("/api/v1/community/comments/{$top}/like")->assertJsonPath('data.likes_count', 2);
        $this->deleteJson("/api/v1/community/comments/{$top}/like")->assertJsonPath('data.likes_count', 1);
        $this->deleteJson("/api/v1/community/comments/{$top}/like")->assertJsonPath('data.likes_count', 1);

        // Each viewer sees their own like
        $this->signIn($fan);
        $this->getJson("/api/v1/community/posts/{$id}/comments")
            ->assertJsonPath('data.0.likes_count', 1)->assertJsonPath('data.0.liked', true)
            ->assertJsonPath('data.0.replies.0.likes_count', 1)->assertJsonPath('data.0.replies.0.liked', true);
        $this->signIn($commenter);
        $this->getJson("/api/v1/community/posts/{$id}/comments")->assertJsonPath('data.0.liked', false);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/community/posts/{$id}/comments")->assertJsonPath('data.0.likes_count', 1)->assertJsonPath('data.0.liked', false);
        $this->postJson("/api/v1/community/comments/{$top}/like")->assertStatus(401);

        // Comment likes don't notify anyone
        $this->assertSame($notices, UserNotification::count());

        // A confirmed email is needed; deleted comments can't be liked
        $this->member(['name' => 'New Member', 'email_verified_at' => null]);
        $this->postJson("/api/v1/community/comments/{$top}/like")->assertStatus(403);
        $this->signIn($commenter);
        $this->deleteJson("/api/v1/community/comments/{$reply}")->assertOk();
        $this->signIn($fan);
        $this->postJson("/api/v1/community/comments/{$reply}/like")->assertStatus(404);
        $this->postJson('/api/v1/community/comments/999999/like')->assertStatus(404);
    }

    public function test_authors_edit_and_delete_only_their_own_posts()
    {
        $author = $this->member();
        $this->publish()->assertCreated();
        $id = $this->postId();

        $this->member(['name' => 'Someone Else']);
        $this->patchJson("/api/v1/community/posts/{$id}", ['body' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/v1/community/posts/{$id}")->assertNotFound();

        $this->signIn($author);
        $this->patchJson("/api/v1/community/posts/{$id}", ['body' => 'Always test joints.', 'category' => 'experience'])->assertOk()
            ->assertJsonPath('data.body', 'Always test joints.')->assertJsonPath('data.category', 'experience');
        $this->assertNotNull(CommunityPost::first()->edited_at);
        $this->deleteJson("/api/v1/community/posts/{$id}")->assertOk();

        $this->getJson("/api/v1/community/posts/{$id}")->assertNotFound();
        $this->getJson('/api/v1/community/posts')->assertJsonCount(0, 'data');
        $this->assertTrue(AuditLog::where('action', 'community.post_deleted')->exists());
    }

    public function test_members_report_posts_and_comments_to_the_safety_team()
    {
        $author = $this->member();
        $this->publish(['body' => 'Send me money first and I will come.'])->assertCreated();
        $id = $this->postId();

        $this->postJson('/api/v1/reports', ['about' => 'post', 'post' => $id, 'reason' => 'spam'])->assertStatus(422)->assertJson(['code' => 'own_content']);

        $reporter = $this->member(['name' => 'Careful Customer']);
        $comment = $this->postJson("/api/v1/community/posts/{$id}/comments", ['body' => 'Is this legit?'])->json('data.id');
        $this->postJson('/api/v1/reports', ['about' => 'post', 'post' => $id, 'reason' => 'scam'])->assertCreated()->assertJsonPath('data.about_name', 'A community post');
        $this->postJson('/api/v1/reports', ['about' => 'post', 'post' => $id, 'reason' => 'payment'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/reports', ['about' => 'post', 'post' => '00000000-0000-0000-0000-000000000000', 'reason' => 'spam'])->assertNotFound();

        $report = Report::latest('id')->first();
        $this->assertSame($author->id, $report->subject_user_id);
        $this->assertSame('high', $report->severity);

        $this->signIn($author);
        $this->postJson('/api/v1/reports', ['about' => 'comment', 'comment' => $comment, 'reason' => 'abusive', 'details' => 'Rude.'])->assertCreated();
        $this->assertSame($reporter->id, Report::latest('id')->first()->subject_user_id);

        // The safety team sees what was reported
        $this->signIn($this->userWithRoles(['trust_safety']));
        $rows = collect($this->getJson('/api/v1/admin/data')->assertOk()->json('data.ops.reports'));
        $row = $rows->firstWhere('reportId', $report->id);
        $this->assertSame(['kind' => 'post', 'postId' => $id, 'commentId' => null, 'text' => 'Send me money first and I will come.', 'photos' => [], 'status' => 'published', 'removedReason' => null], $row['community']);
    }

    public function test_moderators_remove_and_restore_and_the_author_sees_why()
    {
        $author = $this->member();
        $this->publish()->assertCreated();
        $id = $this->postId();
        $this->member(['name' => 'Commenter']);
        $comment = $this->postJson("/api/v1/community/posts/{$id}/comments", ['body' => 'Buy followers cheap!!!'])->json('data.id');

        // Operations can't moderate content; content moderators can
        $this->signIn($this->userWithRoles(['operations']));
        $this->postJson("/api/v1/admin/community/posts/{$id}/remove", ['reason' => 'Advertising'])->assertForbidden();
        $moderator = $this->signIn($this->userWithRoles(['content']));
        $this->postJson("/api/v1/admin/community/posts/{$id}/remove", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/admin/community/comments/{$comment}/remove", ['reason' => 'Spam advertising'])->assertOk()->assertJsonPath('data.status', 'removed');
        $this->postJson("/api/v1/admin/community/posts/{$id}/remove", ['reason' => 'Off-topic advertising'])->assertOk()->assertJsonPath('data.status', 'removed');

        // Gone for everyone else, and its photos, likes and comments with it
        $this->member(['name' => 'Reader']);
        $this->getJson("/api/v1/community/posts/{$id}")->assertNotFound();
        $this->getJson('/api/v1/community/posts')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/community/posts/{$id}/like")->assertNotFound();

        // The author still sees it, with the reason
        $this->signIn($author);
        $this->getJson("/api/v1/community/posts/{$id}")->assertOk()->assertJsonPath('data.removed.reason', 'Off-topic advertising');
        $this->patchJson("/api/v1/community/posts/{$id}", ['body' => 'Changed'])->assertStatus(409);

        $this->signIn($moderator);
        $this->postJson("/api/v1/admin/community/posts/{$id}/restore", ['reason' => 'Removed by mistake'])->assertOk();
        $this->assertSame(0, CommunityPost::first()->comments_count);
        $this->assertTrue(AuditLog::where('action', 'community.post_removed')->where('actor_id', $moderator->id)->where('reason', 'Off-topic advertising')->exists());
        $this->assertSame(CommunityComment::STATUS_REMOVED, CommunityComment::find($comment)->status);
    }

    public function test_suspended_authors_disappear_from_the_feed()
    {
        $author = $this->member();
        $this->publish()->assertCreated();
        $id = $this->postId();

        $author->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->member(['name' => 'Reader']);
        $this->getJson('/api/v1/community/posts')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/community/posts/{$id}")->assertNotFound();
        $this->getJson("/api/v1/community/posts/{$id}/comments")->assertNotFound();
    }

    public function test_photos_of_hidden_posts_are_not_served()
    {
        $this->member();
        $this->publish([], [UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $id = $this->postId();
        $uuid = CommunityPost::first()->media[0]->uuid;
        $this->deleteJson("/api/v1/community/posts/{$id}")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/community/media/{$uuid}.jpg")->assertNotFound();
        $this->get('/api/v1/community/media/../../.env')->assertNotFound();

        // The safety team can still look at them
        $this->signIn($this->userWithRoles(['trust_safety']));
        $this->get("/api/v1/community/media/{$uuid}.jpg")->assertOk();
        $this->signIn($this->userWithRoles(['finance']));
        $this->get("/api/v1/community/media/{$uuid}.jpg")->assertNotFound();
    }
}
