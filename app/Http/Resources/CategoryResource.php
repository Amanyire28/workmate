<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'professions' => $this->whenLoaded('professions', function () {
                return $this->professions->map(function ($profession) {
                    return [
                        'slug' => $profession->slug,
                        'name' => $profession->name,
                        'plural_name' => $profession->plural_name,
                    ];
                })->values();
            }),
        ];
    }
}
