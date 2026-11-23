<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase B4, adds the two permissions for the shadow commission screens and gives them to the roles that should have
 * them, so they work without anyone re-running the seeder (the same way comms.send was added):
 *
 *   commission.view    the Super Administrator and the Finance Administrator (read-only: estimates and rate rules)
 *   commission.manage  the Super Administrator only (create, replace and retire rate rules)
 *
 * Inserts only, and "insert or ignore", so it is safe to run twice, and it never removes or changes anything that
 * exists. A role that doesn't exist on this server is skipped. Roles are not rewritten: nobody else gains access.
 */
class AddCommissionShadowPermissions extends Migration
{
    private const PERMISSIONS = [
        'commission.view' => ['View estimated (shadow) commissions and the commission rate rules. Nothing is collected', ['super_admin', 'finance']],
        'commission.manage' => ['Create, replace and retire shadow commission rate rules', ['super_admin']],
    ];

    public function up()
    {
        foreach (self::PERMISSIONS as $key => [$description, $roles]) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            if (! $permissionId) {
                continue;
            }
            foreach ($roles as $role) {
                $roleId = DB::table('roles')->where('key', $role)->value('id');
                if ($roleId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }
    }

    public function down()
    {
        // permission_role rows go with the permissions (cascade)
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }
}
