<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase B7, adds the two settlement permissions (insert or ignore: safe to run twice, never overwrites or removes anything):
 *
 *   settlements.view    Super Administrator, Finance Administrator
 *   settlements.verify  Super Administrator, Finance Administrator (the finance team verifies that a reported payment really arrived)
 */
class AddCommissionSettlementPermissions extends Migration
{
    private const PERMISSIONS = [
        'settlements.view' => ['See commission settlement cycles, reported commission payments and reconciliation exceptions', ['super_admin', 'finance']],
        'settlements.verify' => ['Verify or reject a commission payment a provider reported (confirming it really arrived)', ['super_admin', 'finance']],
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
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }
}
