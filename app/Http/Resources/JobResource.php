<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A service request: a posted job, or a request sent to one provider.
 * The customer is never named publicly; contact happens through WorkMate.
 */
class JobResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $user = $request->user('sanctum');
        $profile = $user ? $user->providerProfile : null;

        return [
            'id' => $this->id,
            'kind' => $this->isPostedJob() ? 'posted' : 'direct',
            'title' => $this->title,
            'category' => $this->category->slug,
            'description' => $this->description,
            'area' => $this->area_id ? optional($this->area)->slug : null,
            'area_name' => $this->area_name,
            'preferred_time' => $this->preferred_time,
            'workers_needed' => $this->workers_needed,
            'budget' => $this->budget,
            'budget_basis' => $this->budget_basis,
            'status' => $this->status,
            'posted_at' => $this->created_at->toIso8601String(),
            'applicants' => (int) ($this->quotations_count ?? $this->quotations()->count()),
            'is_sample' => $this->is_sample,
            'is_mine' => $user !== null && $this->customer_id === (int) $user->getKey(),
            'has_applied' => $profile !== null
                && $this->quotations()->where('provider_profile_id', $profile->getKey())->exists(),
            // Only for requests sent to one provider
            'provider' => $this->when(! $this->isPostedJob(), function () {
                return ['id' => $this->provider_profile_id, 'display_name' => $this->provider->display_name];
            }),
        ];
    }
}
