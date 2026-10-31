<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => $this->permissions->pluck('key')->sort()->values(),
            'users_count' => $this->when(isset($this->users_count), function () {
                return (int) $this->users_count;
            }),
        ];
    }
}
