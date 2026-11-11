<?php

namespace Tests\Feature\Api;

use App\Models\Message;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ProviderApplications;
use Carbon\Carbon;

/**
 * Bookings: times, double-booking prevention, suggested times, cancelling,
 * time off, availability, exact job locations and request cards in chat.
 */
class BookingTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A fixed "now": Wednesday 18 November 2026, 08:00 in Kampala (05:00 UTC)
        Carbon::setTestNow(Carbon::parse('2026-11-18 05:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    /** A request from a new customer (signed in as them) */
    private function send(ProviderProfile $provider, array $extra = [], ?User $customer = null)
    {
        $this->signIn($customer ?: User::factory()->create(['name' => 'Nakato Sarah']));

        return $this->postJson("/api/v1/providers/{$provider->id}/requests", $extra + [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'ntinda',
            'timing' => 'scheduled', 'scheduled_start' => '2026-11-20 10:00', 'duration_minutes' => 120,
        ]);
    }

    private function accept(ProviderProfile $provider, int $id, array $body = [])
    {
        $this->signIn($provider->owner);

        return $this->postJson("/api/v1/me/received-requests/{$id}/accept", $body);
    }

    /* ---------------- Times ---------------- */

    public function test_a_customer_books_a_date_and_time_in_uganda_time()
    {
        $provider = $this->provider();
        $data = $this->send($provider, ['landmark' => 'Opposite the Shell station'])->assertCreated()->json('data');

        $this->assertSame('scheduled', $data['schedule']['timing']);
        $this->assertSame('2026-11-20T10:00:00+03:00', $data['schedule']['start']);
        $this->assertSame('2026-11-20T12:00:00+03:00', $data['schedule']['end']);
        // Older screens still get a readable time
        $this->assertSame('Fri 20 Nov, 10:00 AM', $data['preferred_time']);
        $this->assertSame('open', $data['status']);
        $r = ServiceRequest::first();
        $this->assertSame('2026-11-20 07:00:00', $r->scheduled_start_at->format('Y-m-d H:i:s'));   // stored in UTC
    }

    public function test_times_in_the_past_or_too_far_ahead_are_refused()
    {
        $provider = $this->provider();
        $this->send($provider, ['scheduled_start' => '2026-11-18 08:10'])->assertStatus(422)->assertJsonPath('code', 'time_too_soon');
        $this->send($provider, ['scheduled_start' => '2027-06-01 10:00'])->assertStatus(422)->assertJsonPath('code', 'time_too_far');
        $this->send($provider, ['scheduled_start' => 'tomorrow'])->assertStatus(422)->assertJsonValidationErrors('scheduled_start');
        $this->send($provider, ['timing' => 'scheduled', 'scheduled_start' => null])->assertStatus(422)->assertJsonValidationErrors('scheduled_start');
        $this->send($provider, ['duration_minutes' => 45])->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_accepting_confirms_the_booking_and_the_customer_is_told()
    {
        $provider = $this->provider();
        $id = $this->send($provider)->json('data.id');

        $this->accept($provider, $id)->assertOk()->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.schedule.start', '2026-11-20T10:00:00+03:00');
        $this->assertDatabaseHas('user_notifications', ['title' => 'Booking confirmed']);

        // Started when they arrive, then done
        $this->postJson("/api/v1/me/received-requests/{$id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/v1/me/received-requests/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    /* ---------------- Double booking ---------------- */

    public function test_a_provider_cant_accept_two_overlapping_bookings()
    {
        $provider = $this->provider();
        $first = $this->send($provider)->json('data.id');
        // Pending requests don't hold the time: a second one for 11:00 can still be sent
        $second = $this->send($provider, ['scheduled_start' => '2026-11-20 11:00', 'duration_minutes' => 60])->assertCreated()->json('data.id');
        $later = $this->send($provider, ['scheduled_start' => '2026-11-20 12:00', 'duration_minutes' => 60])->assertCreated()->json('data.id');

        $this->accept($provider, $first)->assertOk();
        $this->accept($provider, $second)->assertStatus(409)->assertJsonPath('code', 'time_taken');
        // Back to back is fine: 10:00–12:00, then 12:00–13:00
        $this->accept($provider, $later)->assertOk();
        $this->assertSame('open', ServiceRequest::find($second)->status);

        // Customers can't send a request for a time that's already booked, and aren't told by whom
        $this->send($provider, ['scheduled_start' => '2026-11-20 11:30'])->assertStatus(409)
            ->assertJsonPath('message', 'This provider already has an appointment at that time. Please choose another time.');
    }

    public function test_a_longer_length_set_when_accepting_is_checked_too()
    {
        $provider = $this->provider();
        $morning = $this->send($provider, ['scheduled_start' => '2026-11-20 08:00', 'duration_minutes' => 60])->json('data.id');
        $noon = $this->send($provider, ['scheduled_start' => '2026-11-20 12:00', 'duration_minutes' => 60])->json('data.id');
        $this->accept($provider, $noon)->assertOk();

        // Saying the 8:00 job takes 5 hours would run into noon
        $this->accept($provider, $morning, ['duration_minutes' => 300])->assertStatus(409)->assertJsonPath('code', 'time_taken');
        $this->accept($provider, $morning, ['duration_minutes' => 240])->assertOk()->assertJsonPath('data.schedule.duration_minutes', 240);
    }

    public function test_a_cancelled_booking_frees_the_time()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $first = $this->send($provider, [], $customer)->json('data.id');
        $second = $this->send($provider)->json('data.id');
        $this->accept($provider, $first)->assertOk();

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$first}/cancel", ['reason' => 'Fixed it myself'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('user_notifications', ['title' => 'Request cancelled', 'user_id' => $provider->owner_id]);

        $this->accept($provider, $second)->assertOk();
        // Done or cancelled requests can't be cancelled again
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$first}/cancel")->assertStatus(409);
    }

    public function test_time_off_blocks_bookings_and_requests()
    {
        $provider = $this->provider();
        $id = $this->send($provider)->json('data.id');

        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/provider/time-off', ['starts_at' => '2026-11-20 00:00', 'ends_at' => '2026-11-20 23:59', 'note' => 'Family event'])
            ->assertCreated()->assertJsonCount(1, 'data.time_off');
        $this->accept($provider, $id)->assertStatus(409);
        $this->send($provider, ['scheduled_start' => '2026-11-20 15:00'])->assertStatus(409);
        // Another day is fine
        $this->send($provider, ['scheduled_start' => '2026-11-21 15:00'])->assertCreated();

        // Time off can't swallow a confirmed booking
        $this->signIn($provider->owner);
        $period = $this->getJson('/api/v1/me/provider/schedule')->json('data.time_off.0.id');
        $this->deleteJson("/api/v1/me/provider/time-off/{$period}")->assertOk();
        $this->accept($provider, $id)->assertOk();
        $this->postJson('/api/v1/me/provider/time-off', ['starts_at' => '2026-11-20 09:00', 'ends_at' => '2026-11-20 11:00'])->assertStatus(409);
        // Nobody else's
        $this->signIn($this->provider()->owner);
        $this->deleteJson("/api/v1/me/provider/time-off/{$period}")->assertNotFound();
    }

    public function test_as_soon_as_possible_is_held_from_acceptance()
    {
        $provider = $this->provider();
        $id = $this->send($provider, ['timing' => 'asap', 'scheduled_start' => null, 'duration_minutes' => null])->assertCreated()
            ->assertJsonPath('data.preferred_time', 'As soon as possible')->json('data.id');

        $this->accept($provider, $id)->assertOk()->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.schedule.start', '2026-11-18T08:00:00+03:00')
            // The admin's default length: 2 hours
            ->assertJsonPath('data.schedule.end', '2026-11-18T10:00:00+03:00');
        $this->send($provider, ['scheduled_start' => '2026-11-18 09:00'])->assertStatus(409);
    }

    /* ---------------- Suggesting another time ---------------- */

    public function test_the_provider_suggests_a_time_and_the_customer_accepts_it()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->send($provider, [], $customer)->json('data.id');

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/propose", ['start' => '2026-11-21 14:00'])->assertOk()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.schedule.proposed_start', '2026-11-21T14:00:00+03:00');
        $this->assertDatabaseHas('user_notifications', ['title' => 'Time change proposed', 'user_id' => $customer->id]);

        // Nobody else answers it
        $this->signIn(User::factory()->create());
        $this->postJson("/api/v1/me/requests/{$id}/proposal", ['accept' => true])->assertNotFound();

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/proposal", ['accept' => true])->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.schedule.start', '2026-11-21T14:00:00+03:00')
            ->assertJsonPath('data.schedule.proposed_start', null)
            ->assertJsonPath('data.preferred_time', 'Sat 21 Nov, 2:00 PM');
        $this->postJson("/api/v1/me/requests/{$id}/proposal", ['accept' => true])->assertStatus(409)->assertJsonPath('code', 'no_proposal');
    }

    public function test_a_suggested_time_is_checked_again_when_accepted_and_can_be_turned_down()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->send($provider, [], $customer)->json('data.id');
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/propose", ['start' => '2026-11-21 14:00'])->assertOk();

        // Meanwhile the provider takes another job at that time
        $other = $this->send($provider, ['scheduled_start' => '2026-11-21 13:00'])->json('data.id');
        $this->accept($provider, $other)->assertOk();

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/proposal", ['accept' => true])->assertStatus(409)->assertJsonPath('code', 'time_taken');
        $this->postJson("/api/v1/me/requests/{$id}/proposal", ['accept' => false])->assertOk()->assertJsonPath('data.schedule.proposed_start', null)->assertJsonPath('data.status', 'open');
        // A time already booked can't be suggested either
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/propose", ['start' => '2026-11-21 14:00'])->assertStatus(409);
    }

    /* ---------------- Availability ---------------- */

    public function test_availability_preferences_decide_what_customers_can_send()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);

        // Busy now, but taking bookings for later
        $this->patchJson('/api/v1/me/provider/availability', ['accepts_immediate' => false])->assertOk()->assertJsonPath('data.work_status', 'busy');
        $this->send($provider, ['timing' => 'asap', 'scheduled_start' => null])->assertStatus(422)->assertJsonPath('code', 'provider_not_immediate');
        $this->send($provider)->assertCreated();
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.work_status', 'busy')->assertJsonPath('data.accepts_bookings', true);

        // Paused: nothing new, but still on the profile
        $this->signIn($provider->owner);
        $this->patchJson('/api/v1/me/provider/availability', ['accepts_bookings' => false])->assertOk()->assertJsonPath('data.work_status', 'paused');
        $this->assertSame('unavailable', $provider->fresh()->availability_status);
        $this->send($provider, ['scheduled_start' => '2026-11-22 10:00'])->assertStatus(422)->assertJsonPath('code', 'provider_paused');
        $this->getJson("/api/v1/providers/{$provider->id}")->assertOk()->assertJsonPath('data.work_status', 'paused');
    }

    public function test_a_job_in_progress_shows_as_busy_while_still_taking_bookings()
    {
        $provider = $this->provider();
        $id = $this->send($provider, ['timing' => 'asap', 'scheduled_start' => null])->json('data.id');
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.work_status', 'available');

        $this->accept($provider, $id)->assertOk();
        $this->postJson("/api/v1/me/received-requests/{$id}/start")->assertOk();
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.work_status', 'busy');
        // ...and in the catalogue (worked out for the whole list at once)
        $listed = collect($this->getJson('/api/v1/catalog')->json('data.providers'))->firstWhere('id', $provider->id);
        $this->assertSame('busy', $listed['work_status']);
        // They can still be booked for later
        $this->send($provider, ['scheduled_start' => '2026-11-21 10:00'])->assertCreated();
    }

    /* ---------------- Exact job location ---------------- */

    public function test_the_exact_location_is_private_until_the_provider_accepts()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->send($provider, [
            'latitude' => 0.354612, 'longitude' => 32.616013, 'location_accuracy' => 12.4,
            'landmark' => 'Blue gate opposite the church', 'directions' => 'Second house on the left, ring twice',
        ], $customer)->assertCreated()->json('data.id');

        // The customer sees all of it
        $this->signIn($customer);
        $mine = collect($this->getJson('/api/v1/me/requests')->json('data'))->firstWhere('id', $id);
        $this->assertSame(0.354612, $mine['location']['latitude']);
        $this->assertSame(12, $mine['location']['accuracy_m']);
        $this->assertSame('Second house on the left, ring twice', $mine['location']['directions']);

        // The provider: area and landmark only, until they accept
        $this->signIn($provider->owner);
        $before = collect($this->getJson('/api/v1/me/received-requests')->json('data'))->firstWhere('id', $id)['location'];
        $this->assertSame(['area_name' => 'Ntinda', 'landmark' => 'Blue gate opposite the church', 'has_pin' => true, 'directions' => null, 'latitude' => null, 'longitude' => null, 'accuracy_m' => null], $before);
        $after = $this->accept($provider, $id)->json('data.location');
        $this->assertSame(32.616013, $after['longitude']);
        $this->assertSame('Second house on the left, ring twice', $after['directions']);

        // Nobody else: not another provider, not in public listings
        $this->signIn($this->provider()->owner);
        $this->assertNull(collect($this->getJson('/api/v1/me/received-requests')->json('data'))->firstWhere('id', $id));
        $this->assertStringNotContainsString('0.354612', json_encode($this->getJson('/api/v1/catalog')->json()));
        $this->assertStringNotContainsString('0.354612', json_encode($this->getJson("/api/v1/jobs/{$id}")->json()));
    }

    public function test_a_location_outside_uganda_or_half_given_is_refused()
    {
        $provider = $this->provider();
        $this->send($provider, ['latitude' => 51.5, 'longitude' => 0.1])->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude']);
        $this->send($provider, ['latitude' => 0.35])->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    /* ---------------- The request in the chat ---------------- */

    public function test_a_request_appears_once_in_the_chat_and_shows_its_current_status()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $token = '6f1c2a8e-3b7d-4a5e-9c1f-2d3e4f5a6b7c';
        $id = $this->send($provider, ['client_token' => $token, 'budget' => 50000, 'latitude' => 0.3546, 'longitude' => 32.616], $customer)->json('data.id');
        // The same send again (a retry over a bad connection): no second request, no second card
        $this->send($provider, ['client_token' => $token, 'budget' => 50000], $customer)->assertOk();
        $this->assertSame(1, ServiceRequest::count());
        $this->assertSame(1, Message::where('kind', 'request')->count());

        // The provider sees it in their chat with the customer, without the exact point yet
        $this->signIn($provider->owner);
        $chat = $this->getJson('/api/v1/me/conversations')->assertOk()->json('data.0');
        $card = $this->getJson("/api/v1/me/conversations/{$chat['id']}")->json('data.messages.0');
        $this->assertSame('request', $card['kind']);
        $this->assertSame($id, $card['request']['id']);
        $this->assertSame('WM-R'.str_pad((string) $id, 5, '0', STR_PAD_LEFT), $card['request']['reference']);
        $this->assertSame(50000, $card['request']['budget']);
        $this->assertSame('open', $card['request']['status']);
        $this->assertNull($card['request']['location']['latitude']);

        // Accepted: the same card shows it, with the location
        $this->accept($provider, $id)->assertOk();
        $card = $this->getJson("/api/v1/me/conversations/{$chat['id']}")->json('data.messages.0');
        $this->assertSame('scheduled', $card['request']['status']);
        $this->assertSame(0.3546, $card['request']['location']['latitude']);

        // The customer sees the same card; outsiders don't see the chat
        $this->signIn($customer);
        $this->assertSame('scheduled', $this->getJson("/api/v1/me/conversations/{$chat['id']}")->json('data.messages.0.request.status'));
        $this->signIn(User::factory()->create());
        $this->getJson("/api/v1/me/conversations/{$chat['id']}")->assertNotFound();
    }

    /* ---------------- No time given ---------------- */

    public function test_a_request_or_job_without_a_time_is_confirmed_with_the_time_to_be_arranged()
    {
        $provider = $this->provider();
        // An older app sends only words for the time; two of them for the same provider
        $first = $this->send($provider, ['timing' => null, 'scheduled_start' => null, 'duration_minutes' => null, 'preferred_time' => 'Tomorrow'])->assertCreated()->json('data.id');
        $second = $this->send($provider, ['timing' => null, 'scheduled_start' => null, 'duration_minutes' => null, 'preferred_time' => 'Next week'])->assertCreated()->json('data.id');

        // Both can be accepted: nothing is reserved and no time is made up
        $this->accept($provider, $first)->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.schedule.start', null);
        $this->accept($provider, $second)->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertDatabaseHas('user_notifications', ['title' => 'Request accepted']);
        // A real booking at any time is still possible...
        $timed = $this->send($provider, ['scheduled_start' => '2026-11-20 10:00'])->json('data.id');
        $this->accept($provider, $timed)->assertOk();
        // ...and a time suggested for an open-ended one is checked against it
        $this->postJson("/api/v1/me/received-requests/{$first}/propose", ['start' => '2026-11-20 11:00'])->assertStatus(409)->assertJsonPath('code', 'time_taken');
        $this->postJson("/api/v1/me/received-requests/{$first}/propose", ['start' => '2026-11-20 14:00'])->assertOk();
        // Finishing works without a time ever being set
        $this->postJson("/api/v1/me/received-requests/{$second}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_hiring_for_a_flexible_job_confirms_it_without_inventing_a_time()
    {
        $provider = $this->provider();
        $customer = $this->signIn(User::factory()->create());
        $job = $this->postJson('/api/v1/jobs', ['category' => 'plumbing', 'title' => 'Fix bathroom pipes', 'area' => 'ntinda', 'workers_needed' => 1])->assertCreated()->json('data.id');
        $this->signIn($provider->owner);
        $application = $this->postJson("/api/v1/jobs/{$job}/applications", ['message' => 'I can do this plumbing job well and on time.', 'start_time' => 'This week'])->json('data.id');

        $this->signIn($customer);
        $this->postJson("/api/v1/me/jobs/{$job}/applications/{$application}/hire")->assertOk()
            ->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.schedule.start', null);
        // It's a new job, so it shows in their chat
        $this->assertSame(1, Message::where('service_request_id', $job)->count());
        // And the provider's day is still free for a timed booking
        $this->accept($provider, $this->send($provider, ['timing' => 'asap', 'scheduled_start' => null])->json('data.id'))->assertOk();
    }

    /* ---------------- Hiring for a posted job with a time ---------------- */

    public function test_hiring_for_a_dated_job_books_the_provider_if_free()
    {
        $provider = $this->provider();
        // The provider already has 10:00–12:00 on Friday
        $booked = $this->send($provider)->json('data.id');
        $this->accept($provider, $booked)->assertOk();

        $customer = $this->signIn(User::factory()->create());
        $post = function ($start) {
            return $this->postJson('/api/v1/jobs', [
                'category' => 'plumbing', 'title' => 'Fix bathroom pipes', 'area' => 'ntinda', 'workers_needed' => 1,
                'timing' => 'scheduled', 'scheduled_start' => $start, 'duration_minutes' => 60,
            ])->assertCreated()->json('data.id');
        };
        $clash = $post('2026-11-20 11:00');
        $free = $post('2026-11-20 14:00');

        $apply = function ($job) use ($provider) {
            $this->signIn($provider->owner);

            return $this->postJson("/api/v1/jobs/{$job}/applications", ['message' => 'I can do this plumbing job well and on time.', 'start_time' => 'Friday afternoon'])->assertCreated()->json('data.id');
        };
        $a = $apply($clash);
        $b = $apply($free);

        $this->signIn($customer);
        $this->postJson("/api/v1/me/jobs/{$clash}/applications/{$a}/hire")->assertStatus(409)->assertJsonPath('code', 'time_taken');
        $this->postJson("/api/v1/me/jobs/{$free}/applications/{$b}/hire")->assertOk()->assertJsonPath('data.status', 'scheduled');
        // The hired job now shows in their chat too
        $this->assertSame(1, Message::where('service_request_id', $free)->count());
    }
}
