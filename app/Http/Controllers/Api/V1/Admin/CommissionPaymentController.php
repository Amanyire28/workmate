<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CommissionPaymentDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B10: Commission Payments, the administrator's workspace for manual proofs and gateway payments. Reading needs settlements.view;
 * deciding on a manual payment uses the existing settlements verify and reject endpoints (settlements.verify); the export needs
 * finance.reports.view. Nothing here changes a balance.
 */
class CommissionPaymentController extends Controller
{
    /** @var CommissionPaymentDirectory */
    private $directory;

    public function __construct(CommissionPaymentDirectory $directory)
    {
        $this->directory = $directory;
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'in:'.implode(',', CommissionPaymentDirectory::STATUSES)], 'method' => ['nullable', 'string', 'max:16'],
            'source' => ['nullable', 'in:manual,gateway'], 'provider' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'sort' => ['nullable', 'in:'.implode(',', CommissionPaymentDirectory::SORTS)],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->directory->summary()]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']])['page'] ?? 1;

        return response()->json(['data' => $this->directory->directory($this->filters($request), (int) $page)]);
    }

    public function show(string $kind, int $id): JsonResponse
    {
        abort_unless(in_array($kind, ['report', 'attempt'], true), 404);

        return response()->json(['data' => $this->directory->detail($kind, $id)]);
    }

    public function export(Request $request)
    {
        return $this->directory->csv($this->filters($request));
    }
}
