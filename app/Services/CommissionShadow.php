<?php

namespace App\Services;

use App\Models\Area;
use App\Models\CommissionShadowPolicy;
use App\Models\CommissionShadowSnapshot;
use App\Models\JobPaymentReport;
use App\Models\JobPriceAgreement;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase B4, SHADOW MODE. Works out what WorkMate COULD earn from payments the customer has confirmed, and keeps the
 * result. Every figure is an ESTIMATE ("Estimated, not collected"). Nothing here charges anyone, creates a debt, an
 * invoice, a payable balance or a deadline, restricts or notifies a provider, or is shown to a provider or customer;
 * and no setting turns an estimate into something owed.
 *
 * ELIGIBILITY. Only a payment report the customer explicitly CONFIRMED is considered (a waiting, disputed or withdrawn
 * report earns nothing, and neither a finished job nor an agreed price implies a payment). A confirmed payment is then
 * excluded, with the reason kept, when the data cannot establish it safely: a sample record, a request made before
 * bookings existed, a cancelled job, a missing reference, no rate rule in force, or a rule that is ambiguous.
 *
 * WHICH RULE. The rule in force at the moment the customer confirmed the payment. Most specific first: provider,
 * profession, category, area, district, global. A tier is skipped (never guessed) when the job lacks what it needs: the
 * profession tier only when the profession was derived safely (from the application the provider was hired on, from the
 * listed service, or as the provider's only fitting profession; never when several fit or none does). Two rules in one
 * tier is ambiguous, so none is chosen and no rate is invented.
 *
 * THE SUM. commission = amount x rate_bp / 10,000 rounded half up, in whole-number arithmetic only (no floating point),
 * for each payment on its own, so several part payments are each counted once and never double counted. The provider's
 * estimated remainder is amount - commission.
 *
 * HISTORY. One current row per confirmed payment (a unique slot in the database). A row is never edited; when a payment
 * was excluded because no rule applied and a rule covering it exists later, a new revision supersedes it and the old row
 * stays. Changing or ending a rule never rewrites a calculation made under it.
 */
class CommissionShadow
{
    public const ENGINE_VERSION = 1;
    public const ROUNDING = 'half_up_per_payment';
    public const LABEL = 'Estimated — Not Collected';

    /** Exclusions found by looking at the payment itself, not stored (nothing to calculate yet, or never will be) */
    public const NOT_CONFIRMED = ['pending_confirmation' => 'awaiting_confirmation', 'disputed' => 'disputed', 'cancelled' => 'withdrawn'];

    /** @var PlatformSettings */
    private $settings;

    /** @var AuditLogger */
    private $audit;

    public function __construct(PlatformSettings $settings, AuditLogger $audit)
    {
        $this->settings = $settings;
        $this->audit = $audit;
    }

    /** Whether estimates are worked out (off by default; independent of the Pricing switch) */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('finance.commission_shadow_enabled');
    }

    /**
     * The estimated commission of an amount at a rate in basis points (500 = 5%), in whole UGX: amount x rate / 10,000
     * rounded half up. Integer arithmetic only. For example UGX 100,000 at 500 gives UGX 5,000, and UGX 33,333 at 500
     * gives UGX 1,667 (1,666.65 rounds up).
     */
    public static function commission(int $amountUgx, int $rateBp): int
    {
        return intdiv($amountUgx * $rateBp + 5000, 10000);
    }

    /* ---------------- Choosing the rule ---------------- */

    /**
     * Which rule applies to a job at a moment. Returns ['status' => 'resolved', 'policy' => ..., 'tier' => ..., 'trace' => ...]
     * or ['status' => 'unresolved', 'reason' => 'no_policy' | 'ambiguous_policy', 'trace' => ...]. The trace lists every
     * tier looked at and why it did or did not apply.
     *
     * @param  array{provider_id: ?int, profession_id: ?int, profession_skip: ?string, category_id: ?int, area_id: ?int, district_key: ?string}  $context
     */
    public function resolve(array $context, Carbon $at): array
    {
        $trace = [];
        foreach (CommissionShadowPolicy::PRECEDENCE as $tier) {
            [$hash, $why] = $this->scope($tier, $context);
            if ($hash === null) {
                $trace[] = ['tier' => $tier, 'result' => 'skipped', 'why' => $why];
                continue;
            }
            $inForce = CommissionShadowPolicy::where('scope_hash', $hash)->get()->filter(function (CommissionShadowPolicy $p) use ($at) {
                return $p->inForceAt($at);
            })->values();
            if ($inForce->count() === 0) {
                $trace[] = ['tier' => $tier, 'result' => 'none', 'scope' => $hash];
                continue;
            }
            if ($inForce->count() > 1) {
                $trace[] = ['tier' => $tier, 'result' => 'ambiguous', 'scope' => $hash, 'policies' => $inForce->pluck('id')->all()];

                return ['status' => 'unresolved', 'reason' => 'ambiguous_policy', 'trace' => $trace];
            }
            $policy = $inForce->first();
            $trace[] = ['tier' => $tier, 'result' => 'matched', 'scope' => $hash, 'policy' => $policy->id, 'version' => $policy->version, 'rate_bp' => $policy->rate_bp];

            return ['status' => 'resolved', 'policy' => $policy, 'tier' => $tier, 'trace' => $trace];
        }

        return ['status' => 'unresolved', 'reason' => 'no_policy', 'trace' => $trace];
    }

    /** The scope a tier looks for on this job: [hash, null], or [null, why this tier can't be used] */
    private function scope(string $tier, array $c): array
    {
        switch ($tier) {
            case CommissionShadowPolicy::SCOPE_PROVIDER:
                return $c['provider_id'] ? ['provider:'.$c['provider_id'], null] : [null, 'no_provider'];
            case CommissionShadowPolicy::SCOPE_PROFESSION:
                return $c['profession_id'] ? ['profession:'.$c['profession_id'], null] : [null, $c['profession_skip'] ?: 'no_profession'];
            case CommissionShadowPolicy::SCOPE_CATEGORY:
                return $c['category_id'] ? ['category:'.$c['category_id'], null] : [null, 'no_category'];
            case CommissionShadowPolicy::SCOPE_AREA:
                return $c['area_id'] ? ['area:'.$c['area_id'], null] : [null, 'no_area'];
            case CommissionShadowPolicy::SCOPE_DISTRICT:
                return $c['district_key'] ? ['district:'.$c['district_key'], null] : [null, 'no_district'];
            default:
                return ['global', null];
        }
    }

    /* ---------------- Judging one payment ---------------- */

    /**
     * Whether a confirmed payment can be estimated, and if so what the estimate is. Never writes anything. Returns
     * ['status' => 'calculated' | 'excluded', 'reason' => ?, 'inputs' => ..., 'trace' => ..., and the figures when calculated].
     */
    public function evaluate(JobPaymentReport $report): array
    {
        $request = ServiceRequest::withTrashed()->find($report->service_request_id);
        $provider = ProviderProfile::withTrashed()->find($report->provider_profile_id);
        $base = ['inputs' => ['category_id' => null, 'profession_id' => null, 'profession_source' => null, 'area_id' => null, 'district_key' => null], 'trace' => []];

        if (! $request || ! $provider || ! $report->responded_at) {
            return $this->excluded('missing_reference', $base);
        }
        if ($request->is_sample || $provider->is_sample) {
            return $this->excluded('sample_record', $base);
        }
        if ($request->isLegacy()) {
            return $this->excluded('legacy_request', $base);
        }
        // Refunds are not tracked, so a cancelled job's payment is not treated as earned
        if ($request->status === ServiceRequest::STATUS_CANCELLED) {
            return $this->excluded('job_cancelled', $base);
        }

        $agreement = $report->agreement_id ? JobPriceAgreement::find($report->agreement_id) : null;
        $areaId = $request->area_id ?: ($agreement ? $agreement->area_id : null);
        $area = $areaId ? Area::find($areaId) : null;
        $profession = $this->profession($agreement);
        $inputs = [
            'category_id' => $request->category_id ?: ($agreement ? $agreement->category_id : null),
            'profession_id' => $profession['id'],
            'profession_source' => $agreement ? $agreement->profession_source : null,
            'area_id' => $area ? $area->id : null,
            'district_key' => $area ? $this->districtKey($area->district) : null,
        ];

        $at = $report->responded_at->copy();
        $resolution = $this->resolve([
            'provider_id' => $provider->id, 'profession_id' => $profession['id'], 'profession_skip' => $profession['skip'],
            'category_id' => $inputs['category_id'], 'area_id' => $inputs['area_id'], 'district_key' => $inputs['district_key'],
        ], $at);
        if ($resolution['status'] !== 'resolved') {
            return $this->excluded($resolution['reason'], ['inputs' => $inputs, 'trace' => $resolution['trace']]);
        }

        $policy = $resolution['policy'];
        $commission = self::commission((int) $report->amount_ugx, (int) $policy->rate_bp);

        return [
            'status' => CommissionShadowSnapshot::CALCULATED, 'reason' => null, 'inputs' => $inputs, 'trace' => $resolution['trace'],
            'policy' => $policy, 'tier' => $resolution['tier'], 'rate_bp' => (int) $policy->rate_bp,
            'commission' => $commission, 'net' => (int) $report->amount_ugx - $commission,
        ];
    }

    /** The profession to judge by, and why not when it can't safely be used */
    private function profession(?JobPriceAgreement $agreement): array
    {
        if (! $agreement) {
            return ['id' => null, 'skip' => 'no_agreement'];
        }
        if (! $agreement->profession_id || $agreement->profession_source === 'unresolved') {
            return ['id' => null, 'skip' => 'profession_unresolved'];
        }
        // Several professions fit and the main one was assumed: not safe to charge a profession rate on
        if ($agreement->profession_review_needed) {
            return ['id' => null, 'skip' => 'profession_needs_review'];
        }

        return ['id' => (int) $agreement->profession_id, 'skip' => null];
    }

    private function excluded(string $reason, array $base): array
    {
        return ['status' => CommissionShadowSnapshot::EXCLUDED, 'reason' => $reason, 'inputs' => $base['inputs'], 'trace' => $base['trace']];
    }

    public function districtKey(?string $district): ?string
    {
        $key = mb_strtolower(trim((string) $district));

        return $key === '' ? null : mb_substr($key, 0, 100);
    }

    /* ---------------- Keeping the result ---------------- */

    /**
     * Makes sure a confirmed payment has its estimate. Safe to run any number of times and from any number of processes:
     * the payment row is locked, one current row per payment is enforced by the database, and an existing calculation
     * is never touched. An earlier exclusion for a missing or ambiguous rule is superseded by a new revision when the
     * payment can now be calculated. Returns the current row, or null when the payment is not confirmed.
     */
    public function process(JobPaymentReport $report): ?CommissionShadowSnapshot
    {
        return DB::transaction(function () use ($report) {
            $result = $this->processInside($report);
            // Phase B6 (off by default): in the same transaction, with the request and payment still locked, the obligation follows the
            // estimate: recorded once when it qualifies, reversed once when the payment stopped counting
            app(CommissionAssessments::class)->sync($report->id);

            return $result;
        }, 3);
    }

    private function processInside(JobPaymentReport $report): ?CommissionShadowSnapshot
    {
        return DB::transaction(function () use ($report) {
            // The same order every other money action takes its locks in: the request first, then the payment. (Saving the
            // estimate also checks the request, so taking the payment first could make two actions wait for each other.)
            ServiceRequest::withTrashed()->whereKey($report->service_request_id)->lockForUpdate()->first();
            $locked = JobPaymentReport::whereKey($report->id)->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }

            $current = CommissionShadowSnapshot::where('payment_report_id', $locked->id)->where('current_slot', 1)->lockForUpdate()->first();
            if ($locked->status !== JobPaymentReport::CONFIRMED) {
                // Nothing to estimate. But an estimate already made must not keep standing if the payment stopped being confirmed
                return $current && $current->status === CommissionShadowSnapshot::CALCULATED
                    ? $this->withdraw($locked, $current, 'payment_no_longer_confirmed')
                    : null;
            }
            if ($current && $current->status === CommissionShadowSnapshot::CALCULATED) {
                // The estimate itself never changes (the rate judged is the one in force when the customer confirmed). It only
                // stops being counted if the job has since stopped being a real, live one
                $reason = $this->laterIneligibility($locked);

                return $reason ? $this->withdraw($locked, $current, $reason) : $current;
            }

            $evaluation = $this->evaluate($locked);
            // Still excluded for the same reason: nothing new to say
            if ($current && $evaluation['status'] === CommissionShadowSnapshot::EXCLUDED && $evaluation['reason'] === $current->exclusion_reason) {
                return $current;
            }

            // The earlier exclusion stays as it was: it is marked as no longer current, and the new row points back to it
            if ($current) {
                $current->forceFill(['current_slot' => null, 'superseded_at' => now()])->save();
            }

            return $this->store($locked, $evaluation, $current);
        }, 3);   // tried again (up to three times) if the database picks this one to give way in a lock clash
    }

    /** Why a payment that was estimated should no longer count (null: it still does). Only facts that can change afterwards */
    private function laterIneligibility(JobPaymentReport $report): ?string
    {
        $request = ServiceRequest::withTrashed()->find($report->service_request_id);
        if (! $request || $request->trashed()) {
            return 'missing_reference';
        }

        return $request->status === ServiceRequest::STATUS_CANCELLED ? 'job_cancelled' : null;
    }

    /** The old estimate is kept as it was (marked as no longer current) and a new row says it no longer counts, and why */
    private function withdraw(JobPaymentReport $report, CommissionShadowSnapshot $current, string $reason): CommissionShadowSnapshot
    {
        $current->forceFill(['current_slot' => null, 'superseded_at' => now()])->save();

        return $this->store($report, $this->excluded($reason, ['inputs' => [
            'category_id' => $current->category_id, 'profession_id' => $current->profession_id, 'profession_source' => $current->profession_source,
            'area_id' => $current->area_id, 'district_key' => $current->district_key,
        ], 'trace' => []]), $current);
    }

    /** Process, but never let a problem here affect the caller (the customer's confirmation must not depend on an estimate) */
    public function processQuietly(JobPaymentReport $report): ?CommissionShadowSnapshot
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            return $this->process($report);
        } catch (QueryException $e) {
            // Another process saved this payment's estimate first (the database allows one): that is the answer
            return CommissionShadowSnapshot::where('payment_report_id', $report->id)->where('current_slot', 1)->first();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function store(JobPaymentReport $report, array $e, ?CommissionShadowSnapshot $previous): CommissionShadowSnapshot
    {
        $policy = $e['policy'] ?? null;
        $row = new CommissionShadowSnapshot();
        $row->forceFill([
            'payment_report_id' => $report->id,
            'service_request_id' => $report->service_request_id,
            'provider_profile_id' => $report->provider_profile_id,
            'revision_no' => (int) CommissionShadowSnapshot::where('payment_report_id', $report->id)->max('revision_no') + 1,
            'status' => $e['status'],
            'exclusion_reason' => $e['reason'],
            'mode' => CommissionShadowSnapshot::MODE_SHADOW,
            'amount_ugx' => $report->amount_ugx,
            'policy_id' => $policy ? $policy->id : null,
            'policy_version' => $policy ? $policy->version : null,
            'policy_scope' => $policy ? $policy->scope_hash : null,
            'tier' => $e['tier'] ?? null,
            'rate_bp' => $e['rate_bp'] ?? null,
            'commission_ugx' => $e['commission'] ?? null,
            'provider_net_ugx' => $e['net'] ?? null,
            'rounding' => self::ROUNDING,
            'engine_version' => self::ENGINE_VERSION,
            'category_id' => $e['inputs']['category_id'],
            'profession_id' => $e['inputs']['profession_id'],
            'profession_source' => $e['inputs']['profession_source'],
            'area_id' => $e['inputs']['area_id'],
            'district_key' => $e['inputs']['district_key'],
            'basis_at' => $report->responded_at,
            'trace' => $e['trace'],
            'supersedes_id' => $previous ? $previous->id : null,
            'current_slot' => 1,
        ])->save();

        return $row;
    }

    /**
     * Catches up: estimates for confirmed payments that have none (confirmed before shadow mode was switched on, or
     * missed), and a fresh look at those excluded for a missing or ambiguous rule when a rule has been added or changed
     * since. Idempotent. Returns counts.
     *
     * @return array{created: int, superseded: int, unchanged: int}
     */
    public function processPending(int $limit = 500): array
    {
        $counts = ['created' => 0, 'superseded' => 0, 'unchanged' => 0];

        $fresh = DB::table('job_payment_reports as r')
            ->leftJoin('commission_shadow_snapshots as s', function ($join) {
                $join->on('s.payment_report_id', '=', 'r.id')->where('s.current_slot', '=', 1);
            })
            ->where('r.status', JobPaymentReport::CONFIRMED)->whereNull('s.id')->orderBy('r.id')->limit($limit)->pluck('r.id');

        // Estimates that no longer stand: the job was cancelled (or removed), or the payment stopped being confirmed
        $stale = DB::table('commission_shadow_snapshots as s')
            ->join('job_payment_reports as r', 'r.id', '=', 's.payment_report_id')
            ->leftJoin('service_requests as q', 'q.id', '=', 's.service_request_id')
            ->where('s.current_slot', 1)->where('s.status', CommissionShadowSnapshot::CALCULATED)
            ->where(function ($w) {
                $w->where('r.status', '!=', JobPaymentReport::CONFIRMED)->orWhereNull('q.id')->orWhereNotNull('q.deleted_at')
                    ->orWhere('q.status', ServiceRequest::STATUS_CANCELLED);
            })->orderBy('s.id')->limit($limit)->pluck('s.payment_report_id');

        $lastRuleChange = CommissionShadowPolicy::max('updated_at');
        $again = collect();
        if ($lastRuleChange) {
            $again = CommissionShadowSnapshot::where('current_slot', 1)->where('status', CommissionShadowSnapshot::EXCLUDED)
                ->whereIn('exclusion_reason', ['no_policy', 'ambiguous_policy'])->where('created_at', '<', $lastRuleChange)
                ->orderBy('id')->limit($limit)->pluck('payment_report_id');
        }

        foreach ($fresh->merge($again)->merge($stale)->unique() as $reportId) {
            $report = JobPaymentReport::find($reportId);
            if (! $report) {
                continue;
            }
            $before = CommissionShadowSnapshot::where('payment_report_id', $reportId)->where('current_slot', 1)->first();
            try {
                $after = $this->process($report);
            } catch (QueryException $e) {
                $after = $before;
            }
            if (! $after || ($before && $before->id === $after->id)) {
                $counts['unchanged']++;
            } elseif ($before) {
                $counts['superseded']++;
            } else {
                $counts['created']++;
            }
        }

        return $counts;
    }

    /* ---------------- For the administrators' screens ---------------- */

    /**
     * Estimated commission over a period, from the current estimate of each confirmed payment. Only calculated rows count
     * as eligible volume; excluded ones are counted with their reason, together with payments that earn nothing because
     * they are not confirmed (waiting, disputed, withdrawn). Sample records are never in the figures.
     */
    public function summary(?Carbon $from, ?Carbon $to, string $granularity = 'month'): array
    {
        $current = function () use ($from, $to) {
            $q = DB::table('commission_shadow_snapshots as s')->where('s.current_slot', 1);
            if ($from) {
                $q->where('s.basis_at', '>=', $from);
            }
            if ($to) {
                $q->where('s.basis_at', '<=', $to);
            }

            return $q;
        };
        $calculated = function () use ($current) {
            return $current()->where('s.status', CommissionShadowSnapshot::CALCULATED);
        };

        $totals = $calculated()->selectRaw('count(*) c, coalesce(sum(s.amount_ugx),0) v, coalesce(sum(s.commission_ugx),0) k, coalesce(sum(s.provider_net_ugx),0) n')->first();
        $excluded = $current()->where('s.status', CommissionShadowSnapshot::EXCLUDED)->selectRaw('s.exclusion_reason r, count(*) c, coalesce(sum(s.amount_ugx),0) v')->groupBy('s.exclusion_reason')->get();

        // Payments that can't be estimated yet because they are not confirmed, judged from the payments themselves
        $live = DB::table('job_payment_reports as r')->join('service_requests as q', 'q.id', '=', 'r.service_request_id')
            ->where('q.is_sample', 0)->whereIn('r.status', array_keys(self::NOT_CONFIRMED));
        if ($from) {
            $live->where('r.created_at', '>=', $from);
        }
        if ($to) {
            $live->where('r.created_at', '<=', $to);
        }
        $liveRows = $live->selectRaw('r.status s, count(*) c, coalesce(sum(r.amount_ugx),0) v')->groupBy('r.status')->get();

        $byReason = [];
        foreach ($excluded as $row) {
            $byReason[$row->r] = ['payments' => (int) $row->c, 'amount_ugx' => (int) $row->v];
        }
        foreach ($liveRows as $row) {
            $byReason[self::NOT_CONFIRMED[$row->s]] = ['payments' => (int) $row->c, 'amount_ugx' => (int) $row->v];
        }
        ksort($byReason);

        $format = $granularity === 'day' ? '%Y-%m-%d' : '%Y-%m';
        $group = function ($rows, $keys = []) {
            return $rows->map(function ($r) use ($keys) {
                $out = ['payments' => (int) $r->c, 'amount_ugx' => (int) $r->v, 'commission_ugx' => (int) $r->k];
                foreach ($keys as $k) {
                    $out[$k] = $r->{$k};
                }

                return $out;
            })->values()->all();
        };

        $byPeriod = $calculated()->selectRaw("date_format(s.basis_at, '{$format}') period, count(*) c, sum(s.amount_ugx) v, sum(s.commission_ugx) k")->groupBy('period')->orderBy('period')->get();
        $byCategory = $calculated()->leftJoin('categories as c', 'c.id', '=', 's.category_id')->selectRaw('s.category_id id, coalesce(c.name, \'Not recorded\') name, count(*) c, sum(s.amount_ugx) v, sum(s.commission_ugx) k')->groupBy('s.category_id', 'c.name')->orderByDesc('k')->get();
        $byProfession = $calculated()->leftJoin('professions as p', 'p.id', '=', 's.profession_id')->selectRaw('s.profession_id id, coalesce(p.name, \'Not determined\') name, count(*) c, sum(s.amount_ugx) v, sum(s.commission_ugx) k')->groupBy('s.profession_id', 'p.name')->orderByDesc('k')->get();
        $byPolicy = $calculated()
            ->selectRaw('s.policy_id id, s.policy_version version, s.policy_scope scope, s.tier tier, s.rate_bp rate_bp, count(*) c, sum(s.amount_ugx) v, sum(s.commission_ugx) k')
            ->groupBy('s.policy_id', 's.policy_version', 's.policy_scope', 's.tier', 's.rate_bp')->orderByDesc('k')->get();

        return [
            'mode' => CommissionShadowSnapshot::MODE_SHADOW,
            'label' => self::LABEL,
            'currency' => 'UGX',
            'period' => ['from' => $from ? $from->toIso8601String() : null, 'to' => $to ? $to->toIso8601String() : null, 'granularity' => $granularity],
            'totals' => [
                'eligible_payments' => (int) $totals->c,
                'eligible_volume_ugx' => (int) $totals->v,
                'estimated_commission_ugx' => (int) $totals->k,
                'estimated_provider_remainder_ugx' => (int) $totals->n,
            ],
            'excluded' => ['payments' => (int) array_sum(array_column($byReason, 'payments')), 'by_reason' => $byReason],
            'by_period' => $group($byPeriod, ['period']),
            'by_category' => $group($byCategory, ['id', 'name']),
            'by_profession' => $group($byProfession, ['id', 'name']),
            'by_policy' => $group($byPolicy, ['id', 'version', 'scope', 'tier', 'rate_bp']),
        ];
    }
}
