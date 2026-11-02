<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app checks in while it is open (every presence.heartbeat_seconds), so
 * administrators can see who is online or recently active. Only the time is
 * recorded, never a location.
 */
class PresenceController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        // At most one write every 20 seconds, without touching updated_at
        User::whereKey($request->user()->getKey())
            ->where(function ($q) {
                $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds(20));
            })
            ->toBase()
            ->update(['last_seen_at' => now()]);

        return response()->json(['data' => ['ok' => true]]);
    }
}
