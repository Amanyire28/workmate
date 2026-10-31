<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleAssignment;
use Illuminate\Http\Request;

class UserRoleController extends Controller
{
    /** @var RoleAssignment */
    private $roles;

    public function __construct(RoleAssignment $roles)
    {
        $this->roles = $roles;
    }

    public function store(Request $request, User $user, Role $role)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->roles->grant($user, $role, $request->user(), $data['reason']);

        return RoleResource::collection($user->roles()->with('permissions')->get());
    }

    public function destroy(Request $request, User $user, Role $role)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->roles->revoke($user, $role, $request->user(), $data['reason']);

        return RoleResource::collection($user->roles()->with('permissions')->get());
    }
}
