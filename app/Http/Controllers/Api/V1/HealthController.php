<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Public liveness check for the frontend and uptime monitors. Reports no
     * internal details; full system health is an admin-only module.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'api_version' => 'v1',
                'time' => now()->toIso8601String(),
            ],
        ]);
    }
}
