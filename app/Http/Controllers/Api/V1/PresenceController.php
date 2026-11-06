<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The app checks in while it is open (every presence.heartbeat_seconds), so
 * administrators can see who is online or recently active, and how many
 * people use WorkMate each day. Only the time and the day are recorded,
 * never a location.
 */
class PresenceController extends Controller
{
    /** Also returns the unread notification count, so the bell stays current without polling of its own */
    public function heartbeat(Request $request, UserNotifications $notifications): JsonResponse
    {
        // At most one write every 20 seconds, without touching updated_at
        $updated = User::whereKey($request->user()->getKey())
            ->where(function ($q) {
                $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds(20));
            })
            ->toBase()
            ->update(['last_seen_at' => now()]);

        // One row per person per day they use the app (daily active users)
        if ($updated) {
            DB::table('user_activity_days')->insertOrIgnore(['user_id' => $request->user()->getKey(), 'active_on' => now()->toDateString()]);
        }

        return response()->json(['data' => ['ok' => true, 'unread_notifications' => $notifications->unreadCount($request->user())]]);
    }
}
