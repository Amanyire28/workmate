<?php

namespace Tests\Feature\Api;

use App\Models\DocumentVerification;
use App\Models\ProviderApplication;
use App\Models\ProviderProfile;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\DocumentVerifications;
use App\Services\ProviderApplications;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;

class EventNotificationTest extends ApiTestCase
{
    /** @var array<int, array{endpoint: string, payload: array}> notifications pushed so far */
    private $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.webpush.public_key' => 'test-public-key', 'services.webpush.private_key' => 'test-private-key']);

        $queued = [];
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->andReturnUsing(function ($subscription, $payload) use (&$queued) {
            $queued[] = [$subscription->getEndpoint(), json_decode($payload, true)];
        });
        $client->shouldReceive('flush')->andReturnUsing(function () use (&$queued) {
            $batch = $queued;
            $queued = [];

            return (function () use ($batch) {
                foreach ($batch as [$endpoint, $payload]) {
                    $this->sent[] = ['endpoint' => $endpoint, 'payload' => $payload];
                    yield new MessageSentReport(new PsrRequest('POST', $endpoint), new PsrResponse(201));
                }
            })();
        });
        $this->app->instance(WebPush::class, $client);
    }

    /** A device with notifications on, for this account */
    private function device(User $user): string
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/device-'.$user->id;
        $subscription = new PushSubscription(['endpoint' => $endpoint, 'public_key' => 'BKey', 'auth_token' => 'auth', 'platform' => 'android']);
        $subscription->forceFill(['user_id' => $user->id, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint)])->save();

        return $endpoint;
    }

    /**
     * Each distinct notification pushed so far. In tests one application
     * handles every request, so after-response work repeats on later
     * requests; on a server each request is its own process.
     */
    private function sentOnce()
    {
        return collect($this->sent)->unique(function ($n) {
            return $n['endpoint'].json_encode($n['payload']);
        })->values();
    }

    /** Titles pushed to this account's device */
    private function titlesFor(User $user): array
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/device-'.$user->id;

        return $this->sentOnce()->where('endpoint', $endpoint)->pluck('payload.title')->values()->all();
    }

    /** An approved plumber whose owner has a device with notifications on */
    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create(['name' => 'Okello Mukasa']);
        $this->device($owner);
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function sendRequest(ProviderProfile $provider, User $customer): int
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow',
        ])->assertCreated()->json('data.id');
    }

    public function test_a_request_and_every_answer_reach_the_right_person()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['name' => 'Aisha Nakato']);
        $this->device($customer);

        $accepted = $this->sendRequest($provider, $customer);
        $declined = $this->sendRequest($provider, $customer);

        $this->assertSame(['You’re live on WorkMate', 'New request', 'New request'], $this->titlesFor($provider->owner));
        $this->assertSame([], $this->titlesFor($customer));
        $first = $this->sentOnce()->firstWhere('payload.title', 'New request');
        $this->assertSame('Aisha needs plumbing. Reply in WorkMate.', $first['payload']['body']);
        $this->assertSame('/activity?tab=received', $first['payload']['url']);
        // Requests stay on screen until dismissed; chat messages don't
        $this->assertTrue($first['payload']['important']);

        // Requests from before the booking system keep the original steps and messages;
        // new ones say "Booking confirmed" (BookingTest)
        \App\Models\ServiceRequest::whereKey([$accepted, $declined])->update(['booking_version' => \App\Models\ServiceRequest::BOOKING_LEGACY]);
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$accepted}/accept")->assertOk();
        $this->postJson("/api/v1/me/received-requests/{$declined}/decline", ['reason' => 'Fully booked this week'])->assertOk();
        $this->postJson("/api/v1/me/received-requests/{$accepted}/complete")->assertOk();

        $this->assertSame(['Request accepted', 'Request declined', 'Your job is done'], $this->titlesFor($customer));
        // The decline reason stays in WorkMate, not on a locked screen
        $this->assertStringNotContainsString('Fully booked', json_encode($this->sent));
    }

    public function test_messages_notify_the_other_side_without_their_text()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['name' => 'Aisha Nakato']);
        $this->device($customer);

        $this->signIn($customer);
        $chat = $this->postJson("/api/v1/me/conversations/provider/{$provider->id}")->assertOk()->json('data.id');
        $this->postJson("/api/v1/me/conversations/{$chat}/messages", ['body' => 'My secret gate code is 4321'])->assertCreated();

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/conversations/{$chat}/messages", ['body' => 'Thanks, see you at 9'])->assertCreated();

        $this->assertSame(['You’re live on WorkMate', 'New message'], $this->titlesFor($provider->owner));
        $this->assertSame(['New message'], $this->titlesFor($customer));
        $toProvider = $this->sentOnce()->where('endpoint', 'https://fcm.googleapis.com/fcm/send/device-'.$provider->owner_id)->firstWhere('payload.title', 'New message');
        $this->assertSame('Aisha sent you a message.', $toProvider['payload']['body']);
        $this->assertSame('/messages/c/'.$chat, $toProvider['payload']['url']);
        $this->assertArrayNotHasKey('important', $toProvider['payload']);
        $this->assertStringNotContainsString('4321', json_encode($this->sent));
    }

    public function test_decisions_on_an_application_and_documents_reach_the_provider()
    {
        $applicant = User::factory()->create();
        $this->device($applicant);
        $service = app(ProviderApplications::class);
        $service->saveDetails($applicant, ['type' => 'individual', 'display_name' => 'Nambi Cleaning', 'professions' => [['profession' => 'cleaner']], 'base_area' => 'ntinda']);
        $reviewer = $this->userWithRoles(['provider_review']);
        $service->requestMoreInformation($service->submit($applicant), $reviewer, 'Add a photo of your work');
        $this->app->terminate();
        $this->assertSame(['Changes needed'], $this->titlesFor($applicant));

        $profile = $applicant->providerProfile()->first();
        $verification = new DocumentVerification();
        $verification->forceFill(['provider_profile_id' => $profile->id, 'status' => DocumentVerification::STATUS_PENDING_REVIEW, 'submitted_at' => now()])->save();
        app(DocumentVerifications::class)->requestDocument($verification, $reviewer, 'Driving permit', null);
        $this->app->terminate();

        $this->assertSame(['Changes needed', 'Another document needed'], $this->titlesFor($applicant));
        $this->assertSame('/start-earning', $this->sentOnce()->last()['payload']['url']);
    }

    public function test_nothing_is_sent_when_push_is_not_set_up_or_nobody_turned_it_on()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $this->sendRequest($provider, $customer);
        $before = $this->sentOnce()->count();

        // Push switched off on the server: nothing more goes out
        config(['services.webpush.private_key' => null]);
        $this->sendRequest($provider, $customer);
        $this->assertSame($before, $this->sentOnce()->count());

        // The customer never turned notifications on
        $this->assertSame([], $this->titlesFor($customer));

        $this->assertSame(2, \App\Models\ServiceRequest::count());
    }
}
