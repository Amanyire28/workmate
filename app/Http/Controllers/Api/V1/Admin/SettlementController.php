<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionPaymentReport;
use App\Services\AuditLogger;
use App\Services\CommissionSettlements;
use App\Services\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B7, finance administration of commission settlement. Reading needs settlements.view; verifying or rejecting a reported payment needs
 * settlements.verify (the routes say which). The service refuses a decision on a payment from the administrator's own business.
 */
class SettlementController extends Controller
{
    /** @var CommissionSettlements */
    private $settlements;

    public function __construct(CommissionSettlements $settlements)
    {
        $this->settlements = $settlements;
    }

    public function cycles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:open,due,partially_paid,settled,overdue,void'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json(['data' => ['enabled' => $this->settlements->enabled()] + $this->settlements->adminCycles($data)]);
    }

    public function cycle(int $id): JsonResponse
    {
        $one = $this->settlements->adminCycle($id);
        abort_unless($one, 404);

        return response()->json(['data' => $one]);
    }

    public function payments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,verified,rejected,all'], 'provider' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json(['data' => $this->settlements->adminPayments($data)]);
    }

    public function exceptions(): JsonResponse
    {
        return response()->json(['data' => $this->settlements->adminExceptions()]);
    }

    public function verify(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount_ugx' => ['required', 'integer', 'min:1', 'max:100000000'],
            'receipt_confirmed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:' . CommissionSettlements::MAX_NOTE],
        ]);
        $report = $this->settlements->verify($request->user(), $id, $data['amount_ugx'], $data['note'] ?? null, (bool) $data['receipt_confirmed']);

        return response()->json(['data' => $this->settlements->presentPayment($report, true)]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:' . CommissionSettlements::MAX_NOTE]]);
        $report = $this->settlements->reject($request->user(), $id, $data['reason']);

        return response()->json(['data' => $this->settlements->presentPayment($report, true)]);
    }

    /** The proof a provider attached. Every opening is written to the audit log. */
    public function evidence(Request $request, PrivateFiles $files, AuditLogger $audit, int $id)
    {
        $report = CommissionPaymentReport::find($id);
        abort_unless($report, 404);
        $file = $this->settlements->evidence($report);
        $audit->record('commission.payment_evidence_viewed', $report, [], [], null, $request->user());

        return $files->response($file);
    }
}
