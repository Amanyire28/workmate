<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider as customers see it. No contact details, no account fields.
 * The base location is rounded to about 1 km, and the rating is worked out
 * from published reviews (load with withAvg/withCount on reviews).
 */
class PublicProviderResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $professions = $this->professions->sortBy(function ($profession) {
            return [$profession->pivot->is_primary ? 0 : 1, $profession->name];
        })->values();

        $reviewCount = (int) ($this->published_reviews_count ?? 0);
        $main = $professions->first(function ($profession) {
            return (bool) $profession->pivot->is_primary;
        });

        return [
            'id' => $this->id,
            'name' => $this->owner->name,
            'display_name' => $this->display_name,
            'avatar_url' => $this->owner->avatarUrl(),
            'cover_url' => $this->coverUrl(),
            // How many jobs they show under "Recent work"
            'work_count' => (int) ($this->work_count ?? 0),
            'type' => $this->type,
            'categories' => $professions->map(function ($profession) {
                return $profession->category->slug;
            })->unique()->values(),
            'professions' => $professions->map(function ($profession) {
                return ['slug' => $profession->slug, 'name' => $profession->name, 'is_primary' => (bool) $profession->pivot->is_primary];
            }),
            // Customers nearby find a provider first for their main profession
            'main_profession' => $main ? $main->slug : null,
            'main_category' => $main ? $main->category->slug : null,
            'base_area' => $this->baseArea ? ['slug' => $this->baseArea->slug, 'name' => $this->baseArea->name] : null,
            'latitude' => $this->latitude === null ? null : round($this->latitude, 2),
            'longitude' => $this->longitude === null ? null : round($this->longitude, 2),
            'service_areas' => $this->serviceAreas->pluck('slug')->values(),
            'bio' => $this->bio,
            'skills' => $this->skills ?: [],
            'availability_status' => $this->availability_status,
            // What customers are told: available | busy | paused (preferences plus real bookings)
            'work_status' => $this->workStatus(),
            'accepts_immediate' => (bool) $this->accepts_immediate,
            'accepts_bookings' => (bool) $this->accepts_bookings,
            // "Online now" / "Last seen …", unless they chose to hide it. Only a time, to the minute.
            'presence' => $this->presence(),
            'availability_note' => $this->availability_note,
            'next_available' => $this->next_available,
            'price_from' => $this->price_from,
            'price_basis' => $this->price_basis,
            'rating' => $reviewCount ? round((float) $this->published_reviews_avg_rating, 2) : null,
            'review_count' => $reviewCount,
            'document_verified' => $this->document_verified_at !== null,
            'blue_tick' => $this->blue_tick_awarded_at !== null,
            'is_sample' => $this->is_sample,
            'reviews' => ReviewResource::collection($this->whenLoaded('reviews')),
        ];
    }

    private function presence(): ?array
    {
        $owner = $this->owner;
        if (! $owner || $owner->show_last_seen === false) {
            return null;
        }
        $seen = $owner->last_seen_at;
        // Read once per request, however many providers are listed
        $attributes = request()->attributes;
        if (! $attributes->has('presence_online_minutes')) {
            $attributes->set('presence_online_minutes', (int) app(\App\Services\PlatformSettings::class)->get('presence.online_minutes'));
        }
        $window = $attributes->get('presence_online_minutes');

        return [
            'online' => $seen !== null && $seen->gte(now()->subMinutes($window)),
            'last_seen_at' => $seen ? $seen->copy()->startOfMinute()->toIso8601String() : null,
        ];
    }
}
