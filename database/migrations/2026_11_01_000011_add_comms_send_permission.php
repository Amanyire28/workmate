<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddCommsSendPermission extends Migration
{
    /**
     * Adds the comms.send permission and gives it to the Super Administrator
     * role, so sending push announcements works without re-running the
     * seeder. Safe to run twice; the seeder does the same.
     *
     * @return void
     */
    public function up()
    {
        DB::table('permissions')->insertOrIgnore([
            'key' => 'comms.send',
            'description' => 'Send push announcements to everyone who turned them on',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('key', 'comms.send')->value('id');
        $roleId = DB::table('roles')->where('key', 'super_admin')->value('id');

        if ($permissionId && $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // permission_role rows go with it (cascade)
        DB::table('permissions')->where('key', 'comms.send')->delete();
    }
}
