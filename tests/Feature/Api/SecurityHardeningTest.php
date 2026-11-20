<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Step 1 safety fixes: browser security headers, trusted hosts, email links
 * built only from the site's own address, CORS, email-header injection, and
 * the added rate limits.
 */
class SecurityHardeningTest extends ApiTestCase
{
    private const SITE = 'https://wm.resnetsystems.com';

    protected function tearDown(): void
    {
        // TrustHosts sets this on the Request class itself; don't let it leak into other tests
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    private function siteAddressIsSet(): void
    {
        config(['security.public_url' => self::SITE]);
    }

    /* ---------------- Security headers ---------------- */

    public function test_api_responses_and_pages_carry_the_security_headers()
    {
        foreach ([$this->getJson('/api/v1/health'), $this->get('/community')] as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=(), usb=()');
        }
    }

    public function test_the_content_security_policy_starts_in_report_only_mode()
    {
        $response = $this->get('/community');
        $response->assertHeaderMissing('Content-Security-Policy');
        $policy = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($policy);
        foreach ([
            "default-src 'self'", "script-src 'self'", "object-src 'none'", "frame-ancestors 'self'", "base-uri 'self'",
            'https://fonts.googleapis.com', 'https://fonts.gstatic.com', 'https://cdn.jsdelivr.net', 'report-uri /api/v1/csp-reports',
        ] as $part) {
            $this->assertStringContainsString($part, $policy);
        }
        // No script loophole
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $policy);
    }

    public function test_videos_from_r2_are_allowed_by_the_policy_when_storage_is_set_up()
    {
        config(['videos.r2.account_id' => 'acct123', 'videos.r2.endpoint' => null]);
        $policy = app(SecurityHeaders::class)->policy();
        $this->assertMatchesRegularExpression("#media-src 'self' blob: https://acct123\\.r2\\.cloudflarestorage\\.com#", $policy);
        $this->assertMatchesRegularExpression("#connect-src 'self' https://acct123\\.r2\\.cloudflarestorage\\.com#", $policy);
    }

    public function test_the_policy_can_be_enforced_or_switched_off()
    {
        config(['security.headers.csp' => 'enforce']);
        $this->get('/community')->assertHeader('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
        config(['security.headers.csp' => 'off']);
        $this->get('/community')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_hsts_is_only_sent_once_the_https_site_address_is_set()
    {
        $this->getJson('/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');
        config(['security.public_url' => 'http://insecure.example']);
        $this->getJson('/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');
        $this->siteAddressIsSet();
        $this->getJson('/api/v1/health')->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    }

    /* ---------------- Trusted hosts ---------------- */

    public function test_until_the_site_address_is_set_any_host_still_works()
    {
        $this->getJson('http://anything.example/api/v1/health')->assertOk();
    }

    public function test_once_set_requests_for_other_hosts_are_refused()
    {
        $this->siteAddressIsSet();
        $this->getJson(self::SITE.'/api/v1/health')->assertOk();
        $this->getJson('https://evil.example/api/v1/health')->assertStatus(400)->assertJsonPath('code', 'bad_host');
        // Pages too: refused, never a server error
        $this->assertContains($this->get('https://evil.example/community')->status(), [400, 404]);
    }

    public function test_extra_host_names_can_be_allowed()
    {
        $this->siteAddressIsSet();
        config(['security.extra_hosts' => ['www.wm.resnetsystems.com']]);
        $this->getJson('https://www.wm.resnetsystems.com/api/v1/health')->assertOk();
    }

    /* ---------------- Email links ---------------- */

    public function test_reset_links_use_the_site_address_even_if_the_request_names_another_host()
    {
        Notification::fake();
        $this->siteAddressIsSet();
        // As if host checking were off (e.g. on a developer's computer): the link still can't be redirected
        $this->app['env'] = 'local';
        $user = User::factory()->create(['email' => 'okello@example.com']);

        $this->postJson('https://evil.example/api/v1/auth/forgot-password', ['email' => 'okello@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;
            $this->assertStringStartsWith(self::SITE.'/reset-password?', $url);
            $this->assertStringNotContainsString('evil.example', $url);

            return true;
        });
        // And the generator is back to normal afterwards
        $this->assertStringNotContainsString(self::SITE, url('/x'));
    }

    public function test_email_confirmation_links_use_the_site_address()
    {
        $this->siteAddressIsSet();
        $this->app['env'] = 'local';
        $user = User::factory()->unverified()->create();
        $this->getJson('https://evil.example/api/v1/health');   // a request on another host

        $url = (new VerifyEmail())->toMail($user)->actionUrl;
        $this->assertStringStartsWith(self::SITE.'/api/v1/auth/verify-email/', $url);
    }

    public function test_without_a_site_address_links_are_built_as_before()
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'okello@example.com']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'okello@example.com'])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            return strpos($n->toMail($user)->actionUrl, 'http://localhost/reset-password?') === 0;
        });
    }

    /* ---------------- CORS ---------------- */

    public function test_other_websites_are_not_allowed_to_call_the_api()
    {
        $response = $this->call('OPTIONS', '/api/v1/health', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame([], config('cors.allowed_origins'));
    }

    /* ---------------- Email header injection ---------------- */

    public function test_addresses_with_line_breaks_are_refused_everywhere()
    {
        $bad = "okello@example.com\r\nBcc: everyone@example.com";
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Okello', 'email' => $bad, 'password' => 'secret-12345', 'password_confirmation' => 'secret-12345',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => $bad, 'password' => 'x'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => "okello@example.com\nX"])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/reset-password', ['email' => $bad, 'token' => 't', 'password' => 'secret-12345', 'password_confirmation' => 'secret-12345'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->signIn($this->userWithRoles(['super_admin']));
        $this->patchJson('/api/v1/admin/settings/platform.support_email', ['value' => "help@workmate.ug\r\nBcc: x@y.z", 'reason' => 'Testing'])
            ->assertStatus(422);
    }

    public function test_ordinary_addresses_still_work()
    {
        User::factory()->create(['email' => 'okello@example.com', 'password' => Hash::make('secret-123')]);
        $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => 'okello@example.com', 'password' => 'secret-123'])->assertOk();
    }

    /* ---------------- Rate limits ---------------- */

    public function test_forgot_password_is_limited_per_address()
    {
        User::factory()->create(['email' => 'okello@example.com']);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'okello@example.com'])->assertOk();
        }
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'okello@example.com'])
            ->assertStatus(429)->assertHeader('Retry-After');
        // Someone else on the same network can still ask
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'someone@example.com'])->assertOk();
    }

    public function test_likes_are_limited_per_person()
    {
        $this->signIn(User::factory()->create());
        $statuses = [];
        for ($i = 0; $i < 31; $i++) {
            $statuses[] = $this->postJson('/api/v1/community/posts/00000000-0000-4000-8000-000000000000/like')->status();
        }
        $this->assertNotContains(429, array_slice($statuses, 0, 30));
        $this->assertSame(429, end($statuses));
    }

    public function test_push_subscription_changes_are_limited_per_person()
    {
        $this->signIn(User::factory()->create());
        for ($i = 0; $i < 30; $i++) {
            $this->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/x'.$i])->assertOk();
        }
        $this->deleteJson('/api/v1/me/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/y'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_video_upload_starts_are_limited_per_person()
    {
        $this->signIn(User::factory()->create());
        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $this->travel(25)->seconds();   // past the per-minute posting limit
            $statuses[] = $this->postJson('/api/v1/community/video-uploads', [])->status();
        }
        $this->assertNotContains(429, array_slice($statuses, 0, 10));
        $this->assertSame(429, end($statuses));
    }

    public function test_people_search_is_limited_per_person_not_per_network()
    {
        $this->signIn(User::factory()->create());
        for ($i = 0; $i < 40; $i++) {
            $this->getJson('/api/v1/community/people?q=ok'.$i)->assertOk();
        }
        $this->getJson('/api/v1/community/people?q=more')->assertStatus(429);
        // Another member on the same mobile network address isn't affected
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/community/people?q=ok')->assertOk();
    }

    public function test_registration_is_limited_per_network_address()
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/register', [])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/register', [])->assertStatus(429);
    }

    /* ---------------- Policy reports ---------------- */

    public function test_policy_reports_are_logged_without_tokens_or_signatures()
    {
        $logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context) use (&$logged) {
            $logged[] = [$message, $context];
        });
        $report = ['csp-report' => [
            'document-uri' => self::SITE.'/reset-password?token=secret-reset-token&email=a@b.c',
            'effective-directive' => 'media-src',
            'blocked-uri' => 'https://acct.r2.cloudflarestorage.com/bucket/video.mp4?X-Amz-Signature=abc123secret',
            'disposition' => 'report',
        ]];
        $this->call('POST', '/api/v1/csp-reports', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode($report))->assertNoContent();

        $this->assertCount(1, $logged);
        $context = json_encode($logged[0][1]);
        $this->assertStringContainsString('media-src', $context);
        $this->assertStringContainsString('/reset-password', $context);
        foreach (['secret-reset-token', 'X-Amz-Signature', 'abc123secret', 'a@b.c'] as $secret) {
            $this->assertStringNotContainsString($secret, $context);
        }
    }

    public function test_odd_or_oversized_policy_reports_are_ignored()
    {
        Log::shouldReceive('warning')->never();
        $this->call('POST', '/api/v1/csp-reports', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], 'not json')->assertNoContent();
        $this->call('POST', '/api/v1/csp-reports', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], str_repeat('x', 9000))->assertNoContent();
    }
}
