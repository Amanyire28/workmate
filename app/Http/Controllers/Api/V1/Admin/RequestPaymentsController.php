<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Services\PaymentReports;
use Illuminate\Http\JsonResponse;

/**
 * The final price and the payments reported for one request, read-only, for administrators who can work with money
 * (finance.view). Administrators cannot report, confirm, dispute, withdraw or resolve anything here: no authority to
 * resolve a payment dispute has been approved yet.
 */
class RequestPaymentsController extends Controller
{
    public function show(PaymentReports $payments, int $id): JsonResponse
    {
        $request = ServiceRequest::withTrashed()->findOrFail($id);

        return response()->json(['data' => $payments->history($request)]);
    }
}
