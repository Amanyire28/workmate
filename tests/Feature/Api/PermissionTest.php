<?php

namespace Tests\Feature\Api;

use App\Models\User;

class PermissionTest extends ApiTestCase
{
    public function test_customers_cannot_reach_admin_endpoints()
    {
        $this->signIn($this->userWithRoles());

        $this->getJson('/api/v1/admin/roles')->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->getJson('/api/v1/admin/settings')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_each_role_reaches_only_what_its_permissions_allow()
    {
        $this->signIn($this->userWithRoles(['technical']));

        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->getJson('/api/v1/admin/roles')->assertForbidden();

        $this->signIn($this->userWithRoles(['finance']));

        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    }

    public function test_a_suspended_admin_loses_every_permission()
    {
        $admin = $this->userWithRoles(['super_admin']);
        $admin->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->signIn($admin->fresh());

        $this->getJson('/api/v1/admin/roles')->assertForbidden()->assertJson(['code' => 'account_inactive']);
        $this->assertSame([], $admin->fresh()->permissionKeys());
    }

    public function test_status_cannot_be_mass_assigned()
    {
        $user = User::factory()->create();
        $user->fill(['status' => User::STATUS_SUSPENDED, 'name' => 'Changed']);

        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame('Changed', $user->name);
    }

    public function test_super_admin_holds_every_permission_and_others_hold_no_admin_manage()
    {
        $super = $this->userWithRoles(['super_admin']);
        $this->assertEqualsCanonicalizing(array_keys(config('permissions.permissions')), $super->permissionKeys());

        foreach (array_keys(config('permissions.roles')) as $role) {
            if ($role !== 'super_admin') {
                $this->assertFalse($this->userWithRoles([$role])->can('admin.manage'), "{$role} must not hold admin.manage");
            }
        }
    }

    public function test_roles_list_matches_the_definitions()
    {
        $this->signIn($this->userWithRoles(['super_admin']));

        $this->getJson('/api/v1/admin/roles')
            ->assertOk()
            ->assertJsonCount(count(config('permissions.roles')), 'data')
            ->assertJsonPath('data.0.key', 'super_admin')
            ->assertJsonPath('data.0.users_count', 1);

        $this->getJson('/api/v1/admin/permissions')
            ->assertOk()
            ->assertJsonCount(count(config('permissions.permissions')), 'data');
    }

    public function test_seeding_twice_changes_nothing_and_keeps_assignments()
    {
        $admin = $this->userWithRoles(['support']);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->assertDatabaseCount('roles', count(config('permissions.roles')));
        $this->assertDatabaseCount('permissions', count(config('permissions.permissions')));
        $this->assertTrue($admin->fresh()->hasRole('support'));
    }
}
