<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\Accounts;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The signed-in account, with the capabilities, roles and permissions the
     * frontend uses to decide what to show. The server still checks every request.
     */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles', 'providerProfile'));
    }

    public function update(UpdateProfileRequest $request, Accounts $accounts): UserResource
    {
        $user = $accounts->updateProfile($request->user(), $request->validated());

        return new UserResource($user->load('roles', 'providerProfile'));
    }
}
