<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\PlatformSettings;
use LogicException;

class AuditAndSettingsTest extends ApiTestCase
{
    public function test_settings_fall_back_to_their_defaults()
    {
        $this->assertSame(5, app(PlatformSettings::class)->get('search.nearby_km'));
        $this->assertSame(60, app(PlatformSettings::class)->get('presence.heartbeat_seconds'));
    }

    public function test_admins_see_only_settings_their_role_allows()
    {
        $this->signIn($this->userWithRoles(['analytics']));

        $settings = collect($this->getJson('/api/v1/admin/settings')->assertOk()->json('data'));

        // analytics may view location and usage, but change neither
        $this->assertEqualsCanonicalizing(['search', 'presence'], $settings->pluck('group')->unique()->values()->all());
        $this->assertFalse($settings->contains('can_change', true));

        $this->signIn($this->userWithRoles(['finance']));
        $this->getJson('/api/v1/admin/settings')->assertExactJson(['data' => []]);
    }

    public function test_changing_a_setting_saves_and_audits_it()
    {
        $admin = $this->signIn($this->userWithRoles(['technical']));

        $this->patchJson('/api/v1/admin/settings/search.nearby_km', ['value' => 7, 'reason' => 'Sparse coverage in Wakiso'])
            ->assertOk()
            ->assertJsonPath('data.value', 7)
            ->assertJsonPath('data.default', 5);

        $this->assertSame(7, app(PlatformSettings::class)->get('search.nearby_km'));

        $log = AuditLog::where('action', 'settings.updated')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['search.nearby_km' => 5], $log->before);
        $this->assertSame(['search.nearby_km' => 7], $log->after);
    }

    public function test_view_only_admins_cannot_change_settings()
    {
        $this->signIn($this->userWithRoles(['analytics']));

        $this->patchJson('/api/v1/admin/settings/search.nearby_km', ['value' => 7])->assertForbidden();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_unknown_or_hidden_settings_are_404()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $this->patchJson('/api/v1/admin/settings/payments.commission_rate', ['value' => 0])->assertNotFound();

        $this->signIn($this->userWithRoles(['finance']));
        $this->patchJson('/api/v1/admin/settings/search.nearby_km', ['value' => 7])->assertNotFound();
    }

    public function test_values_outside_the_allowed_range_are_rejected()
    {
        $this->signIn($this->userWithRoles(['super_admin']));

        $this->patchJson('/api/v1/admin/settings/presence.heartbeat_seconds', ['value' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    public function test_paired_settings_must_stay_in_order()
    {
        $this->signIn($this->userWithRoles(['super_admin']));

        $this->patchJson('/api/v1/admin/settings/search.nearby_km', ['value' => 10])
            ->assertStatus(422)
            ->assertJson(['code' => 'setting_out_of_order']);

        $this->patchJson('/api/v1/admin/settings/search.extended_km', ['value' => 4])
            ->assertStatus(422)
            ->assertJson(['code' => 'setting_out_of_order']);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_audit_entries_cannot_be_changed_or_deleted()
    {
        $log = app(AuditLogger::class)->record('test.action');

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('An audit entry was updated.');
        } catch (LogicException $e) {
            $this->assertSame('test.action', $log->fresh()->action);
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_sensitive_values_are_redacted_in_the_audit_log()
    {
        $log = app(AuditLogger::class)->record('test.action', null, [], [
            'name' => 'Visible',
            'password' => 'hunter2',
            'payout' => ['api_key' => 'live_123', 'phone' => '0772000000'],
        ]);

        $this->assertSame([
            'name' => 'Visible',
            'password' => '[redacted]',
            'payout' => ['api_key' => '[redacted]', 'phone' => '0772000000'],
        ], $log->fresh()->after);
    }

    public function test_audit_log_listing_is_paginated_and_filterable()
    {
        $this->signIn($this->userWithRoles(['technical']));
        app(AuditLogger::class)->record('settings.updated');
        app(AuditLogger::class)->record('roles.granted');

        $this->getJson('/api/v1/admin/audit-logs?action=roles.granted&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'roles.granted')
            ->assertJsonPath('meta.per_page', 10);

        $this->getJson('/api/v1/admin/audit-logs?per_page=500')->assertStatus(422);
    }
}
