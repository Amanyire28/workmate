<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\DeviceSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class DeviceSessionsTest extends ApiTestCase
{
    private const PHONE = 'Mozilla/5.0 (Linux; Android 13; SM-A145F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0';

    private function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
    }

    /** A stored sign-in session for this person */
    private function storedSession(User $user, string $agent, string $ip = '41.210.155.23', int $minutesAgo = 5): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => $ip, 'user_agent' => $agent,
            'payload' => base64_encode(serialize([])), 'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);

        return $id;
    }

    /* ---------------- Staying signed in ---------------- */

    public function test_keep_me_signed_in_sets_a_90_day_cookie_and_a_shared_device_gets_a_short_session()
    {
        $user = User::factory()->create(['email' => 'aisha@example.com']);

        $res = $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => 'aisha@example.com', 'password' => 'password', 'remember' => true])->assertOk();
        $recaller = collect($res->headers->getCookies())->first(function ($c) {
            return Str::startsWith($c->getName(), 'remember_web_');
        });
        $this->assertNotNull($recaller);
        $this->assertEqualsWithDelta(now()->addMinutes(129600)->getTimestamp(), $recaller->getExpiresTime(), 120);
        $shared = collect($res->headers->getCookies())->first(function ($c) {
            return $c->getName() === 'wm_shared_device';
        });
        // Ticked: any shared-device marker is removed
        $this->assertTrue(! $shared || $shared->getExpiresTime() < time());

        $this->fromWebsite()->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $res = $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => 'aisha@example.com', 'password' => 'password', 'remember' => false])->assertOk();
        $shared = collect($res->headers->getCookies())->first(function ($c) {
            return $c->getName() === 'wm_shared_device';
        });
        $this->assertSame('1', $shared->getValue());
        // Ends when the browser closes
        $this->assertSame(0, $shared->getExpiresTime());
    }

    public function test_a_shared_device_session_is_short_and_ends_with_the_browser()
    {
        config(['session.lifetime' => 129600, 'session.expire_on_close' => false]);
        $this->withCredentials()->withUnencryptedCookie('wm_shared_device', '1')->getJson('/api/v1/health')->assertOk();
        $this->assertSame(120, config('session.lifetime'));
        $this->assertTrue(config('session.expire_on_close'));
    }

    public function test_new_accounts_stay_signed_in()
    {
        Notification::fake();
        $res = $this->fromWebsite()->postJson('/api/v1/auth/register', [
            'name' => 'Nakato Sarah', 'email' => 'sarah@example.com', 'password' => 'kampala2026', 'password_confirmation' => 'kampala2026', 'accept_terms' => true,
        ])->assertCreated();

        $this->assertTrue(collect($res->headers->getCookies())->contains(function ($c) {
            return Str::startsWith($c->getName(), 'remember_web_');
        }));
    }

    /* ---------------- Account → Devices ---------------- */

    public function test_people_see_only_their_own_devices_with_partial_addresses()
    {
        $this->useDatabaseSessions();
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->storedSession($me, self::PHONE);
        $this->storedSession($me, self::LAPTOP, '102.85.1.9', 60);
        $this->storedSession($other, self::PHONE);
        // Expired sessions aren't listed
        $this->storedSession($me, self::LAPTOP, '1.2.3.4', 129600 + 10);

        $this->signIn($me);
        $data = $this->getJson('/api/v1/me/devices')->assertOk()->json('data');
        $this->assertTrue($data['available']);
        $this->assertCount(2, $data['devices']);
        $this->assertSame(['Chrome', 'Android', 'phone', '41.210.155.x'], [$data['devices'][0]['browser'], $data['devices'][0]['platform'], $data['devices'][0]['kind'], $data['devices'][0]['ip_address']]);
        $this->assertSame(['Edge', 'Windows', 'computer'], [$data['devices'][1]['browser'], $data['devices'][1]['platform'], $data['devices'][1]['kind']]);
        // Session ids never leave the server
        $this->assertStringNotContainsString(DB::table('sessions')->where('user_id', $me->id)->value('id'), json_encode($data));
    }

    public function test_signing_out_one_device_only_works_on_your_own()
    {
        $this->useDatabaseSessions();
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->storedSession($me, self::PHONE);
        $theirs = $this->storedSession($other, self::PHONE);
        $token = $me->remember_token;

        $this->signIn($me);
        $this->deleteJson('/api/v1/me/devices/'.DeviceSessions::keyOf($theirs))->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => $theirs]);

        $this->deleteJson('/api/v1/me/devices/'.DeviceSessions::keyOf($mine))->assertNoContent();
        $this->assertDatabaseMissing('sessions', ['id' => $mine]);
        // Its "Keep me signed in" cookie can't sign it back in
        $this->assertNotSame($token, $me->fresh()->remember_token);
        $this->assertSame(1, AuditLog::where('action', 'auth.device_signed_out')->count());
    }

    public function test_signing_out_every_other_device()
    {
        $this->useDatabaseSessions();
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->storedSession($me, self::PHONE);
        $this->storedSession($me, self::LAPTOP);
        $theirs = $this->storedSession($other, self::PHONE);

        $this->signIn($me);
        $this->postJson('/api/v1/me/devices/sign-out-others')->assertOk()->assertJsonPath('data.signed_out', 2);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $me->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => $theirs]);
    }

    public function test_changing_the_password_signs_out_other_devices()
    {
        $this->useDatabaseSessions();
        $me = User::factory()->create(['password' => Hash::make('old-pass-1')]);
        $this->storedSession($me, self::PHONE);
        $token = $me->remember_token;

        $this->signIn($me);
        $this->putJson('/api/v1/me/password', ['current_password' => 'old-pass-1', 'password' => 'new-pass-2', 'password_confirmation' => 'new-pass-2'])->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $me->id)->count());
        $this->assertNotSame($token, $me->fresh()->remember_token);
    }

    public function test_suspending_an_account_signs_it_out_everywhere()
    {
        $this->useDatabaseSessions();
        $customer = User::factory()->create();
        $this->storedSession($customer, self::PHONE);
        $this->storedSession($customer, self::LAPTOP);
        $token = $customer->remember_token;

        $this->signIn($this->userWithRoles(['trust_safety']));
        $this->postJson("/api/v1/admin/users/{$customer->id}/suspend", ['reason' => 'Confirmed fraud report'])->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $customer->id)->count());
        $this->assertNotSame($token, $customer->fresh()->remember_token);
    }

    public function test_the_list_says_when_sessions_are_not_in_the_database()
    {
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/me/devices')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.devices', []);
    }

    public function test_signed_out_people_cannot_see_or_change_devices()
    {
        $this->getJson('/api/v1/me/devices')->assertUnauthorized();
        $this->postJson('/api/v1/me/devices/sign-out-others')->assertUnauthorized();
    }
}
