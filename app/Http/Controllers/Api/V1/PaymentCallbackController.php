<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CommissionCollections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase B10: where a payment gateway confirms a payment. No sign-in: the gateway's signature is the authentication. */
class PaymentCallbackController extends Controller
{
    public function handle(Request $request, CommissionCollections $collections, string $gateway): JsonResponse
    {
        $result = $collections->callback($gateway, (string) $request->getContent(), $request->header('X-Signature'));

        return response()->json(['data' => ['received' => true, 'status' => $result['status']]]);
    }
}
