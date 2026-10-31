<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppInstallEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
