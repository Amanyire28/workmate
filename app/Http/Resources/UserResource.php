<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user's own account. Never includes the password, tokens or
 * internal fields; other people's accounts get narrower resources.
 */
class UserResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'phone' => $this->phone,
            'avatar_url' => $this->avatarUrl(),
            'locale' => $this->locale,
            'status' => $this->status,
            // Every account is a customer; provider capability depends on approval
            'capabilities' => [
                'customer' => true,
                'provider' => $this->isProvider(),
            ],
            'provider_status' => $this->providerProfile ? $this->providerProfile->status : 'not_applied',
            'roles' => $this->roles->pluck('key')->values(),
            'permissions' => $this->permissionKeys(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
