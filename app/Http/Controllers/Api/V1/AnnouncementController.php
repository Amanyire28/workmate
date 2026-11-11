<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "What's New on WorkMate": the release announcement (config/announcements.php),
 * and remembering who has seen it. Kept apart from notifications, chat and
 * booking updates.
 */
class AnnouncementController extends Controller
{
    /**
     * The announcement to show, if one is switched on and meant for this
     * person, with whether they've already seen it (signed in; guests keep
     * that in their own browser).
     */
    public function current(Request $request): JsonResponse
    {
        $a = config('announcements.current');
        $user = $request->user('sanctum');

        if (! $this->live($a) || ! $this->forAudience($a['audience'] ?? 'everyone', $user !== null)) {
            return response()->json(['data' => null]);
        }

        $seen = $user !== null && DB::table('announcement_acknowledgements')
            ->where('user_id', $user->getKey())->where('announcement_id', $a['id'])->exists();

        return response()->json(['data' => [
            'id' => $a['id'],
            'header' => $a['header'],
            'title' => $a['title'],
            'summary' => $a['summary'],
            'items' => $a['items'],
            'closing' => $a['closing'],
            'primary_action' => $a['primary_action'],
            'secondary_action' => $a['secondary_action'],
            'display_seconds' => (int) $a['display_seconds'],
            'delay_seconds' => (int) $a['delay_seconds'],
            'acknowledged' => $seen,
        ]]);
    }

    /** Seen: closed, read in full, or left to go by itself. Once per person and announcement. */
    public function acknowledge(Request $request, string $announcement): JsonResponse
    {
        $data = $request->validate(['how' => ['nullable', Rule::in(['dismissed', 'read', 'expired'])]]);
        abort_unless(in_array($announcement, (array) config('announcements.known', []), true), 404);

        // A second acknowledgement (another device, a retry) changes nothing
        DB::table('announcement_acknowledgements')->insertOrIgnore([
            'user_id' => $request->user()->getKey(),
            'announcement_id' => $announcement,
            'how' => $data['how'] ?? 'dismissed',
            'acknowledged_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $announcement, 'acknowledged' => true]]);
    }

    /** Switched on, and past its optional start; the date alone never switches it on */
    private function live(?array $a): bool
    {
        if (! $a || empty($a['enabled']) || empty($a['id'])) {
            return false;
        }
        if (! empty($a['starts_at'])) {
            try {
                return Carbon::parse($a['starts_at'])->lte(now());
            } catch (\Throwable $e) {
                return false;
            }
        }

        return true;
    }

    private function forAudience(string $audience, bool $signedIn): bool
    {
        if ($audience === 'signed_in') {
            return $signedIn;
        }
        if ($audience === 'guests') {
            return ! $signedIn;
        }

        return true;
    }
}
