<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Category;
use App\Models\CommissionShadowSnapshot;
use App\Models\Profession;
use App\Models\ProviderProfile;
use App\Services\CommissionPolicies;
use App\Services\CommissionShadow;
use App\Services\Pricing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The shadow commission estimates, read-only, for administrators with commission.view (Phase B4). Every figure is an
 * ESTIMATE ("Estimated — Not Collected"): nothing here is revenue, an invoice or money owed, and nothing is shown to
 * providers or customers. No contact details of customers or providers are returned.
 */
class CommissionShadowController extends Controller
{
    /** @var CommissionShadow */
    private $shadow;

    public function __construct(CommissionShadow $shadow)
    {
        $this->shadow = $shadow;
    }

    /** Totals, exclusions and the breakdowns for a period (default: the last 90 days) */
    public function summary(Request $request, Pricing $pricing, CommissionPolicies $policies): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'granularity' => ['nullable', 'in:day,month'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(89)->startOfDay();

        return response()->json(['data' => $this->shadow->summary($from, $to, $data['granularity'] ?? 'month') + [
            // How the switches stand: estimates are only worked out while the shadow switch is on
            'shadow_enabled' => $this->shadow->enabled(),
            'pricing_enabled' => $pricing->enabled(),
            'ceiling_bp' => $policies->ceiling(),
        ]]);
    }

    /** The individual estimates, newest first, 25 to a page */
    public function snapshots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:calculated,excluded'],
            'reason' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $per = 25;
        $query = DB::table('commission_shadow_snapshots as s')->where('s.current_slot', 1)
            ->leftJoin('provider_profiles as p', 'p.id', '=', 's.provider_profile_id')
            ->leftJoin('categories as c', 'c.id', '=', 's.category_id');
        if (! empty($data['status'])) {
            $query->where('s.status', $data['status']);
        }
        if (! empty($data['reason'])) {
            $query->where('s.exclusion_reason', $data['reason']);
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('s.id')->forPage($data['page'] ?? 1, $per)
            ->get(['s.id', 's.payment_report_id', 's.service_request_id', 's.revision_no', 's.status', 's.exclusion_reason', 's.mode', 's.amount_ugx', 's.rate_bp',
                's.commission_ugx', 's.provider_net_ugx', 's.tier', 's.policy_id', 's.policy_version', 's.policy_scope', 's.basis_at', 'p.display_name as provider', 'c.name as category']);

        return response()->json(['data' => [
            'label' => CommissionShadow::LABEL,
            'items' => $rows->map(function ($r) {
                return [
                    'id' => (int) $r->id, 'payment_report_id' => (int) $r->payment_report_id, 'request_id' => (int) $r->service_request_id, 'revision_no' => (int) $r->revision_no,
                    'status' => $r->status, 'exclusion_reason' => $r->exclusion_reason, 'mode' => $r->mode, 'provider' => $r->provider, 'category' => $r->category,
                    'amount_ugx' => (int) $r->amount_ugx, 'rate_bp' => $r->rate_bp === null ? null : (int) $r->rate_bp,
                    'percent' => $r->rate_bp === null ? null : CommissionPolicies::percent((int) $r->rate_bp),
                    'commission_ugx' => $r->commission_ugx === null ? null : (int) $r->commission_ugx,
                    'provider_remainder_ugx' => $r->provider_net_ugx === null ? null : (int) $r->provider_net_ugx,
                    'tier' => $r->tier, 'policy_id' => $r->policy_id === null ? null : (int) $r->policy_id, 'policy_version' => $r->policy_version === null ? null : (int) $r->policy_version,
                    'policy_scope' => $r->policy_scope, 'confirmed_at' => Carbon::parse($r->basis_at)->toIso8601String(),
                ];
            })->values(),
            'page' => (int) ($data['page'] ?? 1), 'per_page' => $per, 'total' => $total,
        ]]);
    }

    /**
     * "Which rate would apply?": picks the rule for a provider, category and (optionally) place, profession and moment, and
     * shows every tier looked at. Writes nothing.
     */
    public function resolve(Request $request, CommissionPolicies $policies): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'integer'],
            'category' => ['required', 'string', 'max:60'],
            'area' => ['nullable', 'string', 'max:60'],
            'profession' => ['nullable', 'string', 'max:60'],
            'at' => ['nullable', 'date'],
            'amount' => ['nullable', 'integer', 'min:100', 'max:100000000'],
        ]);
        $category = Category::where('slug', $data['category'])->first();
        $area = ! empty($data['area']) ? Area::where('slug', $data['area'])->first() : null;
        $profession = ! empty($data['profession']) ? Profession::where('slug', $data['profession'])->first() : null;
        $provider = ! empty($data['provider']) ? ProviderProfile::find($data['provider']) : null;
        $at = ! empty($data['at']) ? Carbon::parse($data['at'])->setTimezone(config('app.timezone')) : now();

        $resolution = $this->shadow->resolve([
            'provider_id' => $provider ? $provider->id : null,
            'profession_id' => $profession ? $profession->id : null, 'profession_skip' => $profession ? null : 'not_given',
            'category_id' => $category ? $category->id : null,
            'area_id' => $area ? $area->id : null, 'district_key' => $area ? $this->shadow->districtKey($area->district) : null,
        ], $at);

        $out = ['label' => CommissionShadow::LABEL, 'at' => $at->toIso8601String(), 'status' => $resolution['status'], 'trace' => $resolution['trace'], 'precedence' => \App\Models\CommissionShadowPolicy::PRECEDENCE];
        if ($resolution['status'] === 'resolved') {
            $policy = $resolution['policy'];
            $out += ['tier' => $resolution['tier'], 'policy' => $policies->present($policy)];
            if (! empty($data['amount'])) {
                $commission = CommissionShadow::commission((int) $data['amount'], (int) $policy->rate_bp);
                $out['example'] = ['amount_ugx' => (int) $data['amount'], 'commission_ugx' => $commission, 'provider_remainder_ugx' => (int) $data['amount'] - $commission];
            }
        } else {
            // No rule, or an ambiguous one: no rate is invented
            $out['reason'] = $resolution['reason'];
        }

        return response()->json(['data' => $out]);
    }

    public function options(CommissionPolicies $policies): JsonResponse
    {
        return response()->json(['data' => $policies->options()]);
    }
}
