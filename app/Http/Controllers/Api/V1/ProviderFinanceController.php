<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ProviderFinance;
use App\Services\ProviderFinanceAccess;
use App\Services\ProviderStatements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B9, My Finances: the signed-in person's own business's money, read only. Every action asks ProviderFinanceAccess first (the owner,
 * or a team member the owner gave access to), and then only ever reads that one business: an id that belongs to another business is
 * "not found", whatever filter or export it comes through. None of it depends on a financial feature switch.
 */
class ProviderFinanceController extends Controller
{
    /** @var ProviderFinanceAccess */
    private $access;

    /** @var ProviderFinance */
    private $finance;

    public function __construct(ProviderFinanceAccess $access, ProviderFinance $finance)
    {
        $this->access = $access;
        $this->finance = $finance;
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'period' => ['nullable', 'in:'.implode(',', ProviderFinance::PERIODS)], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'max:24'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
    }

    private function providerId(Request $request): int
    {
        return $this->access->requireProviderId($request->user());
    }

    /** Who can see this business's finances (the owner also sees the team and can change it) */
    public function access(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->access->describe($request->user())]);
    }

    public function setAccess(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1'], 'allowed' => ['required', 'boolean'], 'can_pay' => ['nullable', 'boolean']]);

        return response()->json(['data' => $this->access->setAccess($request->user(), (int) $data['user_id'], (bool) $data['allowed'], array_key_exists('can_pay', $data) && $data['can_pay'] !== null ? (bool) $data['can_pay'] : null)]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->finance->summary($this->providerId($request), $this->filters($request))]);
    }

    public function payments(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => $this->finance->payments($this->providerId($request), $f, (int) ($f['page'] ?? 1))]);
    }

    public function statement(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => $this->finance->statement($this->providerId($request), $f, (int) ($f['page'] ?? 1))]);
    }

    public function cycles(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => $this->finance->cycles($this->providerId($request), $f, (int) ($f['page'] ?? 1))]);
    }

    public function cycle(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->finance->cycle($this->providerId($request), $id)]);
    }

    public function commissionPayments(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => $this->finance->commissionPayments($this->providerId($request), (int) ($f['page'] ?? 1))]);
    }

    public function disputes(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => $this->finance->disputes($this->providerId($request), $f, (int) ($f['page'] ?? 1))]);
    }

    /** A statement as CSV or PDF: the same access, the same business, the same period rules as the screens */
    public function statementFile(Request $request, ProviderStatements $statements, string $kind, string $format)
    {
        abort_unless(in_array($kind, ProviderStatements::KINDS, true) && in_array($format, ProviderStatements::FORMATS, true), 404);

        return $statements->download($this->providerId($request), $kind, $format, $this->filters($request));
    }
}
