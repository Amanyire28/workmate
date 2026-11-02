<?php

namespace Tests\Feature\Api;

use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;

class PlatformSettingsTest extends ApiTestCase
{
    private function setting(string $key, $value)
    {
        return $this->patchJson("/api/v1/admin/settings/{$key}", ['value' => $value, 'reason' => 'Testing the setting']);
    }

    public function test_the_app_receives_every_setting_it_applies()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $this->setting('search.max_km', 40)->assertOk();
        $this->setting('search.rank_rating', true)->assertOk();
        $this->setting('platform.default_area', 'kyebando-kampala')->assertOk();
        $this->setting('platform.support_phone', ' +256 700 123456 ')->assertOk();
        $this->setting('platform.support_email', 'help@example.com')->assertOk();

        $catalog = $this->getJson('/api/v1/catalog')->assertOk()->json('data');
        $settings = $catalog['settings'];
        $this->assertSame(40, $settings['search.max_km']);
        $this->assertTrue($settings['search.rank_rating']);
        $this->assertSame('kyebando-kampala', $settings['platform.default_area']);
        $this->assertSame('+256 700 123456', $settings['platform.support_phone']);
        $this->assertSame('help@example.com', $settings['platform.support_email']);
        // The default place is among the places the app knows
        $this->assertContains('kyebando-kampala', array_column($catalog['areas'], 'slug'));

        // Emptying a text setting hides it again
        $this->setting('platform.support_phone', '')->assertOk();
        $this->assertNull($this->getJson('/api/v1/catalog')->json('data.settings')['platform.support_phone']);
    }

    public function test_settings_are_checked()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $this->setting('platform.default_area', 'atlantis')->assertStatus(422);
        $this->setting('platform.support_email', 'not-an-email')->assertStatus(422);
        $this->setting('search.max_km', 2)->assertStatus(422);
        // The wider radius must stay below the maximum distance
        $this->setting('search.max_km', 8)->assertStatus(422)->assertJson(['code' => 'setting_out_of_order']);

        // Platform settings need admin.manage (a role without it doesn't see them); matching needs location.configure
        $this->signIn($this->userWithRoles(['technical']));
        $this->setting('search.use_service_areas', false)->assertOk();
        $this->setting('platform.maintenance_mode', true)->assertNotFound();
        $this->assertFalse(app(\App\Services\PlatformSettings::class)->get('platform.maintenance_mode'));
    }

    public function test_maintenance_mode_pauses_new_work_but_not_messages()
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));
        $provider = ProviderProfile::where('owner_id', $owner->id)->sole();

        $this->signIn($this->userWithRoles(['super_admin']));
        $this->setting('platform.maintenance_mode', true)->assertOk();

        $this->signIn(User::factory()->create());
        $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow',
        ])->assertStatus(503)->assertJson(['code' => 'maintenance']);
        $this->postJson('/api/v1/jobs', [
            'category' => 'plumbing', 'title' => 'Fix a leaking pipe', 'description' => 'Under the kitchen sink.',
            'area' => 'ntinda', 'preferred_time' => 'This week', 'workers_needed' => 1,
        ])->assertStatus(503);

        $chat = $this->postJson("/api/v1/me/conversations/provider/{$provider->id}")->assertOk()->json('data.id');
        $this->postJson("/api/v1/me/conversations/{$chat}/messages", ['body' => 'Are you available next week?'])->assertCreated();
    }

    public function test_new_provider_applications_can_be_stopped()
    {
        app(PlatformSettings::class)->set('platform.provider_signups', false, $this->userWithRoles(['super_admin']));

        $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/provider', ['type' => 'individual', 'display_name' => 'Nambi Cleaning', 'professions' => [['profession' => 'cleaner']], 'base_area' => 'ntinda'])->assertOk();
        $this->postJson('/api/v1/me/provider/submit')->assertStatus(409)->assertJson(['code' => 'provider_signups_closed']);
        $this->assertSame('draft', $this->getJson('/api/v1/me/provider')->json('data.status'));
    }

    public function test_the_app_checks_in_and_admins_see_who_is_online()
    {
        $this->postJson('/api/v1/me/heartbeat')->assertUnauthorized();

        $customer = User::factory()->create();
        $this->signIn($customer);
        $this->postJson('/api/v1/me/heartbeat')->assertOk();
        $this->assertNotNull($customer->fresh()->last_seen_at);

        $quiet = User::factory()->create();
        $quiet->forceFill(['last_seen_at' => now()->subMinutes(20)])->save();
        $away = User::factory()->create();
        $away->forceFill(['last_seen_at' => now()->subHours(3)])->save();

        $this->signIn($this->userWithRoles(['support']));
        $rows = collect($this->getJson('/api/v1/admin/data')->json('data.core.customers'))->keyBy('userId');
        $this->assertSame('online', $rows[$customer->id]['presence']);
        $this->assertSame('recently_active', $rows[$quiet->id]['presence']);
        $this->assertSame('offline', $rows[$away->id]['presence']);
    }
}
