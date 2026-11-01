<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in account's own requests, posted jobs and applications. */
class MyActivityController extends Controller
{
    public function requests(Request $request)
    {
        $requests = ServiceRequest::where('customer_id', $request->user()->getKey())
            ->with(['category', 'area', 'provider', 'review'])
            ->withCount('quotations')
            ->latest()
            ->limit(200)
            ->get();

        return JobResource::collection($requests);
    }

    public function applications(Request $request): JsonResponse
    {
        $profile = $request->user()->providerProfile;

        if (! $profile) {
            return response()->json(['data' => []]);
        }

        $applications = Quotation::where('provider_profile_id', $profile->getKey())
            ->with('request.category')
            ->latest()
            ->limit(200)
            ->get()
            ->map(function (Quotation $quotation) {
                $job = $quotation->request;

                return [
                    'id' => $quotation->id,
                    'status' => $quotation->status,
                    'message' => $quotation->message,
                    'start_time' => $quotation->start_time,
                    'amount' => $quotation->amount,
                    'applied_at' => $quotation->created_at->toIso8601String(),
                    'job' => [
                        'id' => $job->id,
                        'title' => $job->title,
                        'category' => $job->category->slug,
                        'area_name' => $job->area_name,
                        'budget' => $job->budget,
                        'budget_basis' => $job->budget_basis,
                        'status' => $job->trashed() ? 'cancelled' : $job->status,
                    ],
                ];
            });

        return response()->json(['data' => $applications]);
    }
}
