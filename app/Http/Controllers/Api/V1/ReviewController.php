<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\ServiceRequest;
use App\Services\Reviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in customer rating providers who finished work for them */
class ReviewController extends Controller
{
    /** @var Reviews */
    private $reviews;

    public function __construct(Reviews $reviews)
    {
        $this->reviews = $reviews;
    }

    /** Finished jobs to ask about when the app opens */
    public function waiting(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reviews->waiting($request->user())->map(function (ServiceRequest $r) {
            return [
                'request_id' => $r->id,
                'title' => $r->title,
                'category' => $r->category->slug,
                'finished_at' => optional($r->updated_at)->toIso8601String(),
                'provider' => [
                    'id' => $r->provider_profile_id,
                    'display_name' => $r->provider->display_name,
                    'avatar_url' => $r->provider->owner->avatarUrl(),
                ],
            ];
        })->values()]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], ['rating.required' => 'Choose how many stars to give.']);

        return (new ReviewResource($this->reviews->rate($request->user(), $id, $data)))->response()->setStatusCode(201);
    }

    public function later(Request $request, int $id): JsonResponse
    {
        $this->reviews->later($request->user(), $id);

        return response()->json(['data' => ['request_id' => $id]]);
    }
}
