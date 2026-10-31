<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Brings permissions and system roles in line with config/permissions.php.
 * Safe to run repeatedly. It never removes a role from a user and never
 * deletes permissions or roles that are no longer defined.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        DB::transaction(function () {
            $permissionIds = [];

            foreach (config('permissions.permissions') as $key => $description) {
                $permissionIds[$key] = Permission::updateOrCreate(['key' => $key], ['description' => $description])->id;
            }

            foreach (config('permissions.roles') as $key => $definition) {
                $role = Role::updateOrCreate(['key' => $key], [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ]);

                $keys = $definition['permissions'] === '*' ? array_keys($permissionIds) : $definition['permissions'];
                $role->permissions()->sync(array_map(function ($permission) use ($permissionIds) {
                    return $permissionIds[$permission];
                }, $keys));
            }
        });
    }
}
