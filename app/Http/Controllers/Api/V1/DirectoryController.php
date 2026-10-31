<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AreaResource;
use App\Http\Resources\PublicProviderResource;
use App\Http\Resources\ReviewResource;
use App\Models\Area;
use App\Models\ProviderProfile;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public listings: areas, providers and reviews. */
class DirectoryController extends Controller
{
    /**
     * Everything the website needs on its first page, in one request:
     * categories, areas, providers, recent reviews and open jobs.
     */
    public function catalog(Request $request): JsonResponse
    {
        $data = [
            'categories' => app(CategoryController::class)->index(),
            'areas' => $this->areas(),
            'providers' => $this->providers(),
            'reviews' => $this->recentReviews(),
            'jobs' => app(JobController::class)->index(),
        ];

        return response()->json(['data' => array_map(function ($collection) use ($request) {
            return $collection->resolve($request);
        }, $data)]);
    }

    public function areas()
    {
        return AreaResource::collection(Area::active()->orderBy('sort_order')->orderBy('name')->get());
    }

    /**
     * Every provider customers can find. Small enough to send whole for now;
     * server-side nearby search and paging come with location matching.
     */
    public function providers()
    {
        return PublicProviderResource::collection($this->listed()->orderBy('id')->limit(500)->get());
    }

    public function provider(int $id): PublicProviderResource
    {
        $provider = $this->listed()
            ->with(['reviews' => function ($query) {
                $query->published()->latest('reviewed_at')->limit(30);
            }])
            ->findOrFail($id);

        return new PublicProviderResource($provider);
    }

    public function recentReviews()
    {
        $reviews = Review::published()
            ->whereHas('provider', function ($provider) {
                $provider->publiclyListed();
            })
            ->latest('reviewed_at')
            ->limit(60)
            ->get();

        return ReviewResource::collection($reviews);
    }

    private function listed()
    {
        return ProviderProfile::publiclyListed()
            ->with(['owner:id,name,avatar_path', 'professions.category', 'baseArea', 'serviceAreas'])
            ->withCount(['reviews as published_reviews_count' => function ($query) {
                $query->published();
            }])
            ->withAvg(['reviews as published_reviews_avg_rating' => function ($query) {
                $query->published();
            }], 'rating');
    }
}
