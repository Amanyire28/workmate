<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The admin frontend (resources/js/admin/permissions.js) decides what to show
 * from its own copy of the roles. This keeps it identical to the server's.
 */
class PermissionDefinitionsTest extends TestCase
{
    public function test_frontend_permissions_match_the_server()
    {
        $js = file_get_contents(resource_path('js/admin/permissions.js'));

        preg_match('/export const PERMISSIONS = \{(.*?)\};/s', $js, $block);
        preg_match_all("/'([a-z_.]+)':\s/", $block[1], $keys);

        $this->assertSame(array_keys(config('permissions.permissions')), $keys[1]);
    }

    public function test_frontend_roles_match_the_server()
    {
        $js = file_get_contents(resource_path('js/admin/permissions.js'));
        preg_match_all("/id: '([a-z_]+)'.*?permissions: (\[.*?\]|Object\.keys\(PERMISSIONS\))/s", $js, $matches, PREG_SET_ORDER);

        $frontend = [];
        foreach ($matches as $match) {
            preg_match_all("/'([a-z_.]+)'/", $match[2], $permissions);
            $frontend[$match[1]] = $match[2] === 'Object.keys(PERMISSIONS)' ? '*' : $permissions[1];
        }

        $server = array_map(function ($role) {
            return $role['permissions'];
        }, config('permissions.roles'));

        $this->assertSame($server, $frontend);
    }

    public function test_roles_only_reference_defined_permissions()
    {
        $defined = array_keys(config('permissions.permissions'));

        foreach (config('permissions.roles') as $key => $role) {
            if ($role['permissions'] !== '*') {
                $this->assertSame([], array_diff($role['permissions'], $defined), "{$key} uses an undefined permission");
            }
        }
    }
}
