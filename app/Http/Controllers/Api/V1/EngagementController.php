<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Services\ProfileCompleteness;
use App\Services\Recommendations;
use App\Services\Suggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's profile completeness, the suggestion cards shown in
 * the pages, and what WorkMate recommends to them. None of it is ever sent
 * as a notification: it's shown when people open the app.
 */
class EngagementController extends Controller
{
    public function completeness(Request $request, ProfileCompleteness $completeness): JsonResponse
    {
        return response()->json(['data' => $completeness->forUser($request->user())]);
    }

    /** The cards that apply now, and which one each page shows */
    public function suggestions(Request $request, Suggestions $suggestions): JsonResponse
    {
        return response()->json(['data' => $suggestions->forUser($request->user())]);
    }

    /** Suitable open jobs (public details only: never a job's exact location) and community posts */
    public function recommendations(Request $request, Recommendations $recommendations): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'jobs' => JobResource::collection($recommendations->jobsFor($user))->resolve($request),
            'posts' => $recommendations->postsFor($user),
        ]]);
    }
}
