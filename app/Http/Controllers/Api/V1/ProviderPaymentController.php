<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CommissionCollections;
use App\Services\ProviderFinanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase B10: a provider business pays WorkMate commission. Thin: the rules are in CommissionCollections and CommissionSettlements. */
class ProviderPaymentController extends Controller
{
    /** @var CommissionCollections */
    private $collections;

    /** @var ProviderFinanceAccess */
    private $access;

    public function __construct(CommissionCollections $collections, ProviderFinanceAccess $access)
    {
        $this->collections = $collections;
        $this->access = $access;
    }

    /** What the Pay Commission screen shows: what is owed, what is on its way, the ways to pay */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->collections->overview($user, $this->access->requireProviderId($user))]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'max:16'], 'amount' => ['required'], 'phone' => ['nullable', 'string', 'max:24'], 'client_token' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json(['data' => $this->collections->initiate($request->user(), $data)], 201);
    }

    public function attempt(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->collections->show($request->user(), $id)]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->collections->cancel($request->user(), $id)]);
    }

    public function receipt(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->collections->receipt($request->user(), $id)]);
    }

    public function receiptPdf(Request $request, int $id)
    {
        return $this->collections->receiptPdf($request->user(), $id);
    }

    /** Development and tests only (the route does not exist in production): the phone's approval or refusal */
    public function simulate(Request $request, int $id, string $outcome): JsonResponse
    {
        return response()->json(['data' => $this->collections->simulate($request->user(), $id, $outcome)]);
    }
}
