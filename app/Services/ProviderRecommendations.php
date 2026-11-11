<?php

namespace App\Services;

use App\Models\Area;
use App\Models\ProviderProfile;
use App\Models\Review;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Recommended workers" on the home page.
 *
 * 1. Reach: start with providers based in or serving the place, then widen
 *    step by step (5 km, 15 km, the same town or district, anywhere) until
 *    there are enough. The answer says how far it went, so the page can be
 *    honest about it.
 * 2. Order within that reach: a fair rating that starts every provider at
 *    the platform average (so no reviews isn't a penalty), small boosts for
 *    checked documents, the blue tick, recent activity and availability set
 *    in the last two weeks, a nudge for being closer, and the service asked for.
 * 3. Fair exposure: up to two places go to providers approved in the last
 *    30 days, and near-equal scores are shuffled once a day.
 */
class ProviderRecommendations
{
    public const LIMIT = 8;

    /** Enough providers within a reach to stop widening it */
    private const ENOUGH = 4;

    /** Reaches, nearest first */
    public const REACH_AREA = 'area';
    public const REACH_5KM = '5km';
    public const REACH_15KM = '15km';
    public const REACH_TOWN = 'town';
    public const REACH_ANYWHERE = 'anywhere';
    private const REACHES = [self::REACH_AREA, self::REACH_5KM, self::REACH_15KM, self::REACH_TOWN, self::REACH_ANYWHERE];

    /** How many reviews it takes for a provider's own average to count fully */
    private const RATING_WEIGHT = 5;
    private const NEW_PROVIDER_DAYS = 30;
    private const NEW_PROVIDER_PLACES = 2;
    private const RECENT_DAYS = 14;

    /**
     * @param  Area|null  $origin   the place, if one was given or found
     * @param  string|null  $category  a service category slug the person cares about
     * @param  array{latitude: float, longitude: float}|null  $point  the person's current
     *         location (about 1 km precision): distances are measured from it, like the
     *         nearby search, instead of from the place's centre
     * @return array{reach: string, category_matched: bool, ids: int[]}
     */
    public function recommend(?Area $origin, ?string $category = null, ?array $point = null): array
    {
        $at = $point ? $point['latitude'].','.$point['longitude'] : '-';
        $key = 'recommended:'.($origin ? $origin->id : 'none').':'.$at.':'.($category ?: '-').':'.now()->toDateString();

        return Cache::remember($key, now()->addMinutes(5), function () use ($origin, $category, $point) {
            return $this->rank($origin, $category, $point);
        });
    }

    /** @var array{boost: float, window: int} presence signals, read once per ranking */
    private $presence = ['boost' => 0.15, 'window' => 5];

    private function rank(?Area $origin, ?string $category, ?array $point = null): array
    {
        $settings = app(PlatformSettings::class);
        $this->presence = [
            'boost' => max(0, min(30, (int) $settings->get('search.online_boost'))) / 100,
            'window' => (int) $settings->get('presence.online_minutes'),
        ];
        $providers = $this->eligible();

        // The service asked for, when anyone offers it
        $categoryMatched = false;
        if ($category) {
            $offering = $providers->filter(function (ProviderProfile $p) use ($category) {
                return $p->professions->contains(function ($prof) use ($category) {
                    return optional($prof->category)->slug === $category;
                });
            });
            if ($offering->isNotEmpty()) {
                $providers = $offering;
                $categoryMatched = true;
            }
        }

        // How far each provider is from the place, as a reach step
        $steps = $providers->mapWithKeys(function (ProviderProfile $p) use ($origin, $point) {
            return [$p->id => $origin || $point ? $this->step($p, $origin, $point) : count(self::REACHES) - 1];
        });

        // Widen until there are enough
        $reach = count(self::REACHES) - 1;
        foreach (array_keys(self::REACHES) as $i) {
            if ($steps->filter(function ($s) use ($i) { return $s <= $i; })->count() >= self::ENOUGH) {
                $reach = $i;
                break;
            }
        }
        $inReach = $providers->filter(function (ProviderProfile $p) use ($steps, $reach) {
            return $steps[$p->id] <= $reach;
        });

        $average = $this->platformAverage();
        $scored = $inReach->map(function (ProviderProfile $p) use ($steps, $average, $category) {
            return ['provider' => $p, 'score' => $this->score($p, $steps[$p->id], $average, $category)];
        })->sortByDesc('score')->values();

        $picked = $this->withNewProviders($scored);

        return [
            'reach' => self::REACHES[$reach],
            'category_matched' => $categoryMatched,
            'ids' => $picked->map(function ($row) {
                return $row['provider']->id;
            })->values()->all(),
        ];
    }

    /** Approved, listed providers whose owners are active; sample profiles only on a developer's machine */
    private function eligible(): Collection
    {
        $query = ProviderProfile::publiclyListed()
            ->with(['professions.category', 'serviceAreas:id', 'baseArea:id,town,district', 'owner:id,last_seen_at'])
            ->withCount(['reviews as published_reviews_count' => function ($q) {
                $q->published();
            }])
            ->withAvg(['reviews as published_reviews_avg_rating' => function ($q) {
                $q->published();
            }], 'rating')
            ->withMax(['workItems as latest_work_at' => function ($q) {
                $q->published();
            }], 'created_at')
            ->limit(2000);
        if (! app()->environment('local')) {
            $query->where('is_sample', false);
        }

        return $query->get();
    }

    /**
     * 0 = in or serving the place, 1 = within 5 km, 2 = within 15 km, 3 = same town or district, 4 = further.
     * Distances are from the person's current location when given, else from the place's centre.
     */
    private function step(ProviderProfile $p, ?Area $origin, ?array $point = null): int
    {
        if ($origin && ($p->base_area_id === $origin->id || $p->serviceAreas->contains('id', $origin->id))) {
            return 0;
        }
        $from = $point ?: ($origin && $origin->latitude !== null ? ['latitude' => (float) $origin->latitude, 'longitude' => (float) $origin->longitude] : null);
        if ($p->latitude !== null && $from) {
            $km = Area::distanceKm($from['latitude'], $from['longitude'], $p->latitude, $p->longitude);
            if ($km <= 5) {
                return 1;
            }
            if ($km <= 15) {
                return 2;
            }
        }
        $base = $p->baseArea;
        if ($origin && $base && (($origin->town && $base->town === $origin->town) || ($origin->district && $base->district === $origin->district))) {
            return 3;
        }

        return 4;
    }

    private function score(ProviderProfile $p, int $step, float $average, ?string $category): float
    {
        // Fair rating: the platform average, moving towards their own as reviews come in
        $n = (int) $p->published_reviews_count;
        $own = $n ? (float) $p->published_reviews_avg_rating : $average;
        $fair = (self::RATING_WEIGHT * $average + $n * $own) / (self::RATING_WEIGHT + $n);
        $score = ($fair - 1) / 4;

        if ($p->document_verified_at) {
            $score += 0.3;
        }
        if ($p->blue_tick_awarded_at) {
            $score += 0.4;
        }

        $recent = now()->subDays(self::RECENT_DAYS);
        $seen = optional($p->owner)->last_seen_at;
        if (($seen && $seen->gte($recent)) || ($p->latest_work_at && \Carbon\Carbon::parse($p->latest_work_at)->gte($recent))) {
            $score += 0.2;
        }
        // Only an answer they gave recently
        if ($p->availability_updated_at && $p->availability_updated_at->gte($recent)) {
            $score += ['available' => 0.25, 'busy' => 0.0, 'unavailable' => -0.3][$p->availability_status] ?? 0.0;
        }
        // Online now, or active today: a small, bounded edge between otherwise similar providers
        if ($seen && $seen->gte(now()->subMinutes($this->presence['window']))) {
            $score += $this->presence['boost'];
        } elseif ($seen && $seen->gte(now()->subDay())) {
            $score += $this->presence['boost'] / 2;
        }
        // Paused: still listed, after those taking requests
        if ($p->accepts_immediate === false && $p->accepts_bookings === false) {
            $score -= 0.4;
        }
        if ($category && optional(optional($this->main($p))->category)->slug === $category) {
            $score += 0.3;
        }
        $score -= 0.25 * $step;

        // Near-equal scores take turns, changing once a day
        return $score + (crc32($p->id.'|'.now()->toDateString()) % 1000) / 20000;
    }

    private function main(ProviderProfile $p)
    {
        return $p->professions->first(function ($prof) {
            return (bool) $prof->pivot->is_primary;
        }) ?: $p->professions->first();
    }

    /** Keeps up to two places for providers approved in the last 30 days */
    private function withNewProviders(Collection $scored): Collection
    {
        $since = now()->subDays(self::NEW_PROVIDER_DAYS);
        $isNew = function ($row) use ($since) {
            return $row['provider']->approved_at && $row['provider']->approved_at->gte($since);
        };

        $top = $scored->take(self::LIMIT);
        $missing = self::NEW_PROVIDER_PLACES - $top->filter($isNew)->count();
        $waiting = $scored->slice(self::LIMIT)->filter($isNew)->take(max(0, $missing));
        if ($waiting->isEmpty()) {
            return $top;
        }

        // They replace the lowest-placed established providers, then everyone is ordered by score again
        $established = $top->reject($isNew);
        $keep = $top->filter($isNew)->merge($established->take($established->count() - $waiting->count()));

        return $keep->merge($waiting)->sortByDesc('score')->values();
    }

    /** The average review, leaning on a neutral 4 stars until there are plenty of reviews */
    private function platformAverage(): float
    {
        return Cache::remember('recommended:average', now()->addMinutes(30), function () {
            $row = Review::published()->selectRaw('COUNT(*) AS n, COALESCE(SUM(rating), 0) AS total')->first();

            return (20 * 4.0 + (float) $row->total) / (20 + (int) $row->n);
        });
    }
}
