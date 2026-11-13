<?php

namespace Tests\Feature\Api;

use App\Models\CommunityPost;
use App\Models\DocumentVerification;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\BookingReminders;
use App\Services\ProviderApplications;
use App\Services\ProviderServices;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Profile completeness, the suggestion cards in the pages (never sent as
 * notifications), booking reminders and recommendations.
 */
class EngagementTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 18 November 2026, 10:00 in Kampala (07:00 UTC)
        Carbon::setTestNow(Carbon::parse('2026-11-18 07:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function phone(): string
    {
        return '07720'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }

    private function applicant(?User $owner = null, string $profession = 'plumber', string $base = 'ntinda'): User
    {
        $owner = $owner ?: User::factory()->create(['phone' => $this->phone()]);
        app(ProviderApplications::class)->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello '.$profession.' '.$owner->id, 'professions' => [['profession' => $profession]], 'base_area' => $base]);

        return $owner;
    }

    private function provider(string $profession = 'plumber', string $base = 'ntinda'): ProviderProfile
    {
        $owner = $this->applicant(null, $profession, $base);
        $service = app(ProviderApplications::class);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function suggestions(User $user): array
    {
        $this->signIn($user->fresh());   // as a new request would: nothing cached from earlier

        return $this->getJson('/api/v1/me/suggestions')->assertOk()->json('data');
    }

    private function completeness(User $user)
    {
        $this->signIn($user);

        return $this->getJson('/api/v1/me/profile-completeness');
    }

    private function postJob(User $customer, array $extra = []): int
    {
        $this->signIn($customer);

        return $this->postJson('/api/v1/jobs', $extra + ['category' => 'plumbing', 'title' => 'Fix a leaking sink', 'area' => 'kisaasi', 'workers_needed' => 1])->assertCreated()->json('data.id');
    }

    /* ---------------- Access ---------------- */

    public function test_signed_out_people_get_nothing()
    {
        $this->fromWebsite()->getJson('/api/v1/me/profile-completeness')->assertStatus(401);
        $this->fromWebsite()->getJson('/api/v1/me/suggestions')->assertStatus(401);
        $this->fromWebsite()->getJson('/api/v1/me/recommendations')->assertStatus(401);
    }

    /* ---------------- Completeness ---------------- */

    public function test_a_customer_sees_what_is_missing()
    {
        $user = User::factory()->create();
        $data = $this->completeness($user)->assertOk()->json('data');

        $this->assertSame('customer', $data['role']);
        $this->assertFalse($data['complete']);
        $this->assertSame('phone', $data['next']['key']);
        $this->assertSame('/account?panel=profile', $data['next']['url']);
        $this->assertSame(['name', 'email', 'phone', 'photo'], array_column($data['items'], 'key'));
        $this->assertArrayNotHasKey('show_card', $data);

        $user->forceFill(['phone' => $this->phone()])->save();
        $data = $this->completeness($user)->json('data');
        $this->assertTrue($data['complete']);
        $this->assertSame('photo', $data['next']['key']);   // still suggested, not required
        $this->assertSame('/account#profile-photo', $data['next']['url']);
    }

    public function test_an_applicant_is_guided_through_the_application_and_is_never_hireable()
    {
        $owner = $this->applicant();
        $data = $this->completeness($owner)->json('data');

        $this->assertSame('applicant', $data['role']);
        $this->assertTrue($data['complete']);
        $this->assertSame(0, ProviderProfile::publiclyListed()->count());
        $this->assertNotContains('verification', array_column($data['items'], 'key'));

        $owner->providerProfile->forceFill(['status' => ProviderProfile::STATUS_PENDING_REVIEW])->save();
        $this->assertFalse($this->completeness($owner->fresh())->json('data.applies'));
    }

    public function test_an_approved_provider_needs_a_photo_services_and_a_price()
    {
        $provider = $this->provider();
        $data = $this->completeness($provider->owner)->json('data');

        $this->assertSame('provider', $data['role']);
        $this->assertFalse($data['complete']);
        $missing = array_column($data['missing'], 'key');
        $this->assertContains('photo', $missing);
        $this->assertContains('services', $missing);
        $this->assertSame('/provider/'.$provider->id.'#services', collect($data['items'])->firstWhere('key', 'services')['url']);
    }

    /* ---------------- Suggestion cards ---------------- */

    public function test_a_customer_with_an_incomplete_profile_gets_one_card_on_account_and_home()
    {
        $user = User::factory()->create();
        $data = $this->suggestions($user);

        $this->assertSame(['complete_profile'], array_keys($data['cards']));
        $card = $data['cards']['complete_profile'];
        $this->assertSame('Make your profile stand out! ✨', $card['title']);
        $this->assertSame('/account?panel=profile', $card['action']['url']);
        $this->assertSame(50, $card['progress']);
        $this->assertEquals(['account' => 'complete_profile', 'home' => 'complete_profile', 'work' => null, 'provider' => null, 'community' => null], $data['pages']);

        // Done: the card goes, with nothing to dismiss
        $user->forceFill(['phone' => $this->phone()])->save();
        $data = $this->suggestions($user);
        $this->assertSame([], $data['cards']);
        $this->assertSame([null], array_values(array_unique($data['pages'])));
    }

    public function test_an_unconfirmed_email_is_suggested_first()
    {
        $user = User::factory()->create(['email_verified_at' => null, 'phone' => $this->phone()]);
        $card = $this->suggestions($user)['cards']['complete_profile'];
        $this->assertStringContainsString('Confirm your email', $card['body']);
    }

    public function test_an_applicant_is_asked_to_finish_the_application_and_nothing_else()
    {
        $owner = $this->applicant();
        $owner->providerProfile->forceFill(['base_area_id' => null])->save();
        $data = $this->suggestions($owner->fresh());

        $this->assertSame(['finish_application'], array_keys($data['cards']));
        $card = $data['cards']['finish_application'];
        $this->assertSame('/start-earning', $card['action']['url']);
        $this->assertStringContainsString('where you’re based', $card['body']);
        $this->assertSame('finish_application', $data['pages']['account']);
        $this->assertSame('finish_application', $data['pages']['home']);
        $this->assertNull($data['pages']['work']);

        // Changes asked for
        $owner->providerProfile->forceFill(['status' => ProviderProfile::STATUS_MORE_INFO_REQUIRED, 'base_area_id' => null])->save();
        $this->assertSame('Update your provider application', $this->suggestions($owner->fresh())['cards']['finish_application']['title']);

        // Sent: nothing to suggest while it's reviewed
        $owner->providerProfile->forceFill(['status' => ProviderProfile::STATUS_PENDING_REVIEW])->save();
        $this->assertSame([], $this->suggestions($owner->fresh())['cards']);
    }

    public function test_a_new_provider_gets_different_cards_on_different_pages()
    {
        $provider = $this->provider();
        $data = $this->suggestions($provider->owner);

        $this->assertEqualsCanonicalizing(['add_services', 'add_photo', 'get_verified', 'share_work'], array_keys($data['cards']));
        $this->assertEquals([
            'account' => 'add_photo', 'home' => 'add_services', 'work' => 'add_services', 'provider' => 'get_verified', 'community' => 'share_work',
        ], $data['pages']);
        // No card on more than two pages
        foreach (array_count_values(array_filter($data['pages'])) as $times) {
            $this->assertLessThanOrEqual(2, $times);
        }
        // Each opens the place where it's done
        $this->assertSame('/provider/'.$provider->id.'#services', $data['cards']['add_services']['action']['url']);
        $this->assertSame('/account#profile-photo', $data['cards']['add_photo']['action']['url']);
        $this->assertSame('/account/verification', $data['cards']['get_verified']['action']['url']);
        $this->assertSame('/community?compose=1', $data['cards']['share_work']['action']['url']);
        $this->assertNull($data['cards']['add_services']['progress']);
    }

    public function test_doing_each_thing_removes_its_card()
    {
        $provider = $this->provider();
        $owner = $provider->owner;

        // Services with a price
        app(ProviderServices::class)->add($owner, ['title' => 'Fix a leaking tap', 'category' => 'plumbing', 'pricing_type' => 'from', 'price' => 20000, 'price_unit' => 'job']);
        $this->assertArrayNotHasKey('add_services', $this->suggestions($owner)['cards']);

        // A photo
        $owner->forceFill(['avatar_path' => 'avatars/test.jpg'])->save();
        $this->assertArrayNotHasKey('add_photo', $this->suggestions($owner->fresh())['cards']);

        // A community post
        $post = new CommunityPost();
        $post->forceFill(['uuid' => (string) Str::uuid(), 'user_id' => $owner->id, 'category' => 'my_work', 'body' => 'Finished a bathroom in Ntinda today.', 'status' => CommunityPost::STATUS_PUBLISHED])->save();
        $this->assertArrayNotHasKey('share_work', $this->suggestions($owner)['cards']);

        // Verification: sent back asks to finish it; sent or verified, nothing
        $v = new DocumentVerification();
        $v->forceFill(['provider_profile_id' => $provider->id, 'status' => DocumentVerification::STATUS_INFO_REQUESTED])->save();
        $this->assertSame('Finish your verification', $this->suggestions($owner)['cards']['get_verified']['title']);
        $v->forceFill(['status' => DocumentVerification::STATUS_PENDING_REVIEW])->save();
        $this->assertArrayNotHasKey('get_verified', $this->suggestions($owner)['cards']);
        $v->forceFill(['status' => DocumentVerification::STATUS_DRAFT])->save();
        $provider->forceFill(['document_verified_at' => now()])->save();
        $this->assertArrayNotHasKey('get_verified', $this->suggestions($owner)['cards']);

        $this->assertSame([], $this->suggestions($owner)['cards']);
    }

    public function test_matching_open_jobs_are_suggested_until_applied_to()
    {
        $provider = $this->provider('plumber', 'ntinda');
        $job = $this->postJob(User::factory()->create());
        $this->postJob(User::factory()->create(), ['title' => 'Wiring', 'category' => 'electrical']);

        $card = $this->suggestions($provider->owner)['cards']['explore_jobs'];
        $this->assertSame('A new job matches your skills', $card['title']);
        $this->assertSame('/find-work?category=plumbing', $card['action']['url']);

        $q = new Quotation();
        $q->forceFill(['service_request_id' => $job, 'provider_profile_id' => $provider->id, 'message' => 'I can do it this week.', 'status' => Quotation::STATUS_PENDING])->save();
        $this->assertArrayNotHasKey('explore_jobs', $this->suggestions($provider->owner)['cards']);
    }

    public function test_sample_providers_get_no_cards()
    {
        $provider = $this->provider();
        $provider->forceFill(['is_sample' => true])->save();
        $this->assertSame([], $this->suggestions($provider->owner->fresh())['cards']);
    }

    /* ---------------- Never sent as notifications ---------------- */

    public function test_suggestions_are_never_sent_as_notifications()
    {
        $customer = User::factory()->create();
        $applicant = $this->applicant();
        $applicant->providerProfile->forceFill(['base_area_id' => null])->save();
        $this->provider();
        $before = UserNotification::count();

        $this->travel(20)->days();
        $this->artisan('workmate:reminders')->assertExitCode(0);
        // Seeing the cards and completing them doesn't send anything either
        $this->suggestions($customer);
        $customer->forceFill(['phone' => $this->phone()])->save();
        $this->suggestions($customer);

        $this->assertSame($before, UserNotification::count());
        $this->assertSame(0, DB::table('engagement_reminders')->count());
        $settings = $this->getJson('/api/v1/me/notification-settings')->assertOk()->json('data');
        $this->assertArrayHasKey('requests', $settings);
        $this->assertArrayNotHasKey('reminders', $settings);
    }

    public function test_sending_an_application_is_confirmed_in_the_bell()
    {
        $owner = $this->applicant();
        app(ProviderApplications::class)->submit($owner);

        $this->assertSame(['Your provider application has been submitted'], UserNotification::where('user_id', $owner->id)->where('kind', 'account')->pluck('title')->all());
    }

    /* ---------------- Booking reminders ---------------- */

    private function booking(ProviderProfile $provider, User $customer): int
    {
        $this->signIn($customer);
        $id = $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'ntinda',
            'timing' => 'scheduled', 'scheduled_start' => '2026-11-20 10:00', 'duration_minutes' => 120,
        ])->assertCreated()->json('data.id');
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/accept")->assertOk();
        $this->assertSame(ServiceRequest::STATUS_SCHEDULED, ServiceRequest::find($id)->status);

        return $id;
    }

    public function test_booking_reminders_come_a_day_and_an_hour_before_once_each()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['name' => 'Nakato Sarah', 'phone' => $this->phone()]);
        $this->booking($provider, $customer);

        // Thursday 10:00 Kampala: a day before
        Carbon::setTestNow(Carbon::parse('2026-11-19 07:00:00', 'UTC'));
        app(BookingReminders::class)->run();
        app(BookingReminders::class)->run();
        $this->assertSame(1, UserNotification::where('user_id', $customer->id)->where('title', 'Upcoming booking')->count());
        $this->assertSame(1, UserNotification::where('user_id', $provider->owner_id)->where('title', 'Booking reminder')->count());

        // Friday 09:15: an hour before
        Carbon::setTestNow(Carbon::parse('2026-11-20 06:15:00', 'UTC'));
        app(BookingReminders::class)->run();
        app(BookingReminders::class)->run();
        $this->assertSame(1, UserNotification::where('user_id', $customer->id)->where('title', 'Your booking is soon')->count());
        $this->assertSame(1, UserNotification::where('user_id', $provider->owner_id)->where('title', 'Your booking is soon')->count());
        $this->assertSame(4, DB::table('engagement_reminders')->count());
    }

    public function test_cancelled_bookings_and_people_who_turned_requests_off_get_no_reminder()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['phone' => $this->phone(), 'notification_preferences' => ['requests' => false]]);
        $this->booking($provider, $customer);
        $other = $this->provider();
        $customer2 = User::factory()->create(['phone' => $this->phone()]);
        $cancelled = $this->booking($other, $customer2);
        ServiceRequest::whereKey($cancelled)->update(['status' => ServiceRequest::STATUS_CANCELLED]);

        Carbon::setTestNow(Carbon::parse('2026-11-19 07:00:00', 'UTC'));
        app(BookingReminders::class)->run();

        $this->assertSame(0, UserNotification::where('user_id', $customer->id)->where('title', 'Upcoming booking')->count());
        $this->assertSame(1, UserNotification::where('user_id', $provider->owner_id)->where('title', 'Booking reminder')->count());
        $this->assertSame(0, UserNotification::whereIn('user_id', [$customer2->id, $other->owner_id])->whereIn('title', ['Upcoming booking', 'Booking reminder'])->count());
        $this->assertSame('["suppressed"]', DB::table('engagement_reminders')->where('user_id', $customer->id)->value('channels'));
    }

    public function test_a_booking_moved_to_a_new_time_is_reminded_at_the_new_time()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['phone' => $this->phone()]);
        $id = $this->booking($provider, $customer);

        Carbon::setTestNow(Carbon::parse('2026-11-19 07:00:00', 'UTC'));
        app(BookingReminders::class)->run();
        ServiceRequest::whereKey($id)->update(['scheduled_start_at' => Carbon::parse('2026-11-20 09:00:00', 'UTC'), 'scheduled_end_at' => Carbon::parse('2026-11-20 11:00:00', 'UTC')]);
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'UTC'));
        app(BookingReminders::class)->run();

        $this->assertSame(2, DB::table('engagement_reminders')->where('user_id', $customer->id)->where('type', 'booking_24h')->count());
    }

    /* ---------------- Recommendations ---------------- */

    public function test_providers_are_recommended_suitable_open_jobs_without_exact_locations()
    {
        $provider = $this->provider('plumber', 'ntinda');
        $customer = User::factory()->create();
        $near = $this->postJob($customer, ['title' => 'Near plumbing job']);
        $far = $this->postJob($customer, ['title' => 'Far plumbing job', 'area' => 'entebbe']);
        $other = $this->postJob($customer, ['title' => 'Wiring job', 'category' => 'electrical']);
        $applied = $this->postJob($customer, ['title' => 'Already applied']);
        $q = new Quotation();
        $q->forceFill(['service_request_id' => $applied, 'provider_profile_id' => $provider->id, 'message' => 'I can do it this week.', 'status' => Quotation::STATUS_PENDING])->save();
        $closed = $this->postJob($customer, ['title' => 'Closed job']);
        ServiceRequest::whereKey($closed)->update(['status' => ServiceRequest::STATUS_CANCELLED]);

        $this->signIn($provider->owner);
        $jobs = $this->getJson('/api/v1/me/recommendations')->assertOk()->json('data.jobs');
        $this->assertSame([$near], array_column($jobs, 'id'));
        $this->assertArrayNotHasKey('location', $jobs[0]);
        $this->assertStringNotContainsString('latitude', json_encode($jobs));
        $this->assertNotContains($far, array_column($jobs, 'id'));
        $this->assertNotContains($other, array_column($jobs, 'id'));

        // Applicants and customers get no job suggestions
        $this->signIn($this->applicant());
        $this->assertSame([], $this->getJson('/api/v1/me/recommendations')->json('data.jobs'));
        $this->signIn($customer);
        $this->assertSame([], $this->getJson('/api/v1/me/recommendations')->json('data.jobs'));
    }
}
