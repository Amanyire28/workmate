<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppInstallEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppEventController extends Controller
{
    /**
     * Records an install event from the web app. Anyone may send one (people
     * install before signing in), so it stores nothing personal, and repeats
     * from the same device are ignored rather than counted again.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'uuid'],
            'event' => ['required', Rule::in(AppInstallEvent::EVENTS)],
            'platform' => ['required', Rule::in(AppInstallEvent::PLATFORMS)],
            'source' => ['nullable', Rule::in(AppInstallEvent::SOURCES)],
        ]);

        $deviceId = strtolower($data['device_id']);
        $once = in_array($data['event'], AppInstallEvent::ONCE_PER_DEVICE, true);
        $seen = $once && AppInstallEvent::where('device_id', $deviceId)->where('event', $data['event'])->exists();

        if (! $seen) {
            // The unique index drops a second report for the same device and day
            AppInstallEvent::query()->insertOrIgnore([
                'device_id' => $deviceId,
                'event' => $data['event'],
                'platform' => $data['platform'],
                'source' => $data['event'] === 'installed' ? ($data['source'] ?? 'browser') : null,
                'occurred_on' => now()->toDateString(),
                'created_at' => now(),
            ]);
        }

        return response()->json(['data' => ['recorded' => true]], 202);
    }

    /**
     * Counts a search (and how it turned out) or a provider profile view.
     * Only the day's total goes up: no person, device, place or search text
     * is stored, so these figures are approximate by design.
     */
    public function count(Request $request): JsonResponse
    {
        $data = $request->validate([
            'metric' => ['required', Rule::in(['search', 'profile_view'])],
            'outcome' => ['nullable', 'required_if:metric,search', Rule::in(['nearby', 'wider', 'none'])],
        ]);

        $metrics = [$data['metric']];
        if ($data['metric'] === 'search') {
            $metrics[] = 'search_'.$data['outcome'];
        }
        foreach ($metrics as $metric) {
            DB::statement('INSERT INTO app_daily_counts (counted_on, metric, total) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE total = total + 1', [now()->toDateString(), $metric]);
        }

        return response()->json(['data' => ['recorded' => true]], 202);
    }
}
