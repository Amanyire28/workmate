<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentDispute;
use App\Models\PaymentDisputeEvidence;
use App\Services\PaymentDisputes;
use App\Services\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B5: a payment dispute, for the two sides of it only (the customer, and the provider business's owner or manager).
 * Anyone else gets "not found". The service does the checking; this only reads and validates the request.
 */
class PaymentDisputeController extends Controller
{
    /** @var PaymentDisputes */
    private $disputes;

    public function __construct(PaymentDisputes $disputes)
    {
        $this->disputes = $disputes;
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->disputes->show($request->user(), $id)]);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.PaymentDisputes::MAX_MESSAGE],
            'client_token' => ['nullable', 'string', 'max:64'],
            'files' => ['nullable', 'array', 'max:'.PaymentDisputeEvidence::MAX_PER_MESSAGE],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:'.PaymentDisputeEvidence::MAX_KILOBYTES],
        ], [
            'files.*.mimes' => 'Attach photos (JPG or PNG) or PDF files only.',
            'files.*.max' => 'Each file can be up to 5 MB.',
            'files.*.file' => 'One of the files could not be uploaded. Try again.',
        ]);

        return response()->json(['data' => $this->disputes->post($request->user(), $id, $data['body'] ?? null, $request->file('files') ?: [], $data['client_token'] ?? null)], 201);
    }

    public function escalate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'in:'.implode(',', PaymentDispute::ESCALATION_REASONS)], 'note' => ['required', 'string', 'max:'.PaymentDisputes::MAX_NOTE]]);

        return response()->json(['data' => $this->disputes->escalate($request->user(), $id, $data['reason'], $data['note'])]);
    }

    public function propose(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['outcome' => ['required', 'string', 'in:'.implode(',', PaymentDispute::AGREEMENTS)]]);

        return response()->json(['data' => $this->disputes->propose($request->user(), $id, $data['outcome'])]);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['outcome' => ['required', 'string', 'in:'.implode(',', PaymentDispute::AGREEMENTS)]]);

        return response()->json(['data' => $this->disputes->accept($request->user(), $id, $data['outcome'])]);
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->disputes->decline($request->user(), $id)]);
    }

    /** An evidence file, for this case's two sides only */
    public function evidence(Request $request, PrivateFiles $files, int $id, string $uuid)
    {
        return $files->response($this->disputes->evidenceFor($request->user(), $id, $uuid));
    }
}
