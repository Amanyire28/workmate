<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentDispute;
use App\Services\PaymentDisputes;
use App\Services\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B5, administrators and payment disputes. Reading needs disputes.view, taking a case and asking questions needs
 * disputes.review, and deciding needs disputes.resolve (the routes say which). Customer and provider contact details are
 * not part of what is sent: names only.
 */
class PaymentDisputeController extends Controller
{
    /** @var PaymentDisputes */
    private $disputes;

    public function __construct(PaymentDisputes $disputes)
    {
        $this->disputes = $disputes;
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_merge(PaymentDispute::ACTIVE, [PaymentDispute::RESOLVED]))],
            'scope' => ['nullable', 'in:escalated,all'],
            'q' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json(['data' => $this->disputes->queue($data)]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->disputes->adminShow($id)]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->disputes->startReview($request->user(), $id)]);
    }

    public function requestInfo(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'in:customer,provider,both'],
            'message' => ['required', 'string', 'min:5', 'max:'.PaymentDisputes::MAX_MESSAGE],
            'client_token' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json(['data' => $this->disputes->requestInfo($request->user(), $id, $data['to'], $data['message'], $data['client_token'] ?? null)]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:'.implode(',', PaymentDispute::DECISIONS)],
            'reason' => ['required', 'string', 'min:10', 'max:'.PaymentDisputes::MAX_NOTE],
            'amount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ]);

        return response()->json(['data' => $this->disputes->decide($request->user(), $id, $data['outcome'], $data['reason'], $data['amount'] ?? null)]);
    }

    public function evidence(Request $request, PrivateFiles $files, int $id, string $uuid)
    {
        return $files->response($this->disputes->evidenceForAdmin($request->user(), $id, $uuid));
    }
}
