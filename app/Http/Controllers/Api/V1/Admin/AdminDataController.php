<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDataController extends Controller
{
    /**
     * Everything the admin screens show, limited to what the signed-in
     * administrator's permissions allow. Small enough to send whole today;
     * large tables move to paged, filtered endpoints as the platform grows.
     */
    public function __invoke(Request $request, AdminSnapshot $snapshot): JsonResponse
    {
        return response()->json(['data' => $snapshot->build($request->user())]);
    }
}
