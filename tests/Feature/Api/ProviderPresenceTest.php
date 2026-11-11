<?php

namespace Tests\Feature\Api;

use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\ProviderApplications;

/** "Online now" / "Last seen", its privacy switch, and its small place in recommendations */
class ProviderPresenceTest extends ApiTestCase
{
    private function provider(string $area = 'ntinda'): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Provider '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => $area]);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function seen(ProviderProfile $p, $at): void
    {
        $p->owner->forceFill(['last_seen_at' => $at])->save();
    }

    public function test_customers_see_online_now_or_when_they_were_last_seen()
    {
        $provider = $this->provider();
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.presence', ['online' => false, 'last_seen_at' => null]);

        $this->seen($provider, now()->subMinute());
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.presence.online', true);

        // Stale check-ins expire: after the admin's online window (5 minutes) it's "last seen"
        $this->seen($provider, now()->subMinutes(40));
        $presence = $this->getJson("/api/v1/providers/{$provider->id}")->json('data.presence');
        $this->assertFalse($presence['online']);
        $this->assertSame(now()->subMinutes(40)->startOfMinute()->toIso8601String(), $presence['last_seen_at']);
    }

    public function test_the_app_checking_in_marks_the_provider_online()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/heartbeat')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.presence.online', true);
    }

    public function test_providers_can_hide_their_last_seen()
    {
        $provider = $this->provider();
        $this->seen($provider, now());
        $this->signIn($provider->owner);

        $this->patchJson('/api/v1/me', ['show_last_seen' => 'often'])->assertStatus(422)->assertJsonValidationErrors('show_last_seen');
        $this->patchJson('/api/v1/me', ['show_last_seen' => false])->assertOk()->assertJsonPath('data.show_last_seen', false);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.presence', null);
        $listed = collect($this->getJson('/api/v1/catalog')->json('data.providers'))->firstWhere('id', $provider->id);
        $this->assertNull($listed['presence']);
    }

    public function test_online_providers_get_a_small_edge_and_offline_ones_stay_listed()
    {
        $offline = $this->provider();
        $online = $this->provider();
        $this->seen($online, now());

        $ids = $this->getJson('/api/v1/providers/recommended?area=ntinda')->assertOk()->json('data.ids');
        $this->assertSame([$online->id, $offline->id], $ids);
    }

    public function test_paused_providers_come_after_those_taking_requests()
    {
        $paused = $this->provider();
        $open = $this->provider();
        $this->seen($paused, now());
        $paused->forceFill(['accepts_immediate' => false, 'accepts_bookings' => false])->save();

        $ids = $this->getJson('/api/v1/providers/recommended?area=ntinda')->json('data.ids');
        $this->assertSame([$open->id, $paused->id], $ids);
    }
}
