<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Services\Marketplace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The providers who applied to a job the signed-in customer posted. Only the
 * customer who posted it can see them; anyone else gets "not found".
 */
class JobApplicantController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        $job = $this->ownJob($request, $id);

        $applications = $job->quotations()
            ->with(['provider' => function ($q) {
                $q->with(['owner:id,name,avatar_path', 'professions.category', 'baseArea'])
                    ->withCount(['reviews as published_reviews_count' => function ($r) {
                        $r->published();
                    }])
                    ->withAvg(['reviews as published_reviews_avg_rating' => function ($r) {
                        $r->published();
                    }], 'rating');
            }])
            ->oldest()
            ->get()
            ->map(function (Quotation $q) {
                return $this->present($q);
            })
            ->values();

        return response()->json(['data' => $applications]);
    }

    public function hire(Request $request, Marketplace $marketplace, int $id, int $application): JsonResponse
    {
        $job = $marketplace->hire($request->user(), $id, $application);

        return (new JobResource($job->fresh(['category', 'area', 'provider', 'review'])))->response();
    }

    private function ownJob(Request $request, int $id): ServiceRequest
    {
        return ServiceRequest::where('customer_id', $request->user()->getKey())
            ->where('is_posted_job', true)
            ->findOrFail($id);
    }

    /** What the customer needs to choose: no contact details */
    private function present(Quotation $q): array
    {
        /** @var ProviderProfile|null $p */
        $p = $q->provider;
        $main = $p ? ($p->professions->first(function ($profession) {
            return (bool) $profession->pivot->is_primary;
        }) ?: $p->professions->first()) : null;
        $reviews = (int) ($p->published_reviews_count ?? 0);

        return [
            'id' => $q->id,
            'status' => $q->status,
            'message' => $q->message,
            'start_time' => $q->start_time,
            'amount' => $q->amount,
            'applied_at' => $q->created_at->toIso8601String(),
            'provider' => $p ? [
                'id' => $p->id,
                'display_name' => $p->display_name,
                'avatar_url' => optional($p->owner)->avatarUrl(),
                'main_profession' => $main ? $main->name : null,
                'area' => optional($p->baseArea)->name,
                'rating' => $reviews ? round((float) $p->published_reviews_avg_rating, 2) : null,
                'review_count' => $reviews,
                'document_verified' => $p->document_verified_at !== null,
                'blue_tick' => $p->blue_tick_awarded_at !== null,
                // A provider who can't take work any more can't be hired
                'available' => $p->status === ProviderProfile::STATUS_APPROVED,
            ] : null,
        ];
    }
}
