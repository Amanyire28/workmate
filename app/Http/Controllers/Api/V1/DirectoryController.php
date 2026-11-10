<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AreaResource;
use App\Http\Resources\PublicProviderResource;
use App\Http\Resources\ReviewResource;
use App\Models\Area;
use App\Models\ProviderProfile;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Services\PlatformSettings;
use App\Services\ProviderRecommendations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        }, $data) + [
            // Settings administrators change that the app applies
            'settings' => app(PlatformSettings::class)->publicValues(),
        ]]);
    }

    /** Just the settings the app applies, for refreshing them while it's open */
    public function settings(PlatformSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->publicValues()]);
    }

    /**
     * The quick-pick areas, plus every place a listed provider or an open
     * job uses, so pages can place them on the map. Other places are found
     * by searching (see search below).
     */
    public function areas()
    {
        $listed = ProviderProfile::publiclyListed()->select('id');
        $used = Area::query()
            ->whereIn('id', ProviderProfile::publiclyListed()->whereNotNull('base_area_id')->select('base_area_id'))
            ->orWhereIn('id', DB::table('provider_service_areas')->whereIn('provider_profile_id', $listed)->select('area_id'))
            ->orWhereIn('id', ServiceRequest::openJobs()->whereNotNull('area_id')->select('area_id'))
            ->pluck('id');

        $areas = Area::active()
            ->where(function ($query) use ($used) {
                $query->where('is_featured', true)->orWhereIn('id', $used)
                    // The default place searches start from
                    ->orWhere('slug', app(PlatformSettings::class)->get('platform.default_area'));
            })
            ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('name')
            ->get();

        return AreaResource::collection($areas);
    }

    /**
     * Type-to-search over every place in Uganda. Given where the person is
     * searching from (near), closer places come first.
     */
    public function searchAreas(Request $request)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:60'],
            'near_latitude' => ['nullable', 'numeric', 'between:-2,5', 'required_with:near_longitude'],
            'near_longitude' => ['nullable', 'numeric', 'between:29,36', 'required_with:near_latitude'],
        ]);
        $q = trim(preg_replace('/\s+/', ' ', $data['q']));
        // Letters only: % and _ would act as wildcards
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q);

        $query = Area::active()
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like.'%')
                    ->orWhere('name', 'like', '% '.$like.'%')
                    ->orWhere('district', 'like', $like.'%');
            })
            // Names that start with the text, then quick picks
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$like.'%'])
            ->orderByRaw('is_featured DESC');

        if (isset($data['near_latitude'])) {
            // Squared degrees are enough to sort by; towns count as about 30 km closer
            $query->orderByRaw(
                "POW(latitude - ?, 2) + POW(longitude - ?, 2) - CASE WHEN kind = 'town' THEN 0.08 ELSE 0 END",
                [(float) $data['near_latitude'], (float) $data['near_longitude']]
            );
        } else {
            $query->orderByRaw("CASE kind WHEN 'town' THEN 0 WHEN 'suburb' THEN 1 ELSE 2 END");
        }

        return AreaResource::collection($query->orderByRaw('CHAR_LENGTH(name)')->orderBy('name')->limit(12)->get());
    }

    /**
     * The listed place nearest to a position, to name where someone is. The
     * position is used for this lookup only and isn't stored.
     */
    public function nearestArea(Request $request)
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-2,5'],
            'longitude' => ['required', 'numeric', 'between:29,36'],
        ]);
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];

        // Look close by first, then further out
        foreach ([0.05, 0.2, 1.0] as $box) {
            $candidates = Area::active()->where('is_whole_town', false)
                ->whereBetween('latitude', [$lat - $box, $lat + $box])
                ->whereBetween('longitude', [$lng - $box, $lng + $box])
                ->get();
            if ($candidates->isNotEmpty()) {
                $nearest = $candidates->sortBy(function (Area $a) use ($lat, $lng) {
                    // Quick picks win when about as close
                    return Area::distanceKm($lat, $lng, $a->latitude, $a->longitude) - ($a->is_featured ? 1.5 : 0);
                })->first();

                return new AreaResource($nearest);
            }
        }

        return response()->json(['data' => null]);
    }

    /**
     * Every provider customers can find. Small enough to send whole for now;
     * server-side nearby search and paging come with location matching.
     */
    public function providers()
    {
        return PublicProviderResource::collection($this->listed()->orderBy('id')->limit(500)->get());
    }

    /**
     * Recommended workers for the home page: provider ids in order, and how
     * far the search had to reach from the place (see ProviderRecommendations).
     * The app already has the providers, so only ids travel.
     */
    public function recommended(Request $request, ProviderRecommendations $recommendations): JsonResponse
    {
        $data = $request->validate([
            'area' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);
        $area = ! empty($data['area']) ? Area::active()->where('slug', $data['area'])->first() : null;
        $result = $recommendations->recommend($area, $data['category'] ?? null);

        return response()->json(['data' => $result + [
            'area' => $area ? ['slug' => $area->slug, 'name' => $area->name, 'town' => $area->town, 'whole_town' => (bool) $area->is_whole_town] : null,
        ]]);
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
            }, 'workItems as work_count' => function ($query) {
                $query->published();
            }])
            ->withAvg(['reviews as published_reviews_avg_rating' => function ($query) {
                $query->published();
            }], 'rating');
    }
}
