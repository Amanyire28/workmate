<?php

namespace Tests\Feature\Api;

use App\Models\Area;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ProviderApplications;
use Carbon\Carbon;

/**
 * The owner's rule: requests made before the booking system stay exactly as
 * they are and keep their original workflow; the booking system applies to
 * requests made from the release on, told apart by booking_version.
 */
class LegacyBookingCompatibilityTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 18 November 2026, 08:00 in Kampala
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

    /**
     * A request as the code before this release made it: no booking_version
     * (the database default marks it legacy), a free-text time, no schedule.
     */
    private function legacy(ProviderProfile $provider, User $customer, string $status, bool $posted = false): ServiceRequest
    {
        $r = new ServiceRequest([
            'category_id' => Category::where('slug', 'plumbing')->value('id'),
            'title' => 'Plumbing request', 'description' => 'Old request from before bookings.',
            'area_id' => Area::where('slug', 'ntinda')->value('id'), 'area_name' => 'Ntinda',
            'preferred_time' => 'This week',
        ]);
        $r->forceFill([
            'customer_id' => $customer->id,
            'provider_profile_id' => $posted ? null : $provider->id,
            'is_posted_job' => $posted,
            'status' => $status,
            'responded_at' => $status === ServiceRequest::STATUS_OPEN ? null : now()->subDays(3),
            'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(3),
        ])->save();

        return $r->fresh();
    }

    private function snapshot(ServiceRequest $r): array
    {
        return ServiceRequest::withTrashed()->find($r->id)->getAttributes();
    }

    private function sendNew(ProviderProfile $provider, array $extra = [])
    {
        $this->signIn(User::factory()->create());

        return $this->postJson("/api/v1/providers/{$provider->id}/requests", $extra + [
            'category' => 'plumbing', 'description' => 'New request after the release.', 'area' => 'ntinda',
            'timing' => 'scheduled', 'scheduled_start' => '2026-11-20 10:00', 'duration_minutes' => 120,
        ]);
    }

    public function test_older_requests_are_marked_legacy_without_being_rewritten_and_new_ones_use_the_booking_system()
    {
        $provider = $this->provider();
        $old = $this->legacy($provider, User::factory()->create(), ServiceRequest::STATUS_OPEN);
        $this->assertSame(ServiceRequest::BOOKING_LEGACY, $old->booking_version);
        $this->assertNull($old->timing);
        $this->assertNull($old->scheduled_start_at);

        $id = $this->sendNew($provider)->assertCreated()->assertJsonPath('data.is_legacy', false)->json('data.id');
        $this->assertSame(ServiceRequest::BOOKING_V2, ServiceRequest::find($id)->booking_version);
        // Even a new request without a time (an older app) is on the booking system
        $plain = $this->sendNew($provider, ['timing' => null, 'scheduled_start' => null, 'preferred_time' => 'Tomorrow'])->assertCreated()->json('data.id');
        $this->assertSame(ServiceRequest::BOOKING_V2, ServiceRequest::find($plain)->booking_version);
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$plain}/accept")->assertOk()->assertJsonPath('data.status', 'scheduled');
    }

    public function test_pending_active_completed_and_cancelled_legacy_requests_are_untouched_by_new_activity()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $legacy = [
            'open' => $this->legacy($provider, $customer, ServiceRequest::STATUS_OPEN),
            'completed' => $this->legacy($provider, $customer, ServiceRequest::STATUS_COMPLETED),
            'cancelled' => $this->legacy($provider, $customer, ServiceRequest::STATUS_CANCELLED),
        ];
        $before = array_map([$this, 'snapshot'], $legacy);

        // Plenty of new-system activity for the same provider and customer
        $id = $this->sendNew($provider)->json('data.id');
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/accept")->assertOk();
        $this->patchJson("/api/v1/me/provider/availability", ['accepts_immediate' => false])->assertOk();
        $this->postJson('/api/v1/me/provider/time-off', ['starts_at' => '2026-11-25 00:00', 'ends_at' => '2026-11-25 23:59'])->assertCreated();
        $this->getJson('/api/v1/me/received-requests')->assertOk();
        $this->signIn($customer);
        $this->getJson('/api/v1/me/requests')->assertOk();

        foreach ($legacy as $key => $r) {
            $this->assertSame($before[$key], $this->snapshot($r), "The legacy {$key} request changed");
        }
        // And no chat cards were made for them
        $this->assertSame(0, Message::whereIn('service_request_id', collect($legacy)->pluck('id'))->count());
    }

    public function test_legacy_requests_keep_their_original_workflow_and_none_of_the_new_steps()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $pending = $this->legacy($provider, $customer, ServiceRequest::STATUS_OPEN);
        $active = $this->legacy($provider, $customer, ServiceRequest::STATUS_IN_PROGRESS);

        $this->signIn($provider->owner);
        $listed = collect($this->getJson('/api/v1/me/received-requests')->json('data'))->keyBy('id');
        $this->assertTrue($listed[$pending->id]['is_legacy']);
        $this->assertSame('This week', $listed[$active->id]['preferred_time']);

        // New steps are refused for them...
        $this->postJson("/api/v1/me/received-requests/{$pending->id}/propose", ['start' => '2026-11-21 10:00'])->assertStatus(409)->assertJsonPath('code', 'legacy_request');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$pending->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'legacy_request');
        $this->postJson("/api/v1/me/requests/{$active->id}/proposal", ['accept' => true])->assertStatus(409)->assertJsonPath('code', 'legacy_request');

        // ...and the original ones work as before: accept → in progress (no times added), done
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$pending->id}/accept")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertNull($pending->fresh()->scheduled_start_at);
        $this->assertNull($pending->fresh()->scheduled_end_at);
        $this->assertNull($pending->fresh()->duration_minutes);
        $this->postJson("/api/v1/me/received-requests/{$active->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertNull($active->fresh()->scheduled_end_at);
    }

    public function test_legacy_work_in_progress_shows_the_provider_as_busy()
    {
        $provider = $this->provider();
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.work_status', 'available');

        $active = $this->legacy($provider, User::factory()->create(), ServiceRequest::STATUS_IN_PROGRESS);
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.work_status', 'busy');
        $listed = collect($this->getJson('/api/v1/catalog')->json('data.providers'))->firstWhere('id', $provider->id);
        $this->assertSame('busy', $listed['work_status']);

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$active->id}/complete")->assertOk();
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.work_status', 'available');
    }

    public function test_a_legacy_job_in_progress_holds_today_but_not_later_days()
    {
        $provider = $this->provider();
        $active = $this->legacy($provider, User::factory()->create(), ServiceRequest::STATUS_IN_PROGRESS);

        // Customers can still send requests for today or later; the provider reviews them
        $today = $this->sendNew($provider, ['scheduled_start' => '2026-11-18 15:00'])->assertCreated()->json('data.id');
        $now = $this->sendNew($provider, ['timing' => 'asap', 'scheduled_start' => null])->assertCreated()->json('data.id');
        $later = $this->sendNew($provider, ['scheduled_start' => '2026-11-19 09:00'])->assertCreated()->json('data.id');

        // Confirming anything before the end of today waits until the older job is done
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$now}/accept")->assertStatus(409)->assertJsonPath('code', 'earlier_job_in_progress');
        $this->postJson("/api/v1/me/received-requests/{$today}/accept")->assertStatus(409)->assertJsonPath('code', 'earlier_job_in_progress');
        $this->postJson("/api/v1/me/received-requests/{$later}/accept")->assertOk()->assertJsonPath('data.status', 'scheduled');
        // No end was invented for the older job
        $this->assertNull($active->fresh()->scheduled_end_at);

        // Once it's marked done, today is free again
        $this->postJson("/api/v1/me/received-requests/{$active->id}/complete")->assertOk();
        $this->postJson("/api/v1/me/received-requests/{$today}/accept")->assertOk();
    }

    public function test_new_bookings_cant_overlap_confirmed_ones_alongside_legacy_work()
    {
        $provider = $this->provider();
        $this->legacy($provider, User::factory()->create(), ServiceRequest::STATUS_OPEN);
        $a = $this->sendNew($provider, ['scheduled_start' => '2026-11-20 10:00'])->json('data.id');
        $b = $this->sendNew($provider, ['scheduled_start' => '2026-11-20 11:00'])->json('data.id');

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$a}/accept")->assertOk();
        $this->postJson("/api/v1/me/received-requests/{$b}/accept")->assertStatus(409)->assertJsonPath('code', 'time_taken');
    }

    public function test_legacy_chats_stay_as_they_were()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $old = $this->legacy($provider, $customer, ServiceRequest::STATUS_IN_PROGRESS);
        $chat = new Conversation();
        $chat->forceFill(['customer_id' => $customer->id, 'provider_profile_id' => $provider->id, 'last_message_at' => now()->subDays(2)])->save();
        foreach (['Hello, are you free?', 'Yes, tomorrow.'] as $i => $text) {
            $m = new Message(['body' => $text]);
            $m->forceFill(['conversation_id' => $chat->id, 'sender_id' => $i ? $provider->owner_id : $customer->id, 'created_at' => now()->subDays(2)->addMinutes($i)])->save();
        }
        $before = Message::where('conversation_id', $chat->id)->orderBy('id')->get()->map->getAttributes()->all();

        // The customer reads the chat; the old job is finished; a new request comes in
        $this->signIn($customer);
        $messages = $this->getJson("/api/v1/me/conversations/{$chat->id}")->assertOk()->json('data.messages');
        $this->assertSame(['text', 'text'], array_column($messages, 'kind'));
        $this->assertSame(['Hello, are you free?', 'Yes, tomorrow.'], array_column($messages, 'body'));
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$old->id}/complete")->assertOk();
        $new = $this->sendNew($provider)->json('data.id');

        // Old messages unchanged (only read receipts may be set by opening the chat); no card for the old job
        $after = Message::where('conversation_id', $chat->id)->whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->map->getAttributes()->all();
        foreach ($before as $i => $row) {
            unset($row['read_at'], $after[$i]['read_at']);
            $this->assertSame($row, $after[$i]);
        }
        $this->assertSame(0, Message::where('service_request_id', $old->id)->count());
        $this->assertSame(1, Message::where('service_request_id', $new)->count());
    }

    public function test_hiring_for_an_older_posted_job_keeps_the_original_workflow_and_adds_no_card()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $job = $this->legacy($provider, $customer, ServiceRequest::STATUS_OPEN, true);
        $quote = new Quotation();
        $quote->forceFill(['service_request_id' => $job->id, 'provider_profile_id' => $provider->id, 'message' => 'I can do it this week for a fair price.', 'status' => Quotation::STATUS_PENDING])->save();

        $this->signIn($customer);
        $this->postJson("/api/v1/me/jobs/{$job->id}/applications/{$quote->id}/hire")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertNull($job->fresh()->scheduled_start_at);
        $this->assertSame(0, Message::where('service_request_id', $job->id)->count());
    }
}
