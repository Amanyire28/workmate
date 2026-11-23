<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CommissionPolicies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shadow commission rate rules (Phase B4). Reading needs commission.view; previewing, creating, replacing and
 * retiring need commission.manage. A rule estimates what WorkMate could earn: it charges nobody and creates no debt.
 * Every change is audited with who, when, why and before/after.
 */
class CommissionPolicyController extends Controller
{
    /** @var CommissionPolicies */
    private $policies;

    public function __construct(CommissionPolicies $policies)
    {
        $this->policies = $policies;
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->policies->list()]);
    }

    /** Checks a proposed rule and shows what it would mean (no change is made); a valid one gets a short-lived token to save it */
    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + ['replaces' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->policies->simulate($request->user(), $data, $data['replaces'] ?? null)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + $this->confirmation());
        $policy = $this->policies->create($request->user(), $data, $data['token'] ?? null, $data['confirm_rate_bp'] ?? null, $data['reason'] ?? null);

        return response()->json(['data' => $this->policies->present($policy)], 201);
    }

    public function replace(Request $request, int $policy): JsonResponse
    {
        $data = $request->validate($this->rules() + $this->confirmation());
        $new = $this->policies->replace($request->user(), $policy, $data, $data['token'] ?? null, $data['confirm_rate_bp'] ?? null, $data['reason'] ?? null);

        return response()->json(['data' => $this->policies->present($new)], 201);
    }

    public function retire(Request $request, int $policy): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        return response()->json(['data' => $this->policies->present($this->policies->retire($request->user(), $policy, $data['reason']))]);
    }

    private function rules(): array
    {
        return [
            'scope_type' => ['nullable', 'string', 'max:12'],
            'scope_ref' => ['nullable', 'integer'],
            'scope_key' => ['nullable', 'string', 'max:100'],
            'rate_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'effective_from' => ['required', 'string', 'max:40'],
            'effective_to' => ['nullable', 'string', 'max:40'],
        ];
    }

    private function confirmation(): array
    {
        return [
            // The preview's token, the rate typed again, and why
            'token' => ['required', 'string', 'max:100'],
            'confirm_rate_bp' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:300'],
        ];
    }
}
