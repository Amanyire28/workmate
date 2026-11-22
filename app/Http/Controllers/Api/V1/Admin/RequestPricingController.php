<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Services\PriceAgreements;
use Illuminate\Http\JsonResponse;

/**
 * The price negotiation of one request, read-only, for administrators who can see the marketplace
 * (marketplace.view). Administrators cannot propose, accept, change or remove anything here.
 */
class RequestPricingController extends Controller
{
    public function show(PriceAgreements $agreements, int $id): JsonResponse
    {
        $request = ServiceRequest::withTrashed()->findOrFail($id);

        return response()->json(['data' => $agreements->history($request)]);
    }
}
