<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionAdjustmentRequest;
use App\Services\FinancialAdministration;
use App\Services\FinancialReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B8, financial administration. The routes carry the permission for each action (overview, reports and audit; proposing an adjustment;
 * approving one; reviewing and resolving exceptions). Whatever the screen hides, the server enforces: this class only validates the request.
 */
class FinancialAdministrationController extends Controller
{
    /** @var FinancialAdministration */
    private $admin;

    /** @var FinancialReports */
    private $reports;

    public function __construct(FinancialAdministration $admin, FinancialReports $reports)
    {
        $this->admin = $admin;
        $this->reports = $reports;
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'provider' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'integer', 'min:1'], 'profession' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->admin->overview($this->filters($request))]);
    }

    public function report(Request $request, string $key): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json(['data' => $this->reports->page($key, $filters, (int) ($filters['page'] ?? 1))]);
    }

    public function csv(Request $request, string $key)
    {
        return $this->reports->csv($key, $this->filters($request));
    }

    public function audit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:60'], 'provider' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json(['data' => $this->admin->audit($data)]);
    }

    /* ---------------- Adjustments ---------------- */

    public function adjustments(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'provider' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return response()->json(['data' => $this->admin->adjustments($data)]);
    }

    public function adjustment(int $id): JsonResponse
    {
        $one = $this->admin->adjustment($id);
        abort_unless($one, 404);

        return response()->json(['data' => $one]);
    }

    public function requestAdjustment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'in:'.implode(',', CommissionAdjustmentRequest::TYPES)],
            'direction' => ['required', 'in:'.implode(',', CommissionAdjustmentRequest::DIRECTIONS)],
            'amount_ugx' => ['required', 'integer', 'min:1', 'max:'.FinancialAdministration::MAX_AMOUNT],
            'assessment_id' => ['nullable', 'integer', 'min:1'], 'commission_payment_id' => ['nullable', 'integer', 'min:1'], 'cycle_id' => ['nullable', 'integer', 'min:1'],
            'exception_id' => ['nullable', 'integer', 'min:1'], 'payment_report_id' => ['nullable', 'integer', 'min:1'], 'dispute_id' => ['nullable', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:200'], 'reason' => ['required', 'string', 'min:20', 'max:2000'], 'provider_reason' => ['nullable', 'string', 'max:300'],
            'client_token' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json(['data' => $this->admin->adjustment($this->admin->request($request->user(), $data)->id)], 201);
    }

    public function approveAdjustment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        return response()->json(['data' => $this->admin->adjustment($this->admin->decide($request->user(), $id, 'approve', $data['reason'])->id)]);
    }

    public function rejectAdjustment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        return response()->json(['data' => $this->admin->adjustment($this->admin->decide($request->user(), $id, 'reject', $data['reason'])->id)]);
    }

    /* ---------------- Exceptions ---------------- */

    public function exceptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,under_review,resolved'], 'kind' => ['nullable', 'in:overpayment,excess_credit'], 'provider' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json(['data' => $this->admin->exceptions($data)]);
    }

    public function exception(int $id): JsonResponse
    {
        $one = $this->admin->exception($id);
        abort_unless($one, 404);

        return response()->json(['data' => $one]);
    }

    public function takeException(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->take($request->user(), $id)]);
    }

    public function noteException(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:2000'], 'reference' => ['nullable', 'string', 'max:200']]);

        return response()->json(['data' => $this->admin->note($request->user(), $id, $data['note'], $data['reference'] ?? null)]);
    }

    public function resolveException(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:20', 'max:500'], 'acknowledged' => ['required', 'boolean']]);

        return response()->json(['data' => $this->admin->resolve($request->user(), $id, $data['note'], (bool) $data['acknowledged'])]);
    }
}
