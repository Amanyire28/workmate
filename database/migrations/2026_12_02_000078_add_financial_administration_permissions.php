<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase B8, adds the financial administration permissions (insert or ignore: safe to run twice, never overwrites or removes anything):
 *
 *   finance.overview.view        Super Administrator, Finance Administrator
 *   finance.reports.view         Super Administrator, Finance Administrator (reports, CSV exports and the financial audit trail)
 *   finance.adjustments.request  Super Administrator, Finance Administrator (propose an adjustment)
 *   finance.exceptions.review    Super Administrator, Finance Administrator (take, note and investigate an exception)
 *   finance.adjustments.approve  Super Administrator only (approve or reject a proposed adjustment)
 *   finance.exceptions.resolve   Super Administrator only (authorise resolving an exception)
 */
class AddFinancialAdministrationPermissions extends Migration
{
    private const PERMISSIONS = [
        'finance.overview.view' => ['See the financial overview: commission assessed, reversed, collected and outstanding', ['super_admin', 'finance']],
        'finance.reports.view' => ['See financial reports, export them as CSV and read the financial audit trail', ['super_admin', 'finance']],
        'finance.adjustments.request' => ['Propose a correction to what a provider owes (it changes nothing until approved and posted)', ['super_admin', 'finance']],
        'finance.exceptions.review' => ['Take, annotate and investigate commission reconciliation exceptions', ['super_admin', 'finance']],
        'finance.adjustments.approve' => ['Approve or reject a proposed financial adjustment', ['super_admin']],
        'finance.exceptions.resolve' => ['Authorise resolving a commission reconciliation exception', ['super_admin']],
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
