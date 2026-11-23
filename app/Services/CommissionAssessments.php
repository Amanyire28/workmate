<?php

namespace App\Services;

use App\Models\CommissionAssessment;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionShadowSnapshot;
use App\Models\JobPaymentReport;
use App\Models\PaymentDispute;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase B6: turns a customer-confirmed payment's commission estimate into a recorded OBLIGATION, in the append-only ledger.
 *
 *   confirmed payment ─► shadow snapshot (B4: policy, version, rate, whole-UGX commission) ─► assessment ─► ledger entry (+amount)
 *   the payment stops counting (job cancelled, no longer confirmed) ─► a reversal entry (-amount); the assessment stays as it was
 *
 * It reuses the B4 engine for everything about the RATE (policy precedence, effective dates, rounding): this class never works out a
 * rate or an amount itself, it only records what the snapshot says, once. Nothing is invented: no snapshot (no rule, no confirmed
 * payment, a sample record, a cancelled job) means no obligation.
 *
 * SAFE BY DEFAULT. Nothing is recorded unless ALL of these hold: finance.commission_assessment_enabled is on (off by default),
 * finance.commission_assessment_from names a moment (empty by default: so nothing earlier than an explicitly chosen start is ever
 * assessed, and old payments never become debt), pricing options and shadow estimates are on, and the customer confirmed the payment
 * at or after that moment. A payment still waiting for the customer, disputed or withdrawn is never assessed, and a payment whose
 * dispute is open is held. Run inside CommissionShadow::process, with the request and the payment already locked, so a payment is
 * assessed once even when several things happen to it at the same moment (the database also allows one assessment per payment and
 * one ledger entry per reference).
 *
 * Money is never moved here: no gateway, no collection, no settlement, no deadline. What a provider owes is the SUM of their
 * entries; settlement and reconciliation are a later phase. Providers see only their own; administrators only read.
 */
class CommissionAssessments
{
    /** @var PlatformSettings */
    private $settings;

    /** @var AuditLogger */
    private $audit;

    public function __construct(PlatformSettings $settings, AuditLogger $audit)
    {
        $this->settings = $settings;
        $this->audit = $audit;
    }

    /** The obligation system's own switch (off by default; independent of the other two, and it turns neither on) */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('finance.commission_assessment_enabled');
    }

    /** The first moment a customer's confirmation can create an obligation; null while none is chosen (then nothing is assessed) */
    public function startsAt(): ?Carbon
    {
        $value = $this->settings->get('finance.commission_assessment_from');
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Brings one payment's obligation in line with what is true now: records it once if it qualifies, reverses it once if it
     * stopped counting. Safe to call any number of times. Call inside the transaction that holds the request and payment locks.
     */
    public function sync(int $paymentReportId): ?CommissionAssessment
    {
        if (! $this->enabled()) {
            return null;
        }
        $report = JobPaymentReport::find($paymentReportId);
        if (! $report) {
            return null;
        }
        $snapshot = CommissionShadowSnapshot::where('payment_report_id', $report->id)->where('current_slot', 1)->first();
        $assessment = CommissionAssessment::where('payment_report_id', $report->id)->first();

        $stands = $report->status === JobPaymentReport::CONFIRMED && $snapshot && $snapshot->status === CommissionShadowSnapshot::CALCULATED;

        if ($assessment) {
            if (! $stands) {
                $this->reverse($assessment, $snapshot && $snapshot->exclusion_reason ? $snapshot->exclusion_reason : 'payment_not_confirmed');
            }

            return $assessment;
        }

        return $stands && $this->qualifies($report, $snapshot) ? $this->assess($report, $snapshot) : null;
    }

    /** Whether a confirmed, estimated payment may become an obligation now */
    private function qualifies(JobPaymentReport $report, CommissionShadowSnapshot $snapshot): bool
    {
        $from = $this->startsAt();
        if (! $from || ! $report->responded_at || $report->responded_at->lt($from)) {
            return false;   // earlier than the chosen start: never charged for the past
        }
        if (! app(Pricing::class)->enabled() || ! (bool) $this->settings->get('finance.commission_shadow_enabled')) {
            return false;
        }
        if ((int) $snapshot->commission_ugx <= 0 || ! $snapshot->policy_id) {
            return false;   // a zero commission is no obligation; and without a rule there is nothing to assess
        }
        // A case still open about this payment holds it: nothing is assessed while WorkMate and the two sides are still deciding
        return ! PaymentDispute::where('payment_report_id', $report->id)->whereIn('status', PaymentDispute::ACTIVE)->exists();
    }

    private function assess(JobPaymentReport $report, CommissionShadowSnapshot $s): CommissionAssessment
    {
        $assessment = new CommissionAssessment();
        $assessment->forceFill([
            'payment_report_id' => $report->id, 'snapshot_id' => $s->id, 'service_request_id' => $report->service_request_id,
            'provider_profile_id' => $report->provider_profile_id, 'amount_ugx' => $s->amount_ugx, 'policy_id' => $s->policy_id,
            'policy_version' => $s->policy_version, 'policy_scope' => $s->policy_scope, 'rate_bp' => $s->rate_bp,
            'commission_ugx' => $s->commission_ugx, 'assessed_at' => now(),
        ])->save();
        $this->entry($assessment, CommissionLedgerEntry::ASSESSMENT, (int) $s->commission_ugx, null, 'assess:'.$report->id);
        $this->audit->record('commission.obligation_assessed', $assessment, [], [
            'payment_report_id' => $report->id, 'provider_profile_id' => $report->provider_profile_id, 'amount_ugx' => (int) $s->amount_ugx,
            'rate_bp' => (int) $s->rate_bp, 'policy_id' => (int) $s->policy_id, 'policy_version' => (int) $s->policy_version, 'commission_ugx' => (int) $s->commission_ugx,
        ], null, null);

        return $assessment;
    }

    /** The payment stopped counting: a negative entry takes the rest of the obligation away, once. The original entries stay. */
    private function reverse(CommissionAssessment $assessment, string $reason): void
    {
        $open = (int) CommissionLedgerEntry::where('assessment_id', $assessment->id)->sum('amount_ugx');
        if ($open <= 0) {
            return;
        }
        $dispute = PaymentDispute::where('payment_report_id', $assessment->payment_report_id)->value('id');
        $this->entry($assessment, CommissionLedgerEntry::REVERSAL, -$open, mb_substr($reason, 0, 40), 'reverse:'.$assessment->id, $dispute ? (int) $dispute : null);
        $this->audit->record('commission.obligation_reversed', $assessment, ['outstanding_ugx' => $open], ['outstanding_ugx' => 0], $reason, null);
    }

    private function entry(CommissionAssessment $a, string $type, int $amount, ?string $reason, string $reference, ?int $dispute = null): void
    {
        $row = new CommissionLedgerEntry();
        $row->forceFill([
            'assessment_id' => $a->id, 'provider_profile_id' => $a->provider_profile_id, 'entry_type' => $type, 'amount_ugx' => $amount,
            'reason' => $reason, 'reference' => $reference, 'dispute_id' => $dispute, 'actor_id' => null, 'created_at' => now(),
        ])->save();
    }

    /**
     * The scheduled catch-up (bounded, idempotent): confirmed payments with a current estimate and no obligation yet, and obligations
     * whose payment has since stopped counting. Each is handled through CommissionShadow::process, in its usual transaction.
     *
     * @return array{checked: int}
     */
    public function catchUp(CommissionShadow $shadow, int $limit = 300): array
    {
        $checked = 0;
        if (! $this->enabled() || ! $this->startsAt()) {
            return ['checked' => 0];
        }
        $from = $this->startsAt();

        $missing = DB::table('commission_shadow_snapshots as s')
            ->join('job_payment_reports as r', 'r.id', '=', 's.payment_report_id')
            ->leftJoin('commission_assessments as a', 'a.payment_report_id', '=', 's.payment_report_id')
            ->where('s.current_slot', 1)->where('s.status', CommissionShadowSnapshot::CALCULATED)->whereNull('a.id')
            ->where('r.status', JobPaymentReport::CONFIRMED)->where('r.responded_at', '>=', $from)->where('s.commission_ugx', '>', 0)
            ->orderBy('s.id')->limit($limit)->pluck('s.payment_report_id');

        $stale = DB::table('commission_assessments as a')
            ->join('job_payment_reports as r', 'r.id', '=', 'a.payment_report_id')
            ->leftJoin('commission_shadow_snapshots as s', function ($join) {
                $join->on('s.payment_report_id', '=', 'a.payment_report_id')->where('s.current_slot', '=', 1);
            })
            ->leftJoin('service_requests as q', 'q.id', '=', 'a.service_request_id')
            ->whereRaw('(select coalesce(sum(amount_ugx), 0) from commission_ledger_entries e where e.assessment_id = a.id) > 0')
            ->where(function ($w) {
                // The payment is no longer confirmed, its estimate no longer stands, or the job was cancelled or removed
                $w->where('r.status', '!=', JobPaymentReport::CONFIRMED)->orWhereNull('s.id')->orWhere('s.status', '!=', CommissionShadowSnapshot::CALCULATED)
                    ->orWhereNull('q.id')->orWhereNotNull('q.deleted_at')->orWhere('q.status', ServiceRequest::STATUS_CANCELLED);
            })->orderBy('a.id')->limit($limit)->pluck('a.payment_report_id');

        foreach ($missing->merge($stale)->unique() as $id) {
            $report = JobPaymentReport::find($id);
            if (! $report) {
                continue;
            }
            try {
                $shadow->process($report);
                $checked++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['checked' => $checked];
    }

    /* ---------------- What is shown ---------------- */

    /** The provider ids a user may see commission for: the business they own or manage, and nobody else's */
    public function providerIdsFor(User $user): array
    {
        return ProviderMember::where('user_id', $user->getKey())->whereIn('role', [ProviderMember::ROLE_OWNER, ProviderMember::ROLE_MANAGER])->pluck('provider_profile_id')->all();
    }

    /** The provider's own view: totals and the obligations behind them (null when the obligation system is off or they have no business) */
    public function forProvider(User $user): ?array
    {
        $ids = $this->providerIdsFor($user);
        if (! $this->enabled() || ! $ids) {
            return null;
        }
        $assessments = CommissionAssessment::whereIn('provider_profile_id', $ids)->orderByDesc('id')->limit(100)->get();
        $outstanding = $this->outstanding($assessments->pluck('id')->all());

        $total = (int) CommissionLedgerEntry::whereIn('provider_profile_id', $ids)->sum('amount_ugx');
        $assessed = (int) CommissionLedgerEntry::whereIn('provider_profile_id', $ids)->where('entry_type', CommissionLedgerEntry::ASSESSMENT)->sum('amount_ugx');
        $reversed = -(int) CommissionLedgerEntry::whereIn('provider_profile_id', $ids)->where('entry_type', CommissionLedgerEntry::REVERSAL)->sum('amount_ugx');
        $requests = ServiceRequest::withTrashed()->with('category')->whereIn('id', $assessments->pluck('service_request_id'))->get()->keyBy('id');

        return [
            'confirmed_payments_ugx' => (int) CommissionAssessment::whereIn('provider_profile_id', $ids)->sum('amount_ugx'),
            'assessed_ugx' => $assessed,
            'reversed_ugx' => $reversed,
            'outstanding_ugx' => $total,
            // Not part of any obligation: they are not confirmed, so nothing is owed on them yet
            'not_counted' => [
                'waiting_for_customer' => JobPaymentReport::whereIn('provider_profile_id', $ids)->where('status', JobPaymentReport::PENDING)->count(),
                'questioned' => JobPaymentReport::whereIn('provider_profile_id', $ids)->where('status', JobPaymentReport::DISPUTED)->count(),
            ],
            'items' => $assessments->map(function (CommissionAssessment $a) use ($outstanding, $requests) {
                $request = $requests->get($a->service_request_id);
                $left = $outstanding[$a->id] ?? 0;

                return [
                    'id' => (int) $a->id, 'request_id' => (int) $a->service_request_id, 'service' => $request && $request->category ? $request->category->name : null,
                    'job' => $request ? $request->title : null, 'payment_ugx' => (int) $a->amount_ugx, 'rate' => CommissionPolicies::percent((int) $a->rate_bp),
                    'commission_ugx' => (int) $a->commission_ugx, 'outstanding_ugx' => $left, 'status' => $left > 0 ? 'outstanding' : 'reversed',
                    'assessed_at' => $a->assessed_at->toIso8601String(), 'policy_version' => (int) $a->policy_version,
                ];
            })->values()->all(),
        ];
    }

    /** Administrators' list (read only) with totals; `provider` narrows it to one business */
    public function adminList(array $filters): array
    {
        $query = CommissionAssessment::query();
        if (! empty($filters['provider'])) {
            $query->where('provider_profile_id', (int) $filters['provider']);
        }
        $total = (clone $query)->count();
        $per = 25;
        $rows = $query->orderByDesc('id')->forPage(max((int) ($filters['page'] ?? 1), 1), $per)->get();
        $outstanding = $this->outstanding($rows->pluck('id')->all());
        $providers = ProviderProfile::withTrashed()->whereIn('id', $rows->pluck('provider_profile_id'))->pluck('display_name', 'id');
        $disputes = PaymentDispute::whereIn('payment_report_id', $rows->pluck('payment_report_id'))->pluck('id', 'payment_report_id');

        return [
            'label' => 'Recorded obligation: not collected by any system yet',
            'enabled' => $this->enabled(),
            'starts_at' => optional($this->startsAt())->toIso8601String(),
            'totals' => [
                'assessments' => (clone $query)->count(),
                'assessed_ugx' => (int) CommissionLedgerEntry::where('entry_type', CommissionLedgerEntry::ASSESSMENT)->when(! empty($filters['provider']), function ($q) use ($filters) {
                    $q->where('provider_profile_id', (int) $filters['provider']);
                })->sum('amount_ugx'),
                'outstanding_ugx' => (int) CommissionLedgerEntry::when(! empty($filters['provider']), function ($q) use ($filters) {
                    $q->where('provider_profile_id', (int) $filters['provider']);
                })->sum('amount_ugx'),
            ],
            'items' => $rows->map(function (CommissionAssessment $a) use ($outstanding, $providers, $disputes) {
                $left = $outstanding[$a->id] ?? 0;

                return [
                    'id' => (int) $a->id, 'provider_id' => (int) $a->provider_profile_id, 'provider' => $providers[$a->provider_profile_id] ?? null,
                    'request_id' => (int) $a->service_request_id, 'payment_report_id' => (int) $a->payment_report_id, 'dispute_id' => $disputes[$a->payment_report_id] ?? null,
                    'payment_ugx' => (int) $a->amount_ugx, 'rate' => CommissionPolicies::percent((int) $a->rate_bp), 'policy_id' => (int) $a->policy_id,
                    'policy_version' => (int) $a->policy_version, 'policy_scope' => $a->policy_scope, 'commission_ugx' => (int) $a->commission_ugx,
                    'outstanding_ugx' => $left, 'status' => $left > 0 ? 'outstanding' : 'reversed', 'assessed_at' => $a->assessed_at->toIso8601String(),
                ];
            })->values()->all(),
            'page' => max((int) ($filters['page'] ?? 1), 1), 'per_page' => $per, 'total' => $total,
        ];
    }

    /** One obligation with every ledger line behind it */
    public function adminShow(int $id): ?array
    {
        $a = CommissionAssessment::with('entries')->find($id);
        if (! $a) {
            return null;
        }

        return [
            'id' => (int) $a->id, 'payment_report_id' => (int) $a->payment_report_id, 'snapshot_id' => (int) $a->snapshot_id, 'request_id' => (int) $a->service_request_id,
            'provider_id' => (int) $a->provider_profile_id, 'payment_ugx' => (int) $a->amount_ugx, 'rate' => CommissionPolicies::percent((int) $a->rate_bp),
            'policy_id' => (int) $a->policy_id, 'policy_version' => (int) $a->policy_version, 'commission_ugx' => (int) $a->commission_ugx,
            'entries' => $a->entries->map(function (CommissionLedgerEntry $e) {
                return ['type' => $e->entry_type, 'amount_ugx' => (int) $e->amount_ugx, 'reason' => $e->reason, 'reference' => $e->reference, 'dispute_id' => $e->dispute_id, 'at' => $e->created_at->toIso8601String()];
            })->values()->all(),
        ];
    }

    /** @return array<int, int> assessment id => what is still outstanding on it */
    private function outstanding(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return CommissionLedgerEntry::whereIn('assessment_id', $ids)->select('assessment_id', DB::raw('sum(amount_ugx) as n'))->groupBy('assessment_id')->pluck('n', 'assessment_id')
            ->map(function ($n) {
                return (int) $n;
            })->all();
    }
}
