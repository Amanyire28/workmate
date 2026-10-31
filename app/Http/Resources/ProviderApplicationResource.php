<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** A provider application as reviewers see it. */
class ProviderApplicationResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $profile = $this->profile;
        $owner = $profile->owner;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'submitted' => $this->snapshot,
            'applicant_note' => $this->applicant_note,
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'decided_at' => optional($this->decided_at)->toIso8601String(),
            'decided_by' => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null,
            'decision_reason' => $this->decision_reason,
            'internal_note' => $this->internal_note,
            'provider' => [
                'id' => $profile->id,
                'status' => $profile->status,
                'display_name' => $profile->display_name,
                'type' => $profile->type,
            ],
            'applicant' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'email_verified' => $owner->email_verified_at !== null,
                'phone' => $owner->phone,
                'account_status' => $owner->status,
                'member_since' => $owner->created_at->toIso8601String(),
            ],
        ];
    }
}
