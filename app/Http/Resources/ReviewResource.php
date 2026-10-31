<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->provider_profile_id,
            'reviewer_name' => $this->reviewer_name,
            'job_title' => $this->job_title,
            'area_name' => $this->area_name,
            'rating' => $this->rating,
            'would_hire_again' => $this->would_hire_again,
            'comment' => $this->comment,
            'reviewed_at' => $this->reviewed_at->toIso8601String(),
            'is_sample' => $this->is_sample,
        ];
    }
}
