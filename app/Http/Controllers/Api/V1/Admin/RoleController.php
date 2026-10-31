<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderBy('id')
            ->get();

        return RoleResource::collection($roles);
    }

    public function permissions(): JsonResponse
    {
        $permissions = collect(config('permissions.permissions'))
            ->map(function ($description, $key) {
                return ['key' => $key, 'description' => $description];
            })
            ->values();

        return response()->json(['data' => $permissions]);
    }
}
