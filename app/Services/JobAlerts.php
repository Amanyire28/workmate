<?php

namespace App\Services;

use App\Models\Area;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tells providers about a new posted job they could take: approved providers
 * with a skill in the job's profession who are within the wider search radius
 * (Admin → Settings → search.extended_km) or list the job's place among the
 * places they serve. Closest first, at most 40 per job and 10 alerts per
 * provider a day, so nobody is flooded. Providers can turn these off.
 */
class JobAlerts
{
    public const MAX_PROVIDERS = 40;
    public const DAILY_LIMIT = 10;

    /** @var EventNotifications */
    private $notify;

    /** @var PlatformSettings */
    private $settings;

    public function __construct(EventNotifications $notify, PlatformSettings $settings)
    {
        $this->notify = $notify;
        $this->settings = $settings;
    }

    /** Never stops a job being posted: problems are logged */
    public function jobPosted(ServiceRequest $job): void
    {
        try {
            $people = $this->recipients($job);
            if ($people) {
                $this->notify->jobPosted($job, $people);
            }
        } catch (\Throwable $e) {
            Log::warning('Job alerts not sent: '.$e->getMessage(), ['job' => $job->id]);
        }
    }

    /** @return int[] the accounts to tell, closest providers first */
    public function recipients(ServiceRequest $job): array
    {
        $area = $job->area_id ? Area::find($job->area_id) : null;
        $radius = (float) $this->settings->get('search.extended_km');
        $customerBusinesses = ProviderMember::where('user_id', $job->customer_id)->pluck('provider_profile_id');
        // Providers who list this place among the places they serve
        $serving = $job->area_id ? DB::table('provider_service_areas')->where('area_id', $job->area_id)->pluck('provider_profile_id')->flip() : collect();

        $matches = ProviderProfile::publiclyListed()
            ->where('is_sample', false)
            ->whereNotIn('id', $customerBusinesses->all() ?: [0])
            ->whereHas('professions', function ($q) use ($job) {
                $q->where('category_id', $job->category_id);
            })
            ->with('baseArea')
            ->get()
            ->map(function (ProviderProfile $p) use ($area, $radius, $job, $serving) {
                $distance = $this->distanceKm($p, $area);
                $serves = isset($serving[$p->id]);
                $sameBase = $job->area_id && $p->base_area_id === $job->area_id;

                return ($distance !== null && $distance <= $radius) || $serves || $sameBase
                    ? ['id' => $p->id, 'distance' => $distance ?? 0.0] : null;
            })
            ->filter()
            ->sortBy('distance')
            ->take(self::MAX_PROVIDERS);

        return $this->withinDailyLimit($this->membersOf($matches->pluck('id')), $job->customer_id);
    }

    /** Everyone in each matching business, except the customer who posted */
    private function membersOf(Collection $providerIds): array
    {
        if ($providerIds->isEmpty()) {
            return [];
        }

        return ProviderMember::whereIn('provider_profile_id', $providerIds->all())->pluck('user_id')->unique()->values()->all();
    }

    /** @param int[] $userIds */
    private function withinDailyLimit(array $userIds, int $customerId): array
    {
        $today = now()->toDateString();

        return array_values(array_filter($userIds, function ($id) use ($customerId, $today) {
            if ((int) $id === $customerId) {
                return false;
            }
            $key = "job-alerts:{$id}:{$today}";
            Cache::add($key, 0, now()->endOfDay());

            return Cache::increment($key) <= self::DAILY_LIMIT;
        }));
    }

    /** Straight-line distance from the provider (their pin, else their base place) to the job's place */
    private function distanceKm(ProviderProfile $p, ?Area $area): ?float
    {
        $lat = $p->latitude ?? optional($p->baseArea)->latitude;
        $lng = $p->longitude ?? optional($p->baseArea)->longitude;
        if (! $area || $area->latitude === null || $lat === null || $lng === null) {
            return null;
        }
        $rad = M_PI / 180;
        $dLat = ($area->latitude - $lat) * $rad;
        $dLng = ($area->longitude - $lng) * $rad;
        $a = sin($dLat / 2) ** 2 + cos($lat * $rad) * cos($area->latitude * $rad) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
