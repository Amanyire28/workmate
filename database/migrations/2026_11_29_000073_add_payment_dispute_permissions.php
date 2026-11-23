<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase B5, adds the three payment-dispute permissions and gives them to the roles that should have them (the same
 * insert-or-ignore pattern as commission.view, so it is safe to run twice and never overwrites or removes anything):
 *
 *   disputes.view     Super Administrator, Finance Administrator (read the escalated cases)
 *   disputes.review   Super Administrator, Finance Administrator (take a case into review, ask for information)
 *   disputes.resolve  Super Administrator only (record the decision that closes a case)
 */
class AddPaymentDisputePermissions extends Migration
{
    private const PERMISSIONS = [
        'disputes.view' => ['See payment disputes the parties escalated, with their discussion and evidence', ['super_admin', 'finance']],
        'disputes.review' => ['Take a payment dispute into review and ask the customer or provider for more information', ['super_admin', 'finance']],
        'disputes.resolve' => ['Record the reasoned decision that closes a payment dispute', ['super_admin']],
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
