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

    public function test_smart_push_is_off_by_default_and_only_those_who_manage_the_platform_can_see_or_change_it()
    {
        $key = 'notifications.smart_push';
        $this->assertFalse(app(PlatformSettings::class)->get($key));
        $this->assertFalse(app(\App\Services\PushDelivery::class)->smartEnabled());

        // Not signed in, and an ordinary member
        $this->setting($key, true)->assertUnauthorized();
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/admin/settings')->assertOk()->assertExactJson(['data' => []]);   // sees no settings at all
        $this->setting($key, true)->assertNotFound();

        // Staff without platform management don't see it, and can't change it by naming it
        foreach (['support', 'finance', 'technical', 'provider_review'] as $role) {
            $this->signIn($this->userWithRoles([$role]));
            $this->assertNotContains($key, array_column($this->getJson('/api/v1/admin/settings')->assertOk()->json('data'), 'key'), $role);
            $this->setting($key, true)->assertNotFound();
        }
        $this->assertFalse(app(\App\Services\PushDelivery::class)->smartEnabled());

        // The administrator sees it, off, and may change it
        $this->signIn($this->userWithRoles(['super_admin']));
        $mine = collect($this->getJson('/api/v1/admin/settings')->assertOk()->json('data'))->firstWhere('key', $key);
        $this->assertFalse($mine['value']);
        $this->assertFalse($mine['default']);
        $this->assertTrue($mine['can_change']);
        $this->assertSame('boolean', $mine['type']);
        $this->assertSame('notifications', $mine['group']);

        // It is not handed to every visitor of the site with the public settings
        $this->assertArrayNotHasKey($key, $this->getJson('/api/v1/catalog')->assertOk()->json('data.settings'));
    }

    public function test_switching_smart_push_is_validated_audited_and_takes_effect_at_once()
    {
        $key = 'notifications.smart_push';
        $admin = $this->signIn($this->userWithRoles(['super_admin']));
        $others = function () use ($key) {
            return collect($this->getJson('/api/v1/admin/settings')->json('data'))->where('key', '!=', $key)->pluck('value', 'key')->all();
        };
        $before = $others();

        // Only a true or false
        $this->patchJson("/api/v1/admin/settings/{$key}", ['value' => 'maybe', 'reason' => 'Testing'])->assertStatus(422);
        $this->patchJson("/api/v1/admin/settings/{$key}", ['reason' => 'Testing'])->assertStatus(422);
        $this->assertFalse(app(\App\Services\PushDelivery::class)->smartEnabled());

        // On: the answer, the delivery code and the audit trail all agree
        $this->patchJson("/api/v1/admin/settings/{$key}", ['value' => true, 'reason' => 'Scheduler confirmed on the server'])
            ->assertOk()->assertJsonPath('data.value', true)->assertJsonPath('data.default', false);
        $this->assertTrue(app(\App\Services\PushDelivery::class)->smartEnabled());
        $log = \App\Models\AuditLog::where('action', 'settings.updated')->latest('id')->first();
        $this->assertSame('Scheduler confirmed on the server', $log->reason);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame([$key => false], $log->before);
        $this->assertSame([$key => true], $log->after);

        // And off again at once
        $this->patchJson("/api/v1/admin/settings/{$key}", ['value' => false, 'reason' => 'Pausing'])->assertOk()->assertJsonPath('data.value', false);
        $this->assertFalse(app(\App\Services\PushDelivery::class)->smartEnabled());

        // No other setting moved
        $this->assertSame($before, $others());
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
        $this->postJson('/api/v1/me/provider/submit', ['accept_provider_terms' => true])->assertStatus(409)->assertJson(['code' => 'provider_signups_closed']);
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

    public function test_open_apps_can_refresh_just_the_settings()
    {
        app(PlatformSettings::class)->set('platform.maintenance_mode', true, $this->userWithRoles(['super_admin']));

        $settings = $this->getJson('/api/v1/settings')->assertOk()->json('data');
        $this->assertTrue($settings['platform.maintenance_mode']);
        $this->assertSame(app(PlatformSettings::class)->publicValues(), $settings);
        $this->assertArrayNotHasKey('presence.online_minutes', $settings);
    }
}
