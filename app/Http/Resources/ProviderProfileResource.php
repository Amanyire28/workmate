<?php

namespace App\Http\Resources;

use App\Models\ProfessionSuggestion;
use App\Models\ProviderProfile;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider profile as its owner sees it. Administrators' internal notes
 * are never included.
 */
class ProviderProfileResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $application = $this->latestApplication;
        $suggestions = $this->relationLoaded('professionSuggestions') ? $this->professionSuggestions : $this->professionSuggestions()->with('category')->get();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'type' => $this->type,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'business_registration_number' => $this->business_registration_number,
            'professions' => $this->professions
                ->map(function ($profession) {
                    return [
                        'profession' => $profession->slug,
                        'name' => $profession->name,
                        'years_experience' => $profession->pivot->years_experience === null ? null : (int) $profession->pivot->years_experience,
                        'is_primary' => (bool) $profession->pivot->is_primary,
                    ];
                })
                // Typed under "Other" and not decided yet
                ->concat($suggestions->where('status', ProfessionSuggestion::STATUS_PENDING)->map(function ($suggestion) {
                    return [
                        'profession' => null,
                        'other' => $suggestion->name,
                        'category' => optional($suggestion->category)->slug,
                        'name' => $suggestion->name,
                        'years_experience' => $suggestion->years_experience,
                        'is_primary' => $suggestion->position === 0,
                        'awaiting_approval' => true,
                    ];
                }))
                // Main profession first, then by name (one pass: PHP 7.4 sorts aren't stable)
                ->sortBy(function ($entry) {
                    return [$entry['is_primary'] ? 0 : 1, $entry['name']];
                })
                ->values(),
            // Typed professions WorkMate didn't add, with the reason
            'declined_professions' => $suggestions->where('status', ProfessionSuggestion::STATUS_DECLINED)
                ->where('updated_at', '>=', now()->subDays(60))
                ->map(function ($suggestion) {
                    return ['name' => $suggestion->name, 'reason' => $suggestion->decision_reason];
                })->values(),
            'base_area' => optional($this->baseArea)->slug,
            'service_areas' => $this->serviceAreas->pluck('slug')->values(),
            'skills' => $this->skills ?: [],
            'price_from' => $this->price_from,
            'price_basis' => $this->price_basis,
            'availability_status' => $this->availability_status,
            'availability_note' => $this->availability_note,
            'approved_at' => optional($this->approved_at)->toIso8601String(),
            'suspension_reason' => $this->status === ProviderProfile::STATUS_SUSPENDED ? $this->suspension_reason : null,
            'can_edit' => $this->isEditable(),
            'can_submit' => in_array($this->status, [ProviderProfile::STATUS_DRAFT, ProviderProfile::STATUS_MORE_INFO_REQUIRED], true),
            'application' => $application ? [
                'status' => $application->status,
                'submitted_at' => $application->submitted_at->toIso8601String(),
                'decided_at' => optional($application->decided_at)->toIso8601String(),
                'decision_reason' => $application->decision_reason,
            ] : null,
        ];
    }
}
