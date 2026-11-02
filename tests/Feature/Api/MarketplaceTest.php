<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ProviderProfile;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ProviderApplications;
use Database\Seeders\SampleMarketplaceSeeder;

class MarketplaceTest extends ApiTestCase
{
    /** An approved provider (owned by a new verified user) offering plumbing in Ntinda */
    private function provider(array $details = []): ProviderProfile
    {
        $owner = User::factory()->create();
        $reviewer = $this->userWithRoles(['provider_review']);
        $service = app(ProviderApplications::class);

        $service->saveDetails($owner, array_merge([
            'type' => 'individual',
            'display_name' => $owner->name,
            'professions' => [['profession' => 'plumber']],
            'base_area' => 'ntinda',
            'service_areas' => ['ntinda', 'bukoto'],
            'price_from' => 30000,
            'price_basis' => 'per job',
        ], $details));
        $service->approve($service->submit($owner), $reviewer);

        return $owner->providerProfile()->first();
    }

    private function review(ProviderProfile $provider, int $rating, string $status = 'published'): void
    {
        (new Review())->forceFill([
            'provider_profile_id' => $provider->id, 'reviewer_name' => 'Aisha N.', 'job_title' => 'Fixed a leak',
            'rating' => $rating, 'status' => $status, 'reviewed_at' => now(),
        ])->save();
    }

    private function postJob(array $overrides = []): int
    {
        return $this->postJson('/api/v1/jobs', array_merge([
            'category' => 'plumbing', 'title' => 'Fix a leaking pipe', 'description' => 'Under the kitchen sink.',
            'area' => 'ntinda', 'preferred_time' => 'This week', 'workers_needed' => 1, 'budget' => 50000, 'budget_basis' => 'fixed',
        ], $overrides))->assertCreated()->json('data.id');
    }

    public function test_only_approved_providers_with_active_accounts_are_listed()
    {
        $listed = $this->provider();
        $suspendedOwner = $this->provider();
        $suspendedOwner->owner->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/provider', ['type' => 'individual', 'display_name' => 'Draft', 'professions' => [['profession' => 'plumber']]])->assertOk();

        $ids = collect($this->getJson('/api/v1/providers')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$listed->id], $ids);
        $this->getJson("/api/v1/providers/{$suspendedOwner->id}")->assertNotFound();
    }

    public function test_listings_show_no_private_details_and_round_the_location()
    {
        $provider = $this->provider();

        $data = $this->getJson("/api/v1/providers/{$provider->id}")->assertOk()->json('data');

        $this->assertSame('ntinda', $data['base_area']['slug']);
        $this->assertSame(0.35, $data['latitude']);           // Ntinda centre 0.3546, rounded
        $this->assertSame(['plumbing'], $data['categories']);
        $this->assertSame(['ntinda', 'bukoto'], $data['service_areas']);
        foreach (['email', 'phone', 'owner_id', 'business_registration_number'] as $private) {
            $this->assertArrayNotHasKey($private, $data);
        }
        $this->assertStringNotContainsString($provider->owner->email, json_encode($data));
    }

    public function test_ratings_come_from_published_reviews_only()
    {
        $provider = $this->provider();
        $this->review($provider, 5);
        $this->review($provider, 4);
        $this->review($provider, 1, 'hidden');

        $this->getJson("/api/v1/providers/{$provider->id}")
            ->assertJsonPath('data.rating', 4.5)
            ->assertJsonPath('data.review_count', 2)
            ->assertJsonCount(2, 'data.reviews');

        $fresh = $this->provider();
        $this->getJson("/api/v1/providers/{$fresh->id}")
            ->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.review_count', 0);
    }

    public function test_posting_a_job_needs_an_account_and_valid_details()
    {
        $this->postJson('/api/v1/jobs', [])->assertUnauthorized();

        $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/jobs', ['category' => 'astrology', 'title' => '', 'area' => 'mars', 'workers_needed' => 0, 'budget' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category', 'title', 'area', 'workers_needed', 'budget']);
    }

    public function test_a_posted_job_is_public_and_shows_in_my_activity()
    {
        $customer = $this->signIn(User::factory()->create());
        $id = $this->postJob();

        $this->getJson('/api/v1/jobs')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.area_name', 'Ntinda')
            ->assertJsonPath('data.0.is_mine', true);
        $this->getJson('/api/v1/me/requests')->assertJsonPath('data.0.kind', 'posted');

        $this->assertSame($customer->id, ServiceRequest::findOrFail($id)->customer_id);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.job_posted')->count());
    }

    public function test_only_approved_providers_can_apply_once_and_not_to_their_own_job()
    {
        $this->signIn(User::factory()->create());
        $jobId = $this->postJob();

        // A customer who isn't a provider
        $this->signIn(User::factory()->create());
        $this->postJson("/api/v1/jobs/{$jobId}/applications", ['message' => str_repeat('I can do this. ', 3), 'start_time' => 'Today'])
            ->assertForbidden()
            ->assertJson(['code' => 'provider_required']);

        $provider = $this->provider();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/jobs/{$jobId}/applications", ['message' => 'Too short', 'start_time' => 'Today'])
            ->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postJson("/api/v1/jobs/{$jobId}/applications", ['message' => str_repeat('I can do this. ', 3), 'start_time' => 'Today', 'amount' => 45000])
            ->assertCreated();
        $this->postJson("/api/v1/jobs/{$jobId}/applications", ['message' => str_repeat('I can do this. ', 3), 'start_time' => 'Today'])
            ->assertStatus(409)->assertJson(['code' => 'already_applied']);

        $this->getJson("/api/v1/jobs/{$jobId}")->assertJsonPath('data.has_applied', true)->assertJsonPath('data.applicants', 1);
        $this->getJson('/api/v1/me/applications')
            ->assertJsonPath('data.0.amount', 45000)
            ->assertJsonPath('data.0.job.id', $jobId);

        $ownJob = $this->postJob();
        $this->postJson("/api/v1/jobs/{$ownJob}/applications", ['message' => str_repeat('I can do this. ', 3), 'start_time' => 'Today'])
            ->assertStatus(422)->assertJson(['code' => 'own_job']);
    }

    public function test_requests_to_one_provider_stay_private()
    {
        $provider = $this->provider();
        $customer = $this->signIn(User::factory()->create());

        $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'electrical', 'description' => 'Sockets keep sparking in the kitchen.', 'area' => 'ntinda', 'preferred_time' => 'This week',
        ])->assertStatus(422)->assertJson(['code' => 'service_not_offered']);

        $id = $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen tap drips all night.', 'area' => 'ntinda', 'preferred_time' => 'This week',
        ])->assertCreated()->assertJsonPath('data.kind', 'direct')->json('data.id');

        // Not among open jobs, and only the customer can open it
        $this->assertNotContains($id, collect($this->getJson('/api/v1/jobs')->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/jobs/{$id}")->assertOk();
        $this->signIn(User::factory()->create());
        $this->getJson("/api/v1/jobs/{$id}")->assertNotFound();

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'Requesting myself for some reason.', 'area' => 'ntinda', 'preferred_time' => 'Today',
        ])->assertStatus(422)->assertJson(['code' => 'own_provider']);
        $this->assertSame(1, ServiceRequest::where('customer_id', $customer->id)->count());
    }

    public function test_chats_are_only_visible_to_their_two_sides()
    {
        $provider = $this->provider();
        $customer = $this->signIn(User::factory()->create(['name' => 'Nakato Sarah']));

        $chat = $this->postJson("/api/v1/me/conversations/provider/{$provider->id}")->assertOk()->json('data');
        $this->assertSame('customer', $chat['role']);
        $this->postJson("/api/v1/me/conversations/{$chat['id']}/messages", ['body' => 'Are you free on Saturday?'])
            ->assertCreated()->assertJsonPath('data.mine', true);

        // The provider sees it as unread, from the customer's first name only
        $this->signIn($provider->owner);
        $this->getJson('/api/v1/me/conversations/unread-count')->assertJsonPath('data.unread', 1);
        $this->getJson('/api/v1/me/conversations')
            ->assertJsonPath('data.0.role', 'provider')
            ->assertJsonPath('data.0.counterpart_name', 'Nakato')
            ->assertJsonPath('data.0.unread', 1);
        $this->getJson("/api/v1/me/conversations/{$chat['id']}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'Are you free on Saturday?')
            ->assertJsonPath('data.messages.0.mine', false);
        $this->getJson('/api/v1/me/conversations/unread-count')->assertJsonPath('data.unread', 0);
        $this->postJson("/api/v1/me/conversations/{$chat['id']}/messages", ['body' => 'Yes, from 9am.'])->assertCreated();

        // Anyone else gets "not found"
        $this->signIn(User::factory()->create());
        $this->getJson("/api/v1/me/conversations/{$chat['id']}")->assertNotFound();
        $this->postJson("/api/v1/me/conversations/{$chat['id']}/messages", ['body' => 'Hi'])->assertNotFound();

        $this->signIn($customer);
        $this->getJson('/api/v1/me/conversations/unread-count')->assertJsonPath('data.unread', 1);
    }

    public function test_reports_are_stored_for_the_safety_team()
    {
        $provider = $this->provider();

        $this->postJson("/api/v1/providers/{$provider->id}/reports", ['reason' => 'scam'])->assertUnauthorized();

        $reporter = $this->signIn(User::factory()->create());
        $this->postJson("/api/v1/providers/{$provider->id}/reports", ['reason' => 'gossip'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/providers/{$provider->id}/reports", ['reason' => 'scam', 'details' => 'Asked for money upfront.'])
            ->assertCreated();

        $report = \App\Models\Report::sole();
        $this->assertSame($reporter->id, $report->reporter_id);
        $this->assertSame($provider->id, $report->provider_profile_id);
        $this->assertSame('open', $report->status);
        // A report alone changes nothing about the provider
        $this->assertSame('approved', $provider->fresh()->status);
    }

    public function test_the_catalog_sends_everything_the_first_page_needs_in_one_request()
    {
        $provider = $this->provider();

        $data = $this->getJson('/api/v1/catalog')->assertOk()->json('data');

        $this->assertSame(['categories', 'areas', 'providers', 'reviews', 'jobs', 'settings'], array_keys($data));
        // Only settings the app applies; nothing only administrators should see
        $this->assertSame(5, $data['settings']['search.nearby_km']);
        $this->assertArrayNotHasKey('presence.online_minutes', $data['settings']);
        $this->assertCount(\App\Models\Category::where('is_active', true)->count(), $data['categories']);
        $this->assertSame($provider->id, $data['providers'][0]['id']);
    }

    public function test_the_sample_data_seeder_adds_labelled_samples_once()
    {
        $this->seed(SampleMarketplaceSeeder::class);
        $this->seed(SampleMarketplaceSeeder::class);

        $this->assertSame(14, ProviderProfile::where('is_sample', true)->count());
        $this->assertSame(24, Review::where('is_sample', true)->count());
        $this->assertSame(7, ServiceRequest::where('is_sample', true)->count());

        $providers = $this->getJson('/api/v1/providers')->assertOk()->json('data');
        $this->assertCount(14, $providers);
        $this->assertTrue(collect($providers)->every(function ($p) {
            return $p['is_sample'] === true;
        }));

        // Sample accounts can't be signed into
        $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => 'sample+provider-1@workmate.invalid', 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_the_directory_says_which_profession_is_each_providers_main_one()
    {
        $provider = $this->provider(['professions' => [['profession' => 'electrician'], ['profession' => 'plumber']]]);

        $listed = collect($this->getJson('/api/v1/providers')->assertOk()->json('data'))->firstWhere('id', $provider->id);
        $this->assertSame('electrician', $listed['main_profession']);
        $this->assertSame('electrical', $listed['main_category']);
        $this->assertEqualsCanonicalizing(['electrical', 'plumbing'], $listed['categories']);
        $this->assertTrue(collect($listed['professions'])->firstWhere('slug', 'electrician')['is_primary']);
    }
}
