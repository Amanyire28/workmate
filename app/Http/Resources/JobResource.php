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
            // The provider's listed service it was for, if any
            'service_id' => $this->provider_service_id,
            // Made before bookings existed: the original steps only
            'is_legacy' => $this->resource->isLegacy(),
            'category' => $this->category->slug,
            'description' => $this->description,
            'area' => $this->area_id ? optional($this->area)->slug : null,
            'area_name' => $this->area_name,
            'preferred_time' => $this->preferred_time,
            // When: for anyone who can see the request (no personal details)
            'schedule' => \App\Services\RequestDetails::schedule($this->resource),
            // Where exactly: the customer's own request only
            'location' => $this->when($user !== null && $this->customer_id === (int) $user->getKey(), function () {
                return \App\Services\RequestDetails::location($this->resource, true);
            }),
            'workers_needed' => $this->workers_needed,
            'budget' => $this->budget,
            'budget_basis' => $this->budget_basis,
            'status' => $this->status,
            'posted_at' => $this->created_at->toIso8601String(),
            'applicants' => (int) ($this->quotations_count ?? $this->quotations()->count()),
            'is_sample' => $this->is_sample,
            // How many photos there are, for everyone; the photos themselves only for the people involved
            'photo_count' => $this->relationLoaded('photos') ? $this->photos->count() : $this->photos()->count(),
            'photos' => $this->when($this->relationLoaded('photos') && $this->photosVisibleTo($user), function () {
                return $this->photos->map->present()->values();
            }, []),
            'is_mine' => $user !== null && $this->customer_id === (int) $user->getKey(),
            'has_applied' => $profile !== null
                && $this->quotations()->where('provider_profile_id', $profile->getKey())->exists(),
            // The provider a request was sent to, or who was hired for a posted job
            'provider' => $this->when($this->provider_profile_id !== null, function () {
                return ['id' => $this->provider_profile_id, 'display_name' => $this->provider->display_name];
            }),
            // The provider's reason for declining, for the customer who sent it
            'provider_note' => $this->when(! $this->isPostedJob() && $user !== null && $this->customer_id === (int) $user->getKey(), $this->provider_note),
            'responded_at' => $this->when($this->provider_profile_id !== null, optional($this->responded_at)->toIso8601String()),
            // The customer's own rating of a finished request
            'review' => $this->when($this->provider_profile_id !== null && $user !== null && $this->customer_id === (int) $user->getKey(), function () {
                return $this->review ? ['rating' => $this->review->rating, 'comment' => $this->review->comment] : null;
            }),
        ];
    }
}
