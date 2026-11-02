<?php

namespace App\Http\Resources;

use App\Models\DocumentRequest;
use App\Models\DocumentVerification;
use App\Models\VerificationDocument;
use App\Services\DocumentRequirements;
use Illuminate\Http\Resources\Json\JsonResource;

/** A provider's document verification as they see it. No storage paths. */
class DocumentVerificationResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $documents = $this->documents->whereNotIn('status', [VerificationDocument::STATUS_REPLACED, VerificationDocument::STATUS_REMOVED]);
        $openRequests = $this->requests->where('status', DocumentRequest::STATUS_OPEN);

        return [
            'status' => $this->status,
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'decided_at' => optional($this->decided_at)->toIso8601String(),
            'decision_reason' => $this->decision_reason,
            'documents' => $documents->sortBy(function ($d) {
                return [$d->kind === VerificationDocument::KIND_NATIONAL_ID ? 0 : 1, $d->id];
            })->map(function (VerificationDocument $d) {
                return [
                    'id' => $d->uuid,
                    'kind' => $d->kind,
                    'requirement' => $d->requirement_key,
                    'label' => $d->label,
                    'status' => $d->status,
                    'note' => $d->status === VerificationDocument::STATUS_REPLACEMENT_REQUIRED ? $d->review_note : null,
                    'expires_on' => optional($d->expires_on)->toDateString(),
                    'request' => $d->document_request_id,
                    'files' => $d->publicFiles(),
                ];
            })->values(),
            'requests' => $openRequests->map(function (DocumentRequest $r) {
                return ['id' => $r->id, 'label' => $r->label, 'instructions' => $r->instructions, 'requested_at' => $r->created_at->toIso8601String()];
            })->values(),
            // The documents their main profession needs (null when it needs none)
            'requirements' => app(DocumentRequirements::class)->present($this->resource, $documents),
            'max_supporting' => DocumentVerification::MAX_SUPPORTING,
            'can_edit' => $this->isEditable(),
            'can_submit' => in_array($this->status, [DocumentVerification::STATUS_DRAFT, DocumentVerification::STATUS_INFO_REQUESTED], true),
        ];
    }
}
