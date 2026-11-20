<?php

namespace Tests\Feature\Api;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushNotifications;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;

/**
 * Push notifications and signing out: a device that signs out stops
 * receiving that account's notifications (its subscription is removed, and
 * only that one), nobody can remove or take over someone else's device by
 * signing out, and pushes sent afterwards don't reach it.
 */
class PushPrivacyTest extends ApiTestCase
{
    private const PHONE = 'https://fcm.googleapis.com/fcm/send/phone-of-a';
    private const LAPTOP = 'https://updates.push.services.mozilla.com/wpush/v2/laptop-of-a';
    private const OTHER = 'https://web.push.apple.com/device-of-b';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.webpush.public_key' => 'test-public-key', 'services.webpush.private_key' => 'test-private-key']);
    }

    /* ---------------- Helpers ---------------- */

    private function person(string $email): User
    {
        return User::factory()->create(['email' => $email]);   // password: "password"
    }

    /** @var array<string, string> session cookie (as the browser holds it) => session id */
    private $sessions = [];

    /**
     * Signs in through the website and returns the session cookie exactly as
     * the browser received it (encrypted), to send back like a browser does.
     */
    private function signInOnDevice(User $user): string
    {
        $response = $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $cookie = collect($response->headers->getCookies())->first(function ($c) {
            return $c->getName() === config('session.cookie');
        });
        $this->sessions[$cookie->getValue()] = $this->app['session']->getId();

        return $cookie->getValue();
    }

    private function onDevice(string $cookie)
    {
        return $this->fromWebsite()->withUnencryptedCookie(config('session.cookie'), $cookie);
    }

    private function subscription(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyOfTheBrowser', 'auth' => 'authSecret'], 'platform' => 'android'];
    }

    /** Another of the user's devices, saved in a different session */
    private function otherDevice(User $user, string $endpoint): void
    {
        app(PushNotifications::class)->subscribe($user, $this->subscription($endpoint), 'a-different-session-'.$endpoint);
    }

    private function signOutEverywhereInThisTest(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    }

    /** Which devices a notification to these accounts actually goes to */
    private function deliveredTo(array $userIds): array
    {
        $sent = [];
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->andReturnUsing(function ($subscription) use (&$sent) {
            $sent[] = $subscription->getEndpoint();
        });
        $client->shouldReceive('flush')->andReturnUsing(function () use (&$sent) {
            return (function () use ($sent) {
                foreach ($sent as $endpoint) {
                    yield new MessageSentReport(new PsrRequest('POST', $endpoint), new PsrResponse(201));
                }
            })();
        });
        app(PushNotifications::class)->deliverToUsers($userIds, [
            'title' => 'New booking request', 'body' => 'You have a new booking request.', 'url' => '/activity', 'tag' => 'test',
        ], $client);
        sort($sent);

        return $sent;
    }

    /* ---------------- Signing out ---------------- */

    public function test_signing_out_removes_this_devices_subscription_and_keeps_the_others()
    {
        // Works without any security settings (APP_PUBLIC_URL unset)
        $this->assertSame('', config('security.public_url'));
        $a = $this->person('a@example.test');
        $session = $this->signInOnDevice($a);
        $this->onDevice($session)->postJson('/api/v1/me/push-subscriptions', $this->subscription(self::PHONE))->assertCreated();
        $this->otherDevice($a, self::LAPTOP);

        $this->onDevice($session)->postJson('/api/v1/auth/logout', ['push_endpoint' => self::PHONE])->assertNoContent();

        $this->assertSame([self::LAPTOP], PushSubscription::where('user_id', $a->id)->pluck('endpoint')->all());
    }

    public function test_signing_out_finds_the_device_by_its_session_when_the_page_cannot_say()
    {
        $a = $this->person('a@example.test');
        $push = app(PushNotifications::class);
        $push->subscribe($a, $this->subscription(self::PHONE), 'session-on-the-phone');
        $this->otherDevice($a, self::LAPTOP);

        // No push address known (e.g. the page couldn't reach its service worker): the session decides
        $this->assertSame(1, $push->forgetDevice($a, null, 'session-on-the-phone'));
        $this->assertSame([self::LAPTOP], PushSubscription::where('user_id', $a->id)->pluck('endpoint')->all());
        // Nothing to go on: nothing removed
        $this->assertSame(0, $push->forgetDevice($a, null, null));
    }

    public function test_signing_out_hands_the_live_session_to_the_device_clean_up()
    {
        $a = $this->person('a@example.test');
        $session = $this->signInOnDevice($a);
        $seen = [];
        $spy = Mockery::mock(PushNotifications::class);
        $spy->shouldReceive('forgetDevice')->once()->andReturnUsing(function ($user, $endpoint, $sessionId) use (&$seen) {
            $seen = [$user->id, $endpoint, $sessionId];

            return 1;
        });
        $this->app->instance(PushNotifications::class, $spy);

        $this->onDevice($session)->postJson('/api/v1/auth/logout', ['push_endpoint' => self::PHONE])->assertNoContent();

        $this->assertSame($a->id, $seen[0]);
        $this->assertSame(self::PHONE, $seen[1]);
        $this->assertIsString($seen[2]);
        $this->assertNotSame('', $seen[2]);
    }

    public function test_after_signing_out_later_notifications_no_longer_reach_that_device()
    {
        $a = $this->person('a@example.test');
        $session = $this->signInOnDevice($a);
        $this->onDevice($session)->postJson('/api/v1/me/push-subscriptions', $this->subscription(self::PHONE))->assertCreated();
        $this->otherDevice($a, self::LAPTOP);
        $this->assertSame([self::PHONE, self::LAPTOP], $this->deliveredTo([$a->id]));

        $this->onDevice($session)->postJson('/api/v1/auth/logout', ['push_endpoint' => self::PHONE])->assertNoContent();

        // A booking request arriving after they signed out goes only to the device still signed in
        $this->assertSame([self::LAPTOP], $this->deliveredTo([$a->id]));
    }

    public function test_signing_out_cannot_remove_someone_elses_device()
    {
        $b = $this->person('b@example.test');
        $this->otherDevice($b, self::OTHER);

        $a = $this->person('a@example.test');
        $session = $this->signInOnDevice($a);
        $this->onDevice($session)->postJson('/api/v1/auth/logout', ['push_endpoint' => self::OTHER])->assertNoContent();

        $this->assertSame(1, PushSubscription::where('user_id', $b->id)->count());
        $this->assertSame([self::OTHER], $this->deliveredTo([$b->id]));
    }

    public function test_signing_out_while_signed_out_removes_nothing()
    {
        $a = $this->person('a@example.test');
        $this->otherDevice($a, self::PHONE);

        $this->fromWebsite()->postJson('/api/v1/auth/logout', ['push_endpoint' => self::PHONE])->assertNoContent();

        $this->assertSame(1, PushSubscription::count());
    }

    public function test_the_push_address_sent_when_signing_out_is_checked()
    {
        $a = $this->person('a@example.test');
        $session = $this->signInOnDevice($a);
        $this->onDevice($session)->postJson('/api/v1/auth/logout', ['push_endpoint' => str_repeat('x', 1001)])->assertStatus(422);
        $this->onDevice($session)->postJson('/api/v1/auth/logout', ['push_endpoint' => ['not', 'a', 'string']])->assertStatus(422);
    }

    /* ---------------- Switching accounts on one device ---------------- */

    public function test_when_someone_else_signs_in_on_the_device_the_first_account_stops_reaching_it()
    {
        $a = $this->person('a@example.test');
        $b = $this->person('b@example.test');
        $push = app(PushNotifications::class);
        $push->subscribe($a, $this->subscription(self::PHONE), 'session-of-a');

        // A's session ran out without signing out; B signs in on the same phone and turns notifications on
        $push->subscribe($b, $this->subscription(self::PHONE), 'session-of-b');

        $this->assertSame([], $this->deliveredTo([$a->id]));
        $this->assertSame([self::PHONE], $this->deliveredTo([$b->id]));

        // A's old session can no longer remove (or reclaim) the phone, now B's
        $this->assertSame(0, $push->forgetDevice($a, self::PHONE, 'session-of-a'));
        $this->assertSame([self::PHONE], $this->deliveredTo([$b->id]));

        // B signing out doesn't hand the phone back to A
        $this->assertSame(1, $push->forgetDevice($b, self::PHONE, 'session-of-b'));
        $this->assertSame([], $this->deliveredTo([$a->id]));
        $this->assertSame([], $this->deliveredTo([$b->id]));
    }

    /* ---------------- Changing subscriptions ---------------- */

    public function test_subscriptions_can_only_be_changed_when_signed_in()
    {
        $a = $this->person('a@example.test');
        $this->otherDevice($a, self::PHONE);

        $this->fromWebsite()->postJson('/api/v1/me/push-subscriptions', $this->subscription(self::LAPTOP))->assertUnauthorized();
        $this->fromWebsite()->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => self::PHONE])->assertUnauthorized();

        $this->assertSame([self::PHONE], PushSubscription::pluck('endpoint')->all());
    }

    public function test_turning_off_someone_elses_device_does_nothing()
    {
        $b = $this->person('b@example.test');
        $this->otherDevice($b, self::OTHER);

        $a = $this->person('a@example.test');
        $session = $this->signInOnDevice($a);
        $this->onDevice($session)->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => self::OTHER])->assertOk();

        $this->assertSame([self::OTHER], $this->deliveredTo([$b->id]));
    }

    public function test_the_session_is_stored_only_as_a_hash()
    {
        $a = $this->person('a@example.test');
        app(PushNotifications::class)->subscribe($a, $this->subscription(self::PHONE), 'the-secret-session-id');

        $row = PushSubscription::first();
        $this->assertSame(hash('sha256', 'the-secret-session-id'), $row->session_hash);
        $this->assertStringNotContainsString('the-secret-session-id', json_encode($row->getAttributes()));
        // And never in what the API sends back
        $this->assertArrayNotHasKey('session_hash', $row->toArray());
    }


    /* ---------------- Older subscriptions of signed-out accounts ---------------- */

    private function sessionFor(User $user): void
    {
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'session-'.$user->id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);
    }

    public function test_subscriptions_of_accounts_signed_in_nowhere_can_be_removed_safely()
    {
        config(['session.driver' => 'database']);
        $signedOut = $this->person('gone@example.test');
        $stillIn = $this->person('here@example.test');
        app(PushNotifications::class)->subscribe($signedOut, $this->subscription(self::PHONE));   // an older row: no session recorded
        app(PushNotifications::class)->subscribe($stillIn, $this->subscription(self::LAPTOP));
        $this->sessionFor($stillIn);

        // Dry run by default: counts, deletes nothing
        $this->artisan('workmate:push-prune-signed-out')
            ->expectsOutput('Subscriptions of accounts signed in nowhere: 1 (on 1 accounts). Total subscriptions: 2.')
            ->assertExitCode(0);
        $this->assertSame(2, PushSubscription::count());

        $this->artisan('workmate:push-prune-signed-out', ['--apply' => true])->assertExitCode(0);
        $this->assertSame([], $this->deliveredTo([$signedOut->id]));
        $this->assertSame([self::LAPTOP], $this->deliveredTo([$stillIn->id]));
    }

    public function test_the_clean_up_refuses_when_sessions_are_not_in_the_database()
    {
        config(['session.driver' => 'file']);
        app(PushNotifications::class)->subscribe($this->person('a@example.test'), $this->subscription(self::PHONE));
        $this->artisan('workmate:push-prune-signed-out', ['--apply' => true])->assertExitCode(1);
        $this->assertSame(1, PushSubscription::count());
    }

}
