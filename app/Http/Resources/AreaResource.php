<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AreaResource extends JsonResource
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
            'town' => $this->town,
            'district' => $this->district,
            'kind' => $this->kind,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_whole_town' => $this->is_whole_town,
            'is_featured' => $this->is_featured,
        ];
    }
}
