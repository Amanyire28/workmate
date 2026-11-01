<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\PushAnnouncement;
use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;

class PushNotificationTest extends ApiTestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-one';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.webpush.public_key' => 'test-public-key', 'services.webpush.private_key' => 'test-private-key']);
    }

    private function subscription(string $endpoint = self::ENDPOINT, array $extra = []): array
    {
        return $extra + ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyOfTheBrowser', 'auth' => 'authSecret'], 'platform' => 'android'];
    }

    private function subscribe(User $user, string $endpoint = self::ENDPOINT, string $platform = 'android'): void
    {
        $this->signIn($user);
        $this->postJson('/api/v1/me/push-subscriptions', $this->subscription($endpoint, ['platform' => $platform]))->assertCreated();
    }

    private function announcement(array $overrides = []): array
    {
        return $overrides + ['title' => 'New plumbers in Ntinda', 'body' => 'Five new plumbers joined this week.', 'url' => '/browse', 'reason' => 'Weekly update'];
    }

    /** A push client that accepts the given endpoints, reports some as gone, and fails the rest */
    private function fakePushClient(array $expired = [], array $failing = []): void
    {
        $queued = [];
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->andReturnUsing(function ($subscription, $payload) use (&$queued) {
            $queued[] = [$subscription->getEndpoint(), json_decode($payload, true)];
        });
        $client->shouldReceive('flush')->andReturnUsing(function () use (&$queued, $expired, $failing) {
            $batch = $queued;
            $queued = [];

            return (function () use ($batch, $expired, $failing) {
                foreach ($batch as [$endpoint, $payload]) {
                    $this->assertSame('New plumbers in Ntinda', $payload['title']);
                    $this->assertSame('/browse', $payload['url']);
                    $request = new PsrRequest('POST', $endpoint);
                    if (in_array($endpoint, $expired, true)) {
                        yield new MessageSentReport($request, new PsrResponse(410), false, 'Gone');
                    } elseif (in_array($endpoint, $failing, true)) {
                        yield new MessageSentReport($request, new PsrResponse(500), false, 'Server error');
                    } else {
                        yield new MessageSentReport($request, new PsrResponse(201));
                    }
                }
            })();
        });
        $this->app->instance(WebPush::class, $client);
    }

    public function test_the_public_key_is_only_offered_once_push_is_set_up()
    {
        $this->getJson('/api/v1/push/config')->assertOk()->assertJson(['data' => ['enabled' => true, 'public_key' => 'test-public-key']]);

        config(['services.webpush.private_key' => null]);
        $this->getJson('/api/v1/push/config')->assertOk()->assertJson(['data' => ['enabled' => false, 'public_key' => null]]);
    }

    public function test_a_signed_in_device_can_turn_notifications_on_and_off()
    {
        $this->postJson('/api/v1/me/push-subscriptions', $this->subscription())->assertUnauthorized();

        $user = User::factory()->create();
        $this->subscribe($user);
        $this->subscribe($user);   // turning it on again changes nothing

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($user->id, PushSubscription::first()->user_id);

        $this->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_subscriptions_must_be_real_push_addresses()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/v1/me/push-subscriptions', $this->subscription('http://insecure.example/push'))
            ->assertStatus(422)->assertJsonValidationErrors('endpoint');
        $this->postJson('/api/v1/me/push-subscriptions', ['endpoint' => self::ENDPOINT])
            ->assertStatus(422)->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
        $this->postJson('/api/v1/me/push-subscriptions', $this->subscription(self::ENDPOINT, ['platform' => 'fridge']))
            ->assertStatus(422)->assertJsonValidationErrors('platform');
    }

    public function test_one_person_cannot_turn_off_another_persons_device()
    {
        $owner = User::factory()->create();
        $this->subscribe($owner);

        $this->signIn(User::factory()->create());
        $this->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => self::ENDPOINT])->assertOk();

        $this->assertSame(1, PushSubscription::where('user_id', $owner->id)->count());
    }

    public function test_a_shared_device_follows_whoever_signs_in_on_it()
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->subscribe($first);
        $this->subscribe($second);

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($second->id, PushSubscription::first()->user_id);
    }

    public function test_only_administrators_allowed_to_send_can_send_announcements()
    {
        $this->subscribe(User::factory()->create());

        $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/admin/push-announcements', $this->announcement())->assertForbidden();

        // Support staff can see the screen (comms.view) but not send
        $this->signIn($this->userWithRoles(['support']));
        $this->postJson('/api/v1/admin/push-announcements', $this->announcement())->assertForbidden();

        $this->assertSame(0, PushAnnouncement::count());
    }

    public function test_an_announcement_goes_to_every_device_and_cleans_up_gone_ones()
    {
        $this->subscribe(User::factory()->create(), self::ENDPOINT);
        $this->subscribe(User::factory()->create(), 'https://web.push.apple.com/device-two', 'ios');
        $this->subscribe(User::factory()->create(), 'https://updates.push.services.mozilla.com/device-three', 'desktop');
        $this->fakePushClient(['https://web.push.apple.com/device-two'], ['https://updates.push.services.mozilla.com/device-three']);

        $admin = $this->signIn($this->userWithRoles(['super_admin']));
        $this->postJson('/api/v1/admin/push-announcements', $this->announcement())
            ->assertStatus(202)
            ->assertJson(['data' => ['status' => 'sending', 'recipients' => 3]]);

        // Sent once the response has gone back
        $announcement = PushAnnouncement::first();
        $this->assertSame('sent', $announcement->status);
        $this->assertSame(1, $announcement->delivered);
        $this->assertSame(1, $announcement->expired);
        $this->assertSame(1, $announcement->failed);
        $this->assertSame($admin->id, $announcement->sent_by);
        $this->assertNotNull($announcement->completed_at);

        $this->assertFalse(PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint('https://web.push.apple.com/device-two'))->exists());
        $this->assertNotNull(PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint(self::ENDPOINT))->value('last_success_at'));

        $log = AuditLog::where('action', 'comms.push_announcement_sent')->first();
        $this->assertSame('Weekly update', $log->reason);
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_announcements_only_open_workmate_pages()
    {
        $this->subscribe(User::factory()->create());
        $this->signIn($this->userWithRoles(['super_admin']));

        foreach (['https://example.com', '//example.com/page', 'browse', 'javascript:alert(1)'] as $url) {
            $this->postJson('/api/v1/admin/push-announcements', $this->announcement(['url' => $url]))
                ->assertStatus(422)->assertJsonValidationErrors('url');
        }
        $this->postJson('/api/v1/admin/push-announcements', $this->announcement(['title' => str_repeat('a', 61), 'reason' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['title', 'reason']);

        $this->assertSame(0, PushAnnouncement::count());
    }

    public function test_sending_explains_what_is_missing()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $this->postJson('/api/v1/admin/push-announcements', $this->announcement())
            ->assertStatus(422)->assertJson(['code' => 'no_subscribers']);

        config(['services.webpush.public_key' => null]);
        $this->postJson('/api/v1/admin/push-announcements', $this->announcement())
            ->assertStatus(503)->assertJson(['code' => 'push_not_configured']);
    }

    public function test_admin_data_shows_counts_but_never_device_addresses()
    {
        $this->subscribe(User::factory()->create(), self::ENDPOINT, 'android');
        $this->subscribe(User::factory()->create(), 'https://web.push.apple.com/device-two', 'ios');

        $this->signIn($this->userWithRoles(['support']));
        $response = $this->getJson('/api/v1/admin/data')->assertOk();
        $this->assertSame(['configured' => true, 'subscribers' => 2, 'byPlatform' => ['android' => 1, 'ios' => 1]], $response->json('data.push'));
        $this->assertStringNotContainsString('device-two', $response->getContent());
        $this->assertStringNotContainsString('authSecret', $response->getContent());

        $this->signIn($this->userWithRoles(['finance']));
        $this->assertNull($this->getJson('/api/v1/admin/data')->json('data.push'));
    }
}
