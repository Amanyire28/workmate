<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CommissionAssessment;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPaymentReport;
use App\Models\CommissionSettlementCycle;
use App\Models\JobFinalPrice;
use App\Models\JobPaymentReport;
use App\Models\PaymentDispute;
use App\Models\ProviderProfile;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase B9: a provider business's own money, in one place (My Finances). It is a READING layer over the records that already exist, with no
 * second source of truth and nothing stored here:
 *
 *   CUSTOMER PAYMENTS   what customers paid the provider directly, from the B3 payment reports: confirmed (the customer said yes), waiting
 *                       for the customer, questioned. Every confirmed payment counts, whether or not it ever produced a commission.
 *                       Nothing here is held by WorkMate. Unconfirmed money is never called earned.
 *   WORKMATE COMMISSION what the provider owes WorkMate, from the B6 ledger and B7 settlements: assessed, reversed, verified settlements,
 *                       outstanding, overdue and the current settlement. The rules are the existing ones (CommissionSettlements::state and
 *                       cycleSummary); nothing is recalculated for the screen.
 *
 * Period figures (what was recorded between two dates) are always kept apart from "as of now" figures (today's position). Dates are
 * Kampala dates: a week starts on Monday, a month on the 1st, all converted to the database's UTC clock.
 *
 * Reading history never depends on a financial feature switch. The switches only decide whether NEW financial activity is possible; with
 * everything off, a provider still reads what happened, and one who never had anything sees an honest empty account.
 */
class ProviderFinance
{
    public const PERIODS = ['this_week', 'this_month', 'last_month', 'all', 'custom'];
    public const PER_PAGE = 20;

    /** @var CommissionSettlements */
    private $settlements;

    /** @var PlatformSettings */
    private $settings;

    public function __construct(CommissionSettlements $settlements, PlatformSettings $settings)
    {
        $this->settlements = $settlements;
        $this->settings = $settings;
    }

    /* ---------------- The period ---------------- */

    /**
     * @param  array{period?: string, from?: string, to?: string}  $in
     * @return array{key: string, label: string, from: ?Carbon, to: ?Carbon, from_date: ?string, to_date: ?string}  from/to in UTC (null = no bound)
     */
    public function period(array $in): array
    {
        $key = $in['period'] ?? 'this_month';
        if (! in_array($key, self::PERIODS, true)) {
            throw new BusinessRuleException('period_invalid', 'Choose a period.', 422);
        }
        $tz = Scheduling::TIMEZONE;
        $now = Carbon::now($tz);
        switch ($key) {
            case 'this_week':
                $from = $now->copy()->startOfWeek();
                $to = $now->copy()->endOfWeek();
                break;
            case 'last_month':
                $from = $now->copy()->startOfMonth()->subMonthNoOverflow()->startOfMonth();
                $to = $from->copy()->endOfMonth();
                break;
            case 'all':
                return ['key' => 'all', 'label' => 'All time', 'from' => null, 'to' => null, 'from_date' => null, 'to_date' => null];
            case 'custom':
                if (empty($in['from']) || empty($in['to'])) {
                    throw new BusinessRuleException('period_invalid', 'Choose the first and the last day.', 422);
                }
                $from = Carbon::parse($in['from'], $tz)->startOfDay();
                $to = Carbon::parse($in['to'], $tz)->endOfDay();
                if ($from->gt($to)) {
                    throw new BusinessRuleException('period_invalid', 'The first day must be before the last day.', 422);
                }
                if ($from->diffInDays($to) > 1830) {
                    throw new BusinessRuleException('period_too_long', 'Choose a period of up to five years, or “All time”.', 422);
                }
                break;
            default:
                $from = $now->copy()->startOfMonth();
                $to = $now->copy()->endOfMonth();
        }
        $names = ['this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Chosen dates'];

        return [
            'key' => $key, 'label' => $names[$key].' ('.$from->format('j M').' to '.$to->format('j M Y').')',
            'from' => $from->copy()->utc(), 'to' => $to->copy()->utc(), 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
        ];
    }

    private function within($query, string $column, array $p)
    {
        return $query->when($p['from'], function ($q) use ($column, $p) {
            $q->where($column, '>=', $p['from']->toDateTimeString());
        })->when($p['to'], function ($q) use ($column, $p) {
            $q->where($column, '<=', $p['to']->toDateTimeString());
        });
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    /* ---------------- Summary ---------------- */

    public function summary(int $providerId, array $in): array
    {
        $p = $this->period($in);
        $reports = function () use ($providerId) {
            return DB::table('job_payment_reports')->where('provider_profile_id', $providerId);
        };
        $ledger = function () use ($providerId) {
            return DB::table('commission_ledger_entries')->where('provider_profile_id', $providerId);
        };

        $confirmed = $this->within($reports()->where('status', JobPaymentReport::CONFIRMED), 'responded_at', $p);
        $reported = $this->within($reports()->whereIn('status', [JobPaymentReport::PENDING, JobPaymentReport::CONFIRMED, JobPaymentReport::DISPUTED]), 'created_at', $p);
        $waiting = $reports()->where('status', JobPaymentReport::PENDING);
        $questioned = $reports()->where('status', JobPaymentReport::DISPUTED);
        [$remaining, $remainingJobs] = $this->remaining($providerId);

        $assessed = (int) $this->within($ledger()->where('entry_type', CommissionLedgerEntry::ASSESSMENT), 'created_at', $p)->sum('amount_ugx');
        $reversed = -(int) $this->within($ledger()->where('entry_type', CommissionLedgerEntry::REVERSAL), 'created_at', $p)->sum('amount_ugx');
        $settledInPeriod = -(int) $this->within($ledger()->where('entry_type', CommissionLedgerEntry::SETTLEMENT), 'created_at', $p)->sum('amount_ugx');
        $balance = (int) $ledger()->sum('amount_ugx');
        $totals = $this->settlements->statusTotals(['provider' => $providerId]);
        $currentId = $this->settlements->cycleSummary(['provider' => $providerId])->where('outstanding_ugx', '>', 0)->orderByDesc('id')->value('id');

        $hasHistory = $reports()->exists() || $ledger()->exists();

        return [
            'provider_id' => $providerId,
            'period' => ['key' => $p['key'], 'label' => $p['label'], 'from' => $p['from_date'], 'to' => $p['to_date']],
            'has_history' => $hasHistory,
            'customer_payments' => [
                'confirmed_ugx' => (int) (clone $confirmed)->sum('amount_ugx'), 'confirmed_count' => (clone $confirmed)->count(),
                'reported_ugx' => (int) (clone $reported)->sum('amount_ugx'), 'reported_count' => (clone $reported)->count(),
                // Today's position, not the period
                'awaiting' => ['count' => (clone $waiting)->count(), 'amount_ugx' => (int) (clone $waiting)->sum('amount_ugx')],
                'questioned' => ['count' => (clone $questioned)->count(), 'amount_ugx' => (int) (clone $questioned)->sum('amount_ugx')],
                'remaining_ugx' => $remaining, 'remaining_jobs' => $remainingJobs,
            ],
            'commission' => [
                'period' => ['assessed_ugx' => $assessed, 'reversed_ugx' => $reversed, 'net_ugx' => $assessed - $reversed, 'settled_ugx' => $settledInPeriod],
                'as_of' => [
                    'outstanding_ugx' => max($balance, 0), 'credit_ugx' => max(-$balance, 0),
                    'overdue_ugx' => $totals['overdue']['outstanding_ugx'] ?? 0, 'overdue_cycles' => $totals['overdue']['count'] ?? 0,
                    'current_cycle' => $currentId ? $this->settlements->state((int) $currentId) : null,
                    // Since the business began: what was assessed (before reversals), what verified payments took off, and what is waiting to be checked or approved
                    'assessed_total_ugx' => (int) DB::table('commission_ledger_entries')->where('provider_profile_id', $providerId)->where('entry_type', 'assessment')->sum('amount_ugx'),
                    'verified_total_ugx' => -(int) DB::table('commission_ledger_entries')->where('provider_profile_id', $providerId)->where('entry_type', 'settlement')->sum('amount_ugx'),
                    'pending_ugx' => $this->settlements->reserved($providerId),
                ],
                // What the backend allows right now (the screen only shows buttons for what is allowed)
                'can_report_payment' => $this->settlements->enabled(),
            ],
            'notes' => [
                'Customer payments are what customers paid you directly. WorkMate does not hold them. Only a payment the customer confirmed counts as paid.',
                'Commission is what you owe WorkMate on confirmed payments. Period figures are what was recorded in the period; outstanding and overdue are today’s position.',
            ],
        ];
    }

    /** What is left to pay on the provider's agreed, live jobs: the agreed price less what customers confirmed (never below zero) */
    private function remaining(int $providerId): array
    {
        $row = DB::table('service_requests as r')
            ->join('job_final_prices as f', function ($j) {
                $j->on('f.service_request_id', '=', 'r.id')->where('f.status', '=', JobFinalPrice::CURRENT);
            })
            ->leftJoin(DB::raw("(select service_request_id, sum(amount_ugx) as paid from job_payment_reports where status = 'confirmed' group by service_request_id) c"), 'c.service_request_id', '=', 'r.id')
            ->where('r.provider_profile_id', $providerId)->whereNull('r.deleted_at')->where('r.status', '!=', 'cancelled')
            ->whereRaw('f.amount_ugx > coalesce(c.paid, 0)')
            ->selectRaw('count(*) as jobs, coalesce(sum(f.amount_ugx - coalesce(c.paid, 0)), 0) as left_ugx')->first();

        return [(int) $row->left_ugx, (int) $row->jobs];
    }

    /* ---------------- Customer payments ---------------- */

    public function payments(int $providerId, array $in, int $page): array
    {
        $p = $this->period($in);
        $q = $this->within(DB::table('job_payment_reports as r')->join('service_requests as q', 'q.id', '=', 'r.service_request_id')
            ->leftJoin('categories as c', 'c.id', '=', 'q.category_id')->leftJoin('users as u', 'u.id', '=', 'r.customer_id')
            ->leftJoin('payment_disputes as d', 'd.payment_report_id', '=', 'r.id')
            ->where('r.provider_profile_id', $providerId), 'r.created_at', $p);
        if (! empty($in['status'])) {
            $q->where('r.status', $in['status']);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('r.id')->forPage(max($page, 1), self::PER_PAGE)->get([
            'r.id', 'r.service_request_id as job', 'q.title', 'c.name as service', 'u.name as customer', 'r.amount_ugx', 'r.method', 'r.paid_on', 'r.reference', 'r.status',
            'r.created_at', 'r.responded_at', 'r.cancel_reason', 'r.response_note', 'd.id as dispute_id', 'd.status as dispute_status',
        ]);

        return [
            'items' => $rows->map(function ($r) {
                return [
                    'id' => (int) $r->id, 'job' => (int) $r->job, 'title' => $r->title, 'service' => $r->service, 'customer' => $this->firstName($r->customer),
                    'amount_ugx' => (int) $r->amount_ugx, 'method' => $r->method, 'paid_on' => $r->paid_on, 'reference' => $r->reference, 'status' => $r->status,
                    'reported_at' => $this->iso($r->created_at), 'responded_at' => $this->iso($r->responded_at),
                    'note' => $r->status === JobPaymentReport::CANCELLED ? $r->cancel_reason : ($r->status === JobPaymentReport::DISPUTED ? $r->response_note : null),
                    'dispute' => $r->dispute_id ? ['id' => (int) $r->dispute_id, 'status' => $r->dispute_status] : null,
                ];
            })->values()->all(),
            'page' => max($page, 1), 'per_page' => self::PER_PAGE, 'total' => $total, 'period' => $p['label'],
        ];
    }

    private function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name !== '' ? explode(' ', $name)[0] : 'Customer';
    }

    /* ---------------- The commission statement ---------------- */

    /** The ledger query for one provider and period: every assessment, reversal and verified settlement, oldest first */
    private function ledgerQuery(int $providerId, array $p): Builder
    {
        return $this->within(DB::table('commission_ledger_entries as e')
            ->leftJoin('commission_assessments as a', 'a.id', '=', 'e.assessment_id')
            ->leftJoin('commission_cycle_obligations as o', 'o.assessment_id', '=', 'a.id')
            ->leftJoin('commission_payment_reports as pr', 'pr.id', '=', 'e.commission_payment_id')
            ->where('e.provider_profile_id', $providerId), 'e.created_at', $p)
            ->select('e.id', 'e.created_at', 'e.entry_type', 'e.amount_ugx', 'e.reason', 'e.reference', 'a.service_request_id as job', 'a.amount_ugx as payment_ugx', 'a.rate_bp',
                DB::raw('coalesce(e.cycle_id, o.cycle_id) as cycle_id'), 'pr.channel', 'pr.reference as payment_reference')
            ->orderBy('e.id');
    }

    /** Opening balance, the period's movements and the closing balance, all from the ledger */
    public function statementTotals(int $providerId, array $p): array
    {
        $ledger = function () use ($providerId) {
            return DB::table('commission_ledger_entries')->where('provider_profile_id', $providerId);
        };
        $opening = $p['from'] ? (int) $ledger()->where('created_at', '<', $p['from']->toDateTimeString())->sum('amount_ugx') : 0;
        $of = function ($type) use ($ledger, $p) {
            return (int) $this->within($ledger()->where('entry_type', $type), 'created_at', $p)->sum('amount_ugx');
        };
        $assessed = $of(CommissionLedgerEntry::ASSESSMENT);
        $reversed = -$of(CommissionLedgerEntry::REVERSAL);
        $settled = -$of(CommissionLedgerEntry::SETTLEMENT);
        $closing = $opening + $assessed - $reversed - $settled;
        $current = (int) $ledger()->sum('amount_ugx');
        $untilNow = ! $p['to'] || $p['to']->gte(now());

        return [
            'opening_ugx' => $opening, 'assessed_ugx' => $assessed, 'reversed_ugx' => $reversed, 'settled_ugx' => $settled, 'closing_ugx' => $closing,
            // When the statement runs up to now, its closing balance IS the provider's balance on the ledger
            'matches_ledger' => $untilNow ? $closing === $current : null,
        ];
    }

    private function describeLine($r, int $running): array
    {
        return [
            'id' => (int) $r->id, 'at' => $this->iso($r->created_at), 'type' => $r->entry_type, 'amount_ugx' => (int) $r->amount_ugx, 'balance_ugx' => $running,
            'job' => $r->job ? (int) $r->job : null, 'payment_ugx' => $r->payment_ugx !== null ? (int) $r->payment_ugx : null,
            'rate' => $r->rate_bp !== null ? CommissionPolicies::percent((int) $r->rate_bp) : null, 'cycle_id' => $r->cycle_id ? (int) $r->cycle_id : null,
            'channel' => $r->channel, 'payment_reference' => $r->payment_reference, 'reason' => $r->reason, 'reference' => $r->reference,
        ];
    }

    public function statement(int $providerId, array $in, int $page): array
    {
        $p = $this->period($in);
        $query = $this->ledgerQuery($providerId, $p);
        $total = DB::query()->fromSub(clone $query, 't')->count();
        $page = max($page, 1);
        $rows = (clone $query)->forPage($page, self::PER_PAGE)->get();
        $running = 0;
        if ($rows->isNotEmpty()) {
            // The balance just before the first line shown: the opening balance plus every earlier line of the period
            $running = $this->statementTotals($providerId, $p)['opening_ugx'] + (int) $this->within(DB::table('commission_ledger_entries')->where('provider_profile_id', $providerId)->where('id', '<', $rows->first()->id), 'created_at', $p)->sum('amount_ugx');
        }
        $lines = [];
        foreach ($rows as $r) {
            $running += (int) $r->amount_ugx;
            $lines[] = $this->describeLine($r, $running);
        }

        return ['period' => ['key' => $p['key'], 'label' => $p['label']], 'totals' => $this->statementTotals($providerId, $p), 'items' => $lines, 'page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total];
    }

    /** Every line of a statement, in batches (for the exports), with running balances */
    public function statementLines(int $providerId, array $p, int $limit = 20000): \Generator
    {
        $query = $this->ledgerQuery($providerId, $p);
        $running = $this->statementTotals($providerId, $p)['opening_ugx'];
        $n = 0;
        for ($page = 1; $n < $limit; $page++) {
            $rows = (clone $query)->forPage($page, 500)->get();
            if ($rows->isEmpty()) {
                return;
            }
            foreach ($rows as $r) {
                $running += (int) $r->amount_ugx;
                yield $this->describeLine($r, $running);
                $n++;
            }
        }
    }

    /* ---------------- Settlements ---------------- */

    public function cycles(int $providerId, array $in, int $page): array
    {
        $query = $this->settlements->cycleSummary(['provider' => $providerId])->when(! empty($in['status']), function ($q) use ($in) {
            $q->where('status', $in['status']);
        });
        $page = max($page, 1);
        $total = (clone $query)->count();
        $rows = (clone $query)->orderByDesc('id')->forPage($page, self::PER_PAGE)->get();
        $counts = [];
        foreach ($this->settlements->statusTotals(['provider' => $providerId]) as $status => $t) {
            $counts[$status] = $t['count'];
        }

        return [
            'items' => $rows->map(function ($r) {
                return $this->settlements->state((int) $r->id);
            })->values()->all(),
            'counts' => $counts, 'page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total,
        ];
    }

    /** One settlement of this business with the obligations behind it. A cycle of another business is "not found". */
    public function cycle(int $providerId, int $id): array
    {
        $cycle = CommissionSettlementCycle::where('provider_profile_id', $providerId)->whereKey($id)->first();
        if (! $cycle) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        $assessmentIds = DB::table('commission_cycle_obligations')->where('cycle_id', $id)->pluck('assessment_id');
        $open = CommissionLedgerEntry::whereIn('assessment_id', $assessmentIds)->select('assessment_id', DB::raw('sum(amount_ugx) as n'))->groupBy('assessment_id')->pluck('n', 'assessment_id');
        $titles = DB::table('service_requests')->whereIn('id', CommissionAssessment::whereIn('id', $assessmentIds)->pluck('service_request_id'))->pluck('title', 'id');

        return $this->settlements->state($id) + [
            'obligations' => CommissionAssessment::whereIn('id', $assessmentIds)->orderBy('id')->get()->map(function ($a) use ($open, $titles) {
                $left = (int) ($open[$a->id] ?? 0);

                return [
                    'id' => (int) $a->id, 'job' => (int) $a->service_request_id, 'title' => $titles[$a->service_request_id] ?? null, 'payment_ugx' => (int) $a->amount_ugx,
                    'rate' => CommissionPolicies::percent((int) $a->rate_bp), 'commission_ugx' => (int) $a->commission_ugx, 'outstanding_ugx' => $left,
                    'status' => $left > 0 ? 'recorded' : 'taken_off', 'assessed_at' => $a->assessed_at->toIso8601String(),
                ];
            })->values()->all(),
            'lines' => CommissionLedgerEntry::where(function ($w) use ($assessmentIds, $id) {
                $w->whereIn('assessment_id', $assessmentIds)->orWhere('cycle_id', $id);
            })->orderBy('id')->get()->map(function ($e) {
                return ['type' => $e->entry_type, 'amount_ugx' => (int) $e->amount_ugx, 'reason' => $e->reason, 'at' => $e->created_at->toIso8601String()];
            })->all(),
            'payments' => CommissionPaymentReport::where('cycle_id', $id)->orderBy('id')->get()->map(function ($r) {
                return $this->settlements->presentPayment($r, false);
            })->all(),
        ];
    }

    /** What this business reported paying WorkMate, newest first */
    public function commissionPayments(int $providerId, int $page): array
    {
        $q = CommissionPaymentReport::where('provider_profile_id', $providerId);
        $page = max($page, 1);

        return [
            'items' => (clone $q)->orderByDesc('id')->forPage($page, self::PER_PAGE)->get()->map(function ($r) {
                return $this->settlements->presentPayment($r, false);
            })->values()->all(),
            'page' => $page, 'per_page' => self::PER_PAGE, 'total' => (clone $q)->count(),
        ];
    }

    /* ---------------- Disputes ---------------- */

    public function disputes(int $providerId, array $in, int $page): array
    {
        $q = DB::table('payment_disputes as d')->join('job_payment_reports as r', 'r.id', '=', 'd.payment_report_id')->join('service_requests as q', 'q.id', '=', 'd.service_request_id')
            ->where('d.provider_profile_id', $providerId);
        if (! empty($in['status'])) {
            $q->whereIn('d.status', $in['status'] === 'open' ? [PaymentDispute::OPEN, PaymentDispute::DISCUSSION] : [$in['status']]);
        }
        $page = max($page, 1);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('d.id')->forPage($page, self::PER_PAGE)->get(['d.id', 'd.status', 'd.opened_at', 'd.resolved_at', 'd.resolution_type', 'd.financial_correction_required', 'r.amount_ugx', 'r.status as payment_status', 'q.title', 'd.service_request_id as job']);

        return [
            'items' => $rows->map(function ($d) {
                return [
                    'id' => (int) $d->id, 'status' => $d->status, 'job' => (int) $d->job, 'title' => $d->title, 'amount_ugx' => (int) $d->amount_ugx, 'payment_status' => $d->payment_status,
                    'opened_at' => $this->iso($d->opened_at), 'resolved_at' => $this->iso($d->resolved_at), 'resolution_type' => $d->resolution_type,
                    'financial_correction_required' => (bool) $d->financial_correction_required,
                ];
            })->values()->all(),
            'page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total,
        ];
    }

    /* ---------------- Statement exports ---------------- */

    public function business(int $providerId): string
    {
        $p = ProviderProfile::withTrashed()->find($providerId);

        return $p ? (string) $p->display_name : 'Provider';
    }

    /** The customer payments the customer CONFIRMED in a period (by the day of confirmation), oldest first, in batches */
    public function paymentLines(int $providerId, array $p, int $limit = 20000): \Generator
    {
        $query = $this->within(DB::table('job_payment_reports as r')->join('service_requests as q', 'q.id', '=', 'r.service_request_id')
            ->where('r.provider_profile_id', $providerId)->where('r.status', JobPaymentReport::CONFIRMED), 'r.responded_at', $p)
            ->select('r.id', 'r.created_at', 'r.responded_at', 'r.service_request_id as job', 'q.title', 'r.amount_ugx', 'r.method', 'r.reference', 'r.paid_on')->orderBy('r.id');
        $n = 0;
        for ($page = 1; $n < $limit; $page++) {
            $rows = (clone $query)->forPage($page, 500)->get();
            if ($rows->isEmpty()) {
                return;
            }
            foreach ($rows as $r) {
                yield [
                    'id' => (int) $r->id, 'confirmed_at' => $this->iso($r->responded_at), 'paid_on' => $r->paid_on, 'job' => (int) $r->job, 'title' => $r->title,
                    'amount_ugx' => (int) $r->amount_ugx, 'method' => $r->method, 'reference' => $r->reference,
                ];
                $n++;
            }
        }
    }

    /**
     * The same figures the summary shows, so an export reconciles with the screen: confirmed in the period, and (today's position, not the
     * period) what is still waiting for the customer or questioned.
     */
    public function paymentTotals(int $providerId, array $p): array
    {
        $reports = function () use ($providerId) {
            return DB::table('job_payment_reports')->where('provider_profile_id', $providerId);
        };
        $confirmed = $this->within($reports()->where('status', JobPaymentReport::CONFIRMED), 'responded_at', $p);
        $waiting = $reports()->where('status', JobPaymentReport::PENDING);
        $questioned = $reports()->where('status', JobPaymentReport::DISPUTED);

        return [
            'confirmed' => ['count' => (clone $confirmed)->count(), 'amount_ugx' => (int) (clone $confirmed)->sum('amount_ugx')],
            'awaiting' => ['count' => (clone $waiting)->count(), 'amount_ugx' => (int) (clone $waiting)->sum('amount_ugx')],
            'questioned' => ['count' => (clone $questioned)->count(), 'amount_ugx' => (int) (clone $questioned)->sum('amount_ugx')],
        ];
    }
}
