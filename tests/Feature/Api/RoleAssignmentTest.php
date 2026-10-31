<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;

class RoleAssignmentTest extends ApiTestCase
{
    public function test_super_admin_can_grant_a_role_and_it_is_audited()
    {
        $admin = $this->signIn($this->userWithRoles(['super_admin']));
        $user = $this->userWithRoles();

        $this->putJson("/api/v1/admin/users/{$user->id}/roles/finance", ['reason' => 'Joined the finance team'])
            ->assertOk()
            ->assertJsonPath('data.0.key', 'finance');

        $this->assertTrue($user->fresh()->can('finance.view'));

        $log = AuditLog::where('action', 'roles.granted')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($user->id, (int) $log->subject_id);
        $this->assertSame(['roles' => []], $log->before);
        $this->assertSame(['roles' => ['finance']], $log->after);
        $this->assertSame('Joined the finance team', $log->reason);
    }

    public function test_a_reason_is_required()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $user = $this->userWithRoles();

        $this->putJson("/api/v1/admin/users/{$user->id}/roles/finance")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_only_admin_managers_can_change_roles()
    {
        $this->signIn($this->userWithRoles(['operations']));
        $user = $this->userWithRoles();

        $this->putJson("/api/v1/admin/users/{$user->id}/roles/super_admin", ['reason' => 'x'])->assertForbidden();
        $this->assertFalse($user->fresh()->hasRole('super_admin'));
    }

    public function test_admins_cannot_change_their_own_roles()
    {
        $admin = $this->signIn($this->userWithRoles(['super_admin']));

        $this->deleteJson("/api/v1/admin/users/{$admin->id}/roles/super_admin", ['reason' => 'x'])
            ->assertForbidden()
            ->assertJson(['code' => 'cannot_change_own_roles']);
    }

    public function test_the_last_active_super_admin_cannot_be_removed()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $other = $this->userWithRoles(['super_admin']);
        $other->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        // Removing the suspended one is fine; the signed-in admin remains
        $this->deleteJson("/api/v1/admin/users/{$other->id}/roles/super_admin", ['reason' => 'Left'])->assertOk();

        // From the command line there is no actor, so only the last-admin rule applies
        $only = User::whereHas('roles', function ($q) {
            $q->where('key', 'super_admin');
        })->sole();
        $this->artisan('workmate:role', ['email' => $only->email, 'role' => 'super_admin', '--revoke' => true])
            ->expectsOutput('This is the only active Super Administrator. Grant the role to someone else first.')
            ->assertExitCode(1);

        $this->assertTrue($only->fresh()->hasRole('super_admin'));
    }

    public function test_unknown_users_and_roles_are_404()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $user = $this->userWithRoles();

        $this->putJson('/api/v1/admin/users/99999/roles/finance', ['reason' => 'x'])->assertNotFound();
        $this->putJson("/api/v1/admin/users/{$user->id}/roles/owner", ['reason' => 'x'])->assertNotFound();
    }

    public function test_the_command_grants_the_first_super_admin()
    {
        $user = User::factory()->create(['email' => 'first.admin@example.com']);

        $this->artisan('workmate:role', ['email' => 'first.admin@example.com', 'role' => 'super_admin'])
            ->expectsOutput('Granted Super Administrator to first.admin@example.com.')
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->can('admin.manage'));
        $this->assertNull(AuditLog::where('action', 'roles.granted')->sole()->actor_id);
    }
}
