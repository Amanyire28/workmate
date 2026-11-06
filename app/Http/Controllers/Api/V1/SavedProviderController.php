<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Providers the signed-in account saved to come back to */
class SavedProviderController extends Controller
{
    /** Newest first; providers no longer listed are marked so they can be removed */
    public function index(Request $request): JsonResponse
    {
        $saved = DB::table('saved_providers')->where('user_id', $request->user()->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->get(['provider_profile_id', 'created_at']);
        $listed = ProviderProfile::publiclyListed()->whereIn('id', $saved->pluck('provider_profile_id')->all() ?: [0])->pluck('id')->flip();

        return response()->json(['data' => $saved->map(function ($row) use ($listed) {
            return [
                'provider_id' => (int) $row->provider_profile_id,
                'saved_at' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
                'available' => isset($listed[$row->provider_profile_id]),
            ];
        })->values()]);
    }

    public function store(Request $request, int $provider): JsonResponse
    {
        $profile = ProviderProfile::publiclyListed()->findOrFail($provider);
        if (ProviderMember::where('provider_profile_id', $profile->id)->where('user_id', $request->user()->getKey())->exists()) {
            throw new BusinessRuleException('own_provider', 'You can’t save your own provider profile.', 422);
        }
        // Saving twice keeps one entry
        DB::table('saved_providers')->insertOrIgnore([
            'user_id' => $request->user()->getKey(), 'provider_profile_id' => $profile->id, 'created_at' => now(),
        ]);

        return response()->json(['data' => ['provider_id' => $profile->id, 'saved' => true]]);
    }

    /** Works for providers no longer listed too, so they can be cleared from the list */
    public function destroy(Request $request, int $provider): JsonResponse
    {
        DB::table('saved_providers')->where('user_id', $request->user()->getKey())->where('provider_profile_id', $provider)->delete();

        return response()->json(['data' => ['provider_id' => $provider, 'saved' => false]]);
    }
}
