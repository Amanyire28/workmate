<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CommunityPost;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ActivityTrackingTest extends ApiTestCase
{
    private function today(array $daily): array
    {
        return collect($daily)->firstWhere('date', now()->toDateString());
    }

    private function login(string $email, string $password)
    {
        return $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_sign_ins_are_security_events_with_masked_addresses()
    {
        $user = User::factory()->create(['email' => 'aisha@example.com', 'password' => bcrypt('Correct-Horse-1')]);

        $this->login('aisha@example.com', 'wrong')->assertStatus(422);
        $this->login('nobody@example.com', 'wrong')->assertStatus(422);
        $this->login('aisha@example.com', 'Correct-Horse-1')->assertOk();

        $failed = AuditLog::where('action', 'auth.sign_in_failed')->orderBy('id')->get();
        $this->assertCount(2, $failed);
        $this->assertSame($user->id, $failed[0]->subject_id);
        $this->assertNull($failed[1]->subject_id);
        $this->assertSame(['email' => 'a•••@example.com'], $failed[0]->after);
        $this->assertStringNotContainsString('aisha@', json_encode(AuditLog::all()));
        $this->assertTrue(AuditLog::where('action', 'auth.signed_in')->where('actor_id', $user->id)->exists());

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->app['auth']->forgetGuards();
        $this->login('aisha@example.com', 'Correct-Horse-1')->assertStatus(403);
        $this->assertTrue(AuditLog::where('action', 'auth.sign_in_blocked')->exists());

        // Security staff see the counts and the events; others don't
        $this->signIn($this->userWithRoles(['super_admin']));
        $data = $this->getJson('/api/v1/admin/data')->assertOk()->json('data');
        $this->assertSame(['signIns24h' => 1, 'failedSignIns24h' => 2, 'accountsTargeted24h' => 0], $data['security']);
        $event = collect($data['ops']['events'])->firstWhere('type', 'auth.sign_in_failed');
        $this->assertSame(['security', 'warning', 'failure'], [$event['category'], $event['severity'], $event['outcome']]);
        $this->assertSame('A sign-in failed (no such account)', $event['summary']);

        $this->signIn($this->userWithRoles(['operations']));
        $data = $this->getJson('/api/v1/admin/data')->assertOk()->json('data');
        $this->assertNull($data['security']);
        $this->assertNull(collect($data['ops']['events'])->firstWhere('category', 'security'));
    }

    public function test_daily_active_users_and_who_is_online()
    {
        $a = $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/me/heartbeat')->assertOk();
        $this->travel(30)->seconds();
        $this->postJson('/api/v1/me/heartbeat')->assertOk();
        $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/me/heartbeat')->assertOk();

        $this->assertSame(1, DB::table('user_activity_days')->where('user_id', $a->id)->count());

        $this->signIn($this->userWithRoles(['operations']));
        $data = $this->getJson('/api/v1/admin/data')->assertOk()->json('data');
        $this->assertSame(2, $this->today($data['daily'])['activeUsers']);
        $this->assertSame(2, $data['presenceNow']['onlineNow']);
        $this->assertSame(2, $data['presenceNow']['activeToday']);
        $this->assertContains('activeUsers', $data['trackedSeries']);
    }

    public function test_searches_and_profile_views_are_counted_without_personal_data()
    {
        $this->postJson('/api/v1/app-events/count', ['metric' => 'search', 'outcome' => 'nearby'])->assertStatus(202);
        $this->postJson('/api/v1/app-events/count', ['metric' => 'search', 'outcome' => 'none'])->assertStatus(202);
        $this->postJson('/api/v1/app-events/count', ['metric' => 'profile_view'])->assertStatus(202);
        $this->postJson('/api/v1/app-events/count', ['metric' => 'search'])->assertStatus(422);
        $this->postJson('/api/v1/app-events/count', ['metric' => 'password'])->assertStatus(422);

        $this->assertSame(['counted_on', 'id', 'metric', 'total'], collect(DB::getSchemaBuilder()->getColumnListing('app_daily_counts'))->sort()->values()->all());

        $this->signIn($this->userWithRoles(['operations']));
        $today = $this->today($this->getJson('/api/v1/admin/data')->json('data.daily'));
        $this->assertSame([2, 1, 0, 1, 1], [$today['searches'], $today['searchNearby'], $today['searchWider'], $today['searchNone'], $today['profileViews']]);
    }

    public function test_the_live_pulse_changes_when_something_happens()
    {
        $this->getJson('/api/v1/admin/pulse')->assertUnauthorized();
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/admin/pulse')->assertForbidden();

        $admin = $this->signIn($this->userWithRoles(['support']));
        $first = $this->getJson('/api/v1/admin/pulse')->assertOk()->json('data.stamp');
        $this->assertSame($first, $this->getJson('/api/v1/admin/pulse')->json('data.stamp'), 'Nothing happened, nothing changed');

        // Someone posts in the community
        $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/community/posts', ['category' => 'tips', 'body' => 'Fresh tip'])->assertCreated();
        $this->signIn($admin);
        $second = $this->getJson('/api/v1/admin/pulse')->json('data.stamp');
        $this->assertNotSame($first, $second);

        // A search is counted
        $this->postJson('/api/v1/app-events/count', ['metric' => 'search', 'outcome' => 'wider'])->assertStatus(202);
        $this->assertNotSame($second, $this->getJson('/api/v1/admin/pulse')->json('data.stamp'));
        $this->assertSame(['stamp', 'checked_at'], array_keys($this->getJson('/api/v1/admin/pulse')->json('data')));
    }

    public function test_daily_figures_come_from_real_activity()
    {
        $author = $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/community/posts', ['category' => 'tips', 'body' => 'Measure twice, cut once.'])->assertCreated();
        $post = CommunityPost::first();

        $fan = $this->signIn(User::factory()->create());
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'So true'])->assertCreated();
        $this->postJson("/api/v1/community/people/{$author->id}/follow")->assertOk();
        $this->postJson('/api/v1/reports', ['about' => 'post', 'post' => $post->uuid, 'reason' => 'spam'])->assertCreated();
        // A completed request is recorded in the audit log
        app(\App\Services\AuditLogger::class)->record('marketplace.request_completed', null, [], [], null, $fan);

        $this->signIn($this->userWithRoles(['super_admin']));
        $data = $this->getJson('/api/v1/admin/data')->assertOk()->json('data');
        $today = $this->today($data['daily']);
        $this->assertSame(1, $today['communityPosts']);
        $this->assertSame(1, $today['communityComments']);
        $this->assertSame(1, $today['follows']);
        $this->assertSame(1, $today['reportsOpened']);
        $this->assertSame(1, $today['jobsCompleted']);
        // The comment and the follow each told the author
        $this->assertSame(2, $today['notificationsSent']);

        // Community events have their own category, wording and a link
        $event = collect($data['ops']['events'])->firstWhere('type', 'community.post_published');
        $this->assertSame('community', $event['category']);
        $this->assertStringContainsString('posted in the community: “Measure twice, cut once.”', $event['summary']);
        $this->assertSame('/community/post/'.$post->uuid, $event['entity']['href']);
    }
}
