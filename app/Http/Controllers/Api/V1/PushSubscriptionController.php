<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\PushNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushSubscriptionController extends Controller
{
    /** The public key browsers need to subscribe; null while push isn't set up */
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => PushNotifications::configured(),
            'public_key' => PushNotifications::configured() ? config('services.webpush.public_key') : null,
        ]]);
    }

    public function store(Request $request, PushNotifications $push): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', Rule::in(PushSubscription::ENCODINGS)],
            'platform' => ['nullable', Rule::in(PushSubscription::PLATFORMS)],
        ]);

        $push->subscribe($request->user(), $data);

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    public function destroy(Request $request, PushNotifications $push): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        $push->unsubscribe($request->user(), $data['endpoint']);

        return response()->json(['data' => ['subscribed' => false]]);
    }
}
