<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One new administrator permission: finance.settings.manage (see and change the financial settings). Super Administrator only.
 */
class AddFinancialSettingsPermission extends Migration
{
    private const KEY = 'finance.settings.manage';

    public function up()
    {
        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY, 'description' => 'See and change the financial settings: pricing, commission estimates, obligations and settlements',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
        $roleId = DB::table('roles')->where('key', 'super_admin')->value('id');
        if ($permissionId && $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down()
    {
        DB::table('permissions')->where('key', self::KEY)->delete();
    }
}
