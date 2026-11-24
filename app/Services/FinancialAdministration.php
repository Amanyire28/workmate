<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\CommissionAdjustmentRequest;
use App\Models\CommissionException;
use App\Models\CommissionExceptionEvent;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPaymentReport;
use App\Models\CommissionSettlementCycle;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase B8: the finance team's tools over the B6 ledger and B7 settlements. It READS the ledger and records requests, decisions and
 * investigation notes. It never writes a ledger line, never edits a balance and never decides money by itself:
 *
 *  - OVERVIEW. Two clearly separate groups: what happened in the chosen PERIOD (assessed, reversed, collected, adjustments) and where
 *    things stand AS OF NOW (outstanding, overdue, awaiting verification, open exceptions). Collected means money WorkMate verified
 *    received (a pending or rejected report is never in it); a provider's own earnings are not in anything here. Whole UGX throughout.
 *  - ADJUSTMENTS. Two steps: a finance administrator PROPOSES (provider, what it is about, type, direction, amount, reason, references),
 *    a Super Administrator APPROVES or REJECTS with a reason, never the person who proposed it and never for their own business. The
 *    same request cannot be open twice, and a correction for an obligation that is already fully reversed is refused so nothing is
 *    corrected twice. APPROVING POSTS NOTHING: the adjustment types, signs and what may be corrected are not an approved policy yet, so
 *    the ledger step is not built and every request keeps posting_status "not_posted". The ledger CHECK is untouched.
 *  - EXCEPTIONS. An exception is taken by a reviewer, annotated (append-only history) and can be closed only by a Super Administrator with
 *    a documented "no financial change" resolution. Refunds, transfers, forfeiture and credit allocation do not exist; an exception that
 *    needs one stays open and says which business decision is pending. An exception with a request in progress cannot be closed.
 *  - AUDIT. Everything above is written to the audit log with actor, reason and the provider concerned.
 */
class FinancialAdministration
{
    public const MIN_AMOUNT = 1;
    public const MAX_AMOUNT = 100000000;

    /** @var AuditLogger */
    private $audit;

    /** @var CommissionSettlements */
    private $settlements;

    public function __construct(AuditLogger $audit, CommissionSettlements $settlements)
    {
        $this->audit = $audit;
        $this->settlements = $settlements;
    }

    /* ---------------- Overview ---------------- */

    /** The reporting period: explicit, inclusive, at most a year (so nothing is added up over an unclear stretch of time) */
    public function period(array $filters): array
    {
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        if ($from->gt($to)) {
            throw new BusinessRuleException('period_invalid', 'The start of the period must be before its end.', 422);
        }
        if ($from->diffInDays($to) > 366) {
            throw new BusinessRuleException('period_too_long', 'Choose a period of up to one year.', 422);
        }

        return [$from, $to];
    }

    public function periodLabel(Carbon $from, Carbon $to): string
    {
        return $from->toDateString().' to '.$to->toDateString();
    }

    public function overview(array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $provider = ! empty($filters['provider']) ? (int) $filters['provider'] : null;
        $ledger = function () use ($provider) {
            return CommissionLedgerEntry::query()->when($provider, function ($q) use ($provider) {
                $q->where('provider_profile_id', $provider);
            });
        };
        $payments = function () use ($provider) {
            return CommissionPaymentReport::query()->when($provider, function ($q) use ($provider) {
                $q->where('provider_profile_id', $provider);
            });
        };

        $assessed = (int) $ledger()->where('entry_type', CommissionLedgerEntry::ASSESSMENT)->whereBetween('created_at', [$from, $to])->sum('amount_ugx');
        $reversed = -(int) $ledger()->where('entry_type', CommissionLedgerEntry::REVERSAL)->whereBetween('created_at', [$from, $to])->sum('amount_ugx');
        $verified = $payments()->where('status', CommissionPaymentReport::VERIFIED)->whereBetween('decided_at', [$from, $to]);
        $approved = CommissionAdjustmentRequest::query()->where('status', CommissionAdjustmentRequest::APPROVED)->whereBetween('decided_at', [$from, $to])
            ->when($provider, function ($q) use ($provider) {
                $q->where('provider_profile_id', $provider);
            });

        // Where things stand now, per provider (a provider who has paid too much does not reduce what another owes)
        $balances = $ledger()->select('provider_profile_id', DB::raw('sum(amount_ugx) as balance'))->groupBy('provider_profile_id')->pluck('balance', 'provider_profile_id');
        $owed = 0;
        $credit = 0;
        foreach ($balances as $b) {
            $b = (int) $b;
            $owed += max($b, 0);
            $credit += max(-$b, 0);
        }
        // Over EVERY cycle, by the database (never a sample)
        $totals = $this->settlements->statusTotals($provider ? ['provider' => $provider] : []);
        $overdueUgx = $totals['overdue']['outstanding_ugx'] ?? 0;
        $overdueCycles = $totals['overdue']['count'] ?? 0;
        $pending = $payments()->where('status', CommissionPaymentReport::PENDING);
        $exceptions = CommissionException::query()->whereIn('status', ['open', 'under_review'])->when($provider, function ($q) use ($provider) {
            $q->where('provider_profile_id', $provider);
        });

        return [
            'period' => [
                'from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $this->periodLabel($from, $to),
                'assessed_ugx' => $assessed,
                'reversed_ugx' => $reversed,
                // Posted adjustments would be added here; none can exist yet (posting is not built), so this is always 0
                'posted_adjustments_ugx' => 0,
                'net_obligations_ugx' => $assessed - $reversed,
                // Money WorkMate verified it received, in the period it was verified; and what of it was applied to what was owed
                'collected_ugx' => (int) (clone $verified)->sum('verified_amount_ugx'),
                'applied_ugx' => (int) (clone $verified)->sum('credited_ugx'),
                'excess_ugx' => (int) (clone $verified)->sum('excess_ugx'),
                'collected_payments' => (clone $verified)->count(),
                'approved_adjustments' => ['count' => (clone $approved)->count(), 'increase_ugx' => (int) (clone $approved)->where('direction', 'increase')->sum('amount_ugx'),
                    'decrease_ugx' => (int) (clone $approved)->where('direction', 'decrease')->sum('amount_ugx'), 'posted' => false],
            ],
            'as_of' => [
                'at' => now()->toIso8601String(),
                'outstanding_ugx' => $owed,
                'provider_credit_ugx' => $credit,
                'overdue_ugx' => $overdueUgx, 'overdue_cycles' => $overdueCycles,
                'pending_verification' => ['count' => (clone $pending)->count(), 'amount_ugx' => (int) (clone $pending)->sum('amount_ugx')],
                'open_exceptions' => ['count' => (clone $exceptions)->count(), 'amount_ugx' => (int) (clone $exceptions)->sum('amount_ugx')],
                'pending_adjustments' => CommissionAdjustmentRequest::where('status', CommissionAdjustmentRequest::PENDING)->when($provider, function ($q) use ($provider) {
                    $q->where('provider_profile_id', $provider);
                })->count(),
            ],
            'notes' => [
                'Period figures count what was recorded in the period. "As of now" figures are today\'s position and do not depend on the period.',
                'Collected is money WorkMate verified received. Reports that are pending or rejected are never counted as collected.',
                'Net obligations is assessed less reversed. Approved adjustments are listed but not posted: posting is not enabled.',
            ],
        ];
    }

    /* ---------------- Adjustments ---------------- */

    /** Proposes an adjustment. Changes nothing about what anyone owes. */
    public function request(User $admin, array $data): CommissionAdjustmentRequest
    {
        $providerId = (int) ($data['provider_id'] ?? 0);
        $provider = ProviderProfile::withTrashed()->find($providerId);
        if (! $provider) {
            throw new BusinessRuleException('provider_invalid', 'Choose the provider this is about.', 422);
        }
        $this->guardOwnBusiness($admin, $providerId);
        $type = $data['type'] ?? null;
        $direction = $data['direction'] ?? null;
        if (! in_array($type, CommissionAdjustmentRequest::TYPES, true) || ! in_array($direction, CommissionAdjustmentRequest::DIRECTIONS, true)) {
            throw new BusinessRuleException('type_invalid', 'Choose what kind of adjustment this is and whether it increases or decreases what is owed.', 422);
        }
        $amount = $data['amount_ugx'] ?? null;
        if ($amount === null || is_bool($amount) || is_float($amount) || ! ctype_digit((string) $amount) || (int) $amount < self::MIN_AMOUNT || (int) $amount > self::MAX_AMOUNT) {
            throw new BusinessRuleException('amount_invalid', 'Enter the amount in whole UGX.', 422);
        }
        $amount = (int) $amount;
        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 20) {
            throw new BusinessRuleException('reason_required', 'Explain why (at least 20 characters). A reviewer will read it.', 422);
        }
        $refs = [];
        foreach (['assessment_id', 'commission_payment_id', 'cycle_id', 'exception_id', 'payment_report_id', 'dispute_id'] as $key) {
            $refs[$key] = ! empty($data[$key]) ? (int) $data[$key] : null;
        }
        if (! array_filter($refs)) {
            throw new BusinessRuleException('subject_required', 'Say what this is about: an obligation, a payment, a settlement, an exception or a dispute.', 422);
        }
        $this->guardReferences($providerId, $refs);
        $token = ! empty($data['client_token']) ? mb_substr((string) $data['client_token'], 0, 64) : null;

        return DB::transaction(function () use ($admin, $providerId, $type, $direction, $amount, $reason, $refs, $data, $token) {
            ProviderProfile::withTrashed()->whereKey($providerId)->lockForUpdate()->first();
            if ($token) {
                $same = CommissionAdjustmentRequest::where('requested_by_user_id', $admin->getKey())->where('client_token', $token)->first();
                if ($same) {
                    return $same;
                }
            }
            $this->guardNotAlreadyCorrected($direction, $refs);
            $key = substr(sha1(implode('|', [$providerId, $type, $direction, $amount, implode(',', $refs)])), 0, 40);
            $open = CommissionAdjustmentRequest::where('dedupe_key', $key)->first();
            if ($open) {
                throw new BusinessRuleException('duplicate_request', 'The same adjustment is already open (request #'.$open->id.').', 409);
            }

            $row = new CommissionAdjustmentRequest();
            $row->forceFill($refs + [
                'provider_profile_id' => $providerId, 'type' => $type, 'direction' => $direction, 'amount_ugx' => $amount,
                'reference' => ! empty($data['reference']) ? mb_substr(trim((string) $data['reference']), 0, 200) : null, 'reason' => $reason,
                'provider_reason' => ! empty($data['provider_reason']) ? mb_substr(trim((string) $data['provider_reason']), 0, 300) : null,
                'client_token' => $token, 'status' => CommissionAdjustmentRequest::PENDING, 'dedupe_key' => $key, 'requested_by_user_id' => $admin->getKey(),
                'requested_at' => now(), 'posting_status' => 'not_posted',
            ])->save();
            $this->audit->record('finance.adjustment_requested', $row, [], ['provider_profile_id' => $providerId, 'type' => $type, 'direction' => $direction, 'amount_ugx' => $amount] + $refs, $reason, $admin);

            return $row;
        }, 3);
    }

    /** Approves or rejects a request (Super Administrator). Once; never by its own proposer; never for the decider's own business. */
    public function decide(User $admin, int $id, string $decision, ?string $reason): CommissionAdjustmentRequest
    {
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 10) {
            throw new BusinessRuleException('reason_required', 'Write the reason for the decision (at least 10 characters).', 422);
        }
        $to = $decision === 'approve' ? CommissionAdjustmentRequest::APPROVED : CommissionAdjustmentRequest::REJECTED;

        return DB::transaction(function () use ($admin, $id, $to, $reason) {
            $probe = CommissionAdjustmentRequest::find($id);
            if (! $probe) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            if ((int) $probe->requested_by_user_id === (int) $admin->getKey()) {
                throw new BusinessRuleException('own_request', 'You can’t decide on an adjustment you proposed.', 403);
            }
            $this->guardOwnBusiness($admin, (int) $probe->provider_profile_id);
            $row = CommissionAdjustmentRequest::whereKey($id)->lockForUpdate()->first();

            if ($row->status !== CommissionAdjustmentRequest::PENDING) {
                if ($row->status === $to && (int) $row->decided_by_user_id === (int) $admin->getKey()) {
                    return $row;
                }
                throw new BusinessRuleException('already_decided', 'This request has already been decided.', 409);
            }
            $row->forceFill([
                'status' => $to, 'decided_by_user_id' => $admin->getKey(), 'decided_at' => now(), 'decision_reason' => mb_substr($reason, 0, 500),
                // A rejected request no longer holds its place: the same correction can be proposed again with better support
                'dedupe_key' => $to === CommissionAdjustmentRequest::REJECTED ? null : $row->dedupe_key,
            ])->save();
            $this->audit->record('finance.adjustment_'.($to === CommissionAdjustmentRequest::APPROVED ? 'approved' : 'rejected'), $row, ['status' => 'pending'], [
                'status' => $to, 'provider_profile_id' => (int) $row->provider_profile_id, 'amount_ugx' => (int) $row->amount_ugx, 'direction' => $row->direction, 'posting_status' => 'not_posted',
            ], $reason, $admin);

            return $row;
        }, 3);
    }

    public function adjustments(array $filters): array
    {
        $q = CommissionAdjustmentRequest::query();
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['provider'])) {
            $q->where('provider_profile_id', (int) $filters['provider']);
        }
        $page = max((int) ($filters['page'] ?? 1), 1);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page, 25)->get();

        return [
            'items' => $rows->map(function ($r) {
                return $this->presentAdjustment($r);
            })->values()->all(),
            'counts' => CommissionAdjustmentRequest::select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all(),
            'page' => $page, 'per_page' => 25, 'total' => $total,
            'posting' => ['enabled' => false, 'note' => 'Approving records the decision only. Posting an adjustment to the ledger is not enabled: the correction policy is not approved yet.'],
        ];
    }

    public function adjustment(int $id): ?array
    {
        $r = CommissionAdjustmentRequest::find($id);

        return $r ? $this->presentAdjustment($r, true) : null;
    }

    private function presentAdjustment(CommissionAdjustmentRequest $r, bool $full = false): array
    {
        $names = User::whereIn('id', array_filter([$r->requested_by_user_id, $r->decided_by_user_id]))->pluck('name', 'id');
        $provider = ProviderProfile::withTrashed()->find($r->provider_profile_id);

        return [
            'id' => (int) $r->id, 'status' => $r->status, 'type' => $r->type, 'direction' => $r->direction, 'amount_ugx' => (int) $r->amount_ugx,
            'provider_id' => (int) $r->provider_profile_id, 'provider' => $provider ? $provider->display_name : null,
            'assessment_id' => $r->assessment_id, 'commission_payment_id' => $r->commission_payment_id, 'cycle_id' => $r->cycle_id, 'exception_id' => $r->exception_id,
            'payment_report_id' => $r->payment_report_id, 'dispute_id' => $r->dispute_id, 'reference' => $r->reference,
            'reason' => $r->reason, 'provider_reason' => $r->provider_reason,
            'requested_by' => $names[$r->requested_by_user_id] ?? null, 'requested_at' => $r->requested_at->toIso8601String(),
            'decided_by' => $names[$r->decided_by_user_id] ?? null, 'decided_at' => optional($r->decided_at)->toIso8601String(), 'decision_reason' => $r->decision_reason,
            'posting_status' => $r->posting_status,
        ];
    }

    /** Everything it points at must belong to the provider it is about */
    private function guardReferences(int $providerId, array $refs): void
    {
        $check = function (string $table, $id, string $column = 'provider_profile_id') use ($providerId) {
            if ($id && ! DB::table($table)->where('id', $id)->where($column, $providerId)->exists()) {
                throw new BusinessRuleException('reference_invalid', 'One of the records you named does not belong to this provider.', 422);
            }
        };
        $check('commission_assessments', $refs['assessment_id']);
        $check('commission_payment_reports', $refs['commission_payment_id']);
        $check('commission_settlement_cycles', $refs['cycle_id']);
        $check('commission_exceptions', $refs['exception_id']);
        $check('job_payment_reports', $refs['payment_report_id']);
        $check('payment_disputes', $refs['dispute_id']);
    }

    /** A decrease for an obligation that is already fully reversed would correct it twice: refused */
    private function guardNotAlreadyCorrected(string $direction, array $refs): void
    {
        if ($direction !== 'decrease') {
            return;
        }
        $assessmentId = $refs['assessment_id'];
        if (! $assessmentId && $refs['payment_report_id']) {
            $assessmentId = DB::table('commission_assessments')->where('payment_report_id', $refs['payment_report_id'])->value('id');
        }
        if ($assessmentId && (int) CommissionLedgerEntry::where('assessment_id', $assessmentId)->sum('amount_ugx') <= 0) {
            throw new BusinessRuleException('already_reversed', 'That obligation has already been reversed in the ledger, so there is nothing left to decrease.', 409);
        }
    }

    private function guardOwnBusiness(User $admin, int $providerId): void
    {
        if (ProviderMember::where('provider_profile_id', $providerId)->where('user_id', $admin->getKey())->exists()) {
            throw new BusinessRuleException('own_business', 'You can’t act on the finances of your own business.', 403);
        }
    }

    /* ---------------- Exceptions ---------------- */

    public function exceptions(array $filters): array
    {
        $q = CommissionException::query();
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['kind'])) {
            $q->where('kind', $filters['kind']);
        }
        if (! empty($filters['provider'])) {
            $q->where('provider_profile_id', (int) $filters['provider']);
        }
        $page = max((int) ($filters['page'] ?? 1), 1);
        $total = (clone $q)->count();
        $rows = $q->orderByRaw("field(status, 'open', 'under_review', 'resolved')")->orderByDesc('id')->forPage($page, 25)->get();

        return [
            'items' => $rows->map(function ($e) {
                return $this->presentException($e);
            })->values()->all(),
            'counts' => CommissionException::select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all(),
            'page' => $page, 'per_page' => 25, 'total' => $total,
        ];
    }

    public function exception(int $id): ?array
    {
        $e = CommissionException::find($id);
        if (! $e) {
            return null;
        }
        $names = User::whereIn('id', CommissionExceptionEvent::where('exception_id', $id)->pluck('actor_id')->filter())->pluck('name', 'id');

        return $this->presentException($e) + [
            'events' => CommissionExceptionEvent::where('exception_id', $id)->orderBy('id')->get()->map(function ($v) use ($names) {
                return ['event' => $v->event, 'by' => $names[$v->actor_id] ?? 'WorkMate', 'note' => $v->note, 'reference' => $v->reference, 'at' => $v->created_at->toIso8601String()];
            })->all(),
            'payment' => $e->commission_payment_id ? optional(CommissionPaymentReport::find($e->commission_payment_id), function ($p) {
                return $this->settlements->presentPayment($p, true);
            }) : null,
            'cycle' => $e->cycle_id ? $this->settlements->adminCycle((int) $e->cycle_id) : null,
            'ledger' => CommissionLedgerEntry::where('provider_profile_id', $e->provider_profile_id)->where(function ($w) use ($e) {
                $w->where('commission_payment_id', $e->commission_payment_id ?: 0)->orWhere('cycle_id', $e->cycle_id ?: 0);
                if ($e->kind === CommissionException::EXCESS_CREDIT) {
                    $w->orWhere('entry_type', CommissionLedgerEntry::REVERSAL);
                }
            })->orderByDesc('id')->limit(30)->get()->map(function ($l) {
                return ['type' => $l->entry_type, 'amount_ugx' => (int) $l->amount_ugx, 'reason' => $l->reason, 'reference' => $l->reference, 'at' => $l->created_at->toIso8601String()];
            })->all(),
            'adjustments' => CommissionAdjustmentRequest::where('exception_id', $id)->orderByDesc('id')->get()->map(function ($a) {
                return $this->presentAdjustment($a);
            })->all(),
            // What would have to be decided by the business before this can be closed with a financial effect. None of it is built.
            'pending_decision' => $e->status === 'resolved' ? null : 'A refund, a transfer to another settlement, forfeiture or applying the credit are not approved policies. '
                .'Until one is, this stays open, or a Super Administrator can close it with a documented "no financial change" resolution.',
        ];
    }

    private function presentException(CommissionException $e): array
    {
        $provider = ProviderProfile::withTrashed()->find($e->provider_profile_id);
        $names = User::whereIn('id', array_filter([$e->assigned_to_user_id, $e->resolved_by_user_id]))->pluck('name', 'id');

        return [
            'id' => (int) $e->id, 'kind' => $e->kind, 'status' => $e->status, 'amount_ugx' => (int) $e->amount_ugx, 'detail' => $e->detail,
            'provider_id' => (int) $e->provider_profile_id, 'provider' => $provider ? $provider->display_name : null,
            'payment_id' => $e->commission_payment_id, 'cycle_id' => $e->cycle_id, 'opened_at' => $e->created_at->toIso8601String(),
            'assigned_to' => $names[$e->assigned_to_user_id] ?? null, 'resolution' => $e->resolution, 'resolved_by' => $names[$e->resolved_by_user_id] ?? null,
            'resolved_at' => optional($e->resolved_at)->toIso8601String(), 'resolution_note' => $e->resolution_note,
        ];
    }

    /** A reviewer takes an exception. The same reviewer again changes nothing; another reviewer cannot silently take it over. */
    public function take(User $admin, int $id): array
    {
        $this->inException($id, function (CommissionException $e) use ($admin) {
            if ($e->status === 'resolved') {
                throw new BusinessRuleException('already_resolved', 'This exception is already resolved.', 409);
            }
            if ($e->status === 'under_review') {
                if ((int) $e->assigned_to_user_id === (int) $admin->getKey()) {
                    return;
                }
                throw new BusinessRuleException('already_taken', 'Another reviewer has this exception.', 409);
            }
            $e->forceFill(['status' => 'under_review', 'assigned_to_user_id' => $admin->getKey()])->save();
            $this->event($e, 'taken', $admin, null, null);
            $this->audit->record('finance.exception_taken', $e, ['status' => 'open'], ['status' => 'under_review', 'provider_profile_id' => (int) $e->provider_profile_id], null, $admin);
        });

        return $this->exception($id);
    }

    /** An investigation note (append-only) with an optional pointer to a statement line or reference */
    public function note(User $admin, int $id, ?string $note, ?string $reference): array
    {
        $note = trim((string) $note);
        if (mb_strlen($note) < 5) {
            throw new BusinessRuleException('note_required', 'Write the note (at least 5 characters).', 422);
        }
        $this->inException($id, function (CommissionException $e) use ($admin, $note, $reference) {
            if ($e->status === 'resolved') {
                throw new BusinessRuleException('already_resolved', 'This exception is already resolved.', 409);
            }
            $this->event($e, 'noted', $admin, $note, $reference ? mb_substr(trim($reference), 0, 200) : null);
            $this->audit->record('finance.exception_noted', $e, [], ['provider_profile_id' => (int) $e->provider_profile_id], $note, $admin);
        });

        return $this->exception($id);
    }

    /**
     * Closes an exception WITHOUT any financial change (Super Administrator). The only resolution that exists. Refused while an adjustment
     * request about it is pending or approved: it would leave that request pointing at a closed exception.
     */
    public function resolve(User $admin, int $id, ?string $note, bool $acknowledged): array
    {
        $note = trim((string) $note);
        if (! $acknowledged) {
            throw new BusinessRuleException('acknowledgement_required', 'Confirm that closing this changes no balance and decides no refund, transfer or credit.', 422);
        }
        if (mb_strlen($note) < 20) {
            throw new BusinessRuleException('note_required', 'Document why no financial change is needed (at least 20 characters).', 422);
        }
        $this->inException($id, function (CommissionException $e) use ($admin, $note) {
            if ($e->status === 'resolved') {
                if ((int) $e->resolved_by_user_id === (int) $admin->getKey()) {
                    return;
                }
                throw new BusinessRuleException('already_resolved', 'This exception is already resolved.', 409);
            }
            if (CommissionAdjustmentRequest::where('exception_id', $e->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                throw new BusinessRuleException('adjustment_in_progress', 'An adjustment about this exception is pending or approved. Finish that first.', 409);
            }
            $from = $e->status;
            $e->forceFill([
                'status' => 'resolved', 'resolution' => 'no_financial_change', 'resolved_by_user_id' => $admin->getKey(), 'resolved_at' => now(), 'resolution_note' => mb_substr($note, 0, 500),
            ])->save();
            $this->event($e, 'resolved', $admin, $note, null);
            $this->audit->record('finance.exception_resolved', $e, ['status' => $from], [
                'status' => 'resolved', 'resolution' => 'no_financial_change', 'provider_profile_id' => (int) $e->provider_profile_id, 'amount_ugx' => (int) $e->amount_ugx,
            ], $note, $admin);
        });

        return $this->exception($id);
    }

    private function inException(int $id, callable $do): void
    {
        DB::transaction(function () use ($id, $do) {
            $e = CommissionException::whereKey($id)->lockForUpdate()->first();
            if (! $e) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            $do($e);
        }, 3);
    }

    private function event(CommissionException $e, string $event, User $actor, ?string $note, ?string $reference): void
    {
        $row = new CommissionExceptionEvent();
        $row->forceFill(['exception_id' => $e->id, 'event' => $event, 'actor_id' => $actor->getKey(), 'note' => $note ? mb_substr($note, 0, 2000) : null, 'reference' => $reference, 'created_at' => now()])->save();
    }

    /* ---------------- Audit trail ---------------- */

    /** The financial audit trail (read only): commission.* and finance.* entries, newest first */
    public function audit(array $filters): array
    {
        $q = AuditLog::query()->where(function ($w) {
            $w->where('action', 'like', 'commission.%')->orWhere('action', 'like', 'finance.%');
        });
        if (! empty($filters['action'])) {
            $q->where('action', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['action']).'%');
        }
        if (! empty($filters['provider'])) {
            $id = (int) $filters['provider'];
            $q->where(function ($w) use ($id) {
                $w->where('after->provider_profile_id', $id)->orWhere('before->provider_profile_id', $id);
            });
        }
        if (! empty($filters['from'])) {
            $q->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $q->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }
        $page = max((int) ($filters['page'] ?? 1), 1);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page, 25)->get();
        $names = User::whereIn('id', $rows->pluck('actor_id')->filter())->pluck('name', 'id');

        return [
            'items' => $rows->map(function ($a) use ($names) {
                return [
                    'id' => (int) $a->id, 'action' => $a->action, 'by' => $a->actor_id ? ($names[$a->actor_id] ?? 'Unknown') : 'System',
                    'subject' => $a->subject_type ? class_basename($a->subject_type).' #'.$a->subject_id : null, 'reason' => $a->reason,
                    'before' => $a->before, 'after' => $a->after, 'at' => $a->created_at->toIso8601String(),
                ];
            })->values()->all(),
            'page' => $page, 'per_page' => 25, 'total' => $total,
        ];
    }
}
