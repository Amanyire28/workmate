<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CommissionAssessments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase B6: a provider business's own commission obligations (owner or manager). `data` is null while the obligation system is off. */
class CommissionObligationController extends Controller
{
    public function mine(Request $request, CommissionAssessments $assessments): JsonResponse
    {
        return response()->json(['data' => $assessments->forProvider($request->user())]);
    }
}
