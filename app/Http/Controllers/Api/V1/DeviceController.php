<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeviceSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Account → Devices: where this account is signed in */
class DeviceController extends Controller
{
    /** @var DeviceSessions */
    private $devices;

    public function __construct(DeviceSessions $devices)
    {
        $this->devices = $devices;
    }

    public function index(Request $request): JsonResponse
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;

        return response()->json(['data' => [
            'available' => $this->devices->available(),
            'devices' => $this->devices->forUser($request->user(), $current),
        ]]);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $this->devices->signOut($request->user(), $key, $request);

        return new JsonResponse(null, 204);
    }

    public function signOutOthers(Request $request): JsonResponse
    {
        $count = $this->devices->signOutOthers($request->user(), $request);

        return response()->json(['data' => ['signed_out' => $count]]);
    }
}
