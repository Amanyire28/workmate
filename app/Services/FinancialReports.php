<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase B8, financial reports, read only, built from the ledger and the settlement records (nothing is stored for them, so a report can
 * never disagree with the ledger). Five reports, each with a stated period and the same filters in the screen and in the CSV:
 *
 *   obligations   one row per assessed commission: what was assessed, reversed and is still outstanding, with the policy version used
 *   collections   money WorkMate verified it received (never a pending or rejected report), by method, date and settlement
 *   outstanding   providers who owe commission now: balance, current settlement, due date, overdue state, last verified payment (as of now)
 *   performance   settlement cycles by state (all of them) and verified payment activity in the period
 *   adjustments   adjustment requests and reconciliation exceptions
 *
 * CSV: streamed in batches (never the whole result in memory, at most 50,000 rows), whole-UGX columns named as such, a period line, no contact
 * details (provider names only), and every text value that a spreadsheet could read as a formula is prefixed so it stays text.
 */
class FinancialReports
{
    public const KEYS = ['obligations', 'collections', 'outstanding', 'performance', 'adjustments'];
    public const MAX_EXPORT_ROWS = 50000;
    private const BATCH = 500;

    /** @var FinancialAdministration */
    private $admin;

    /** @var CommissionSettlements */
    private $settlements;

    public function __construct(FinancialAdministration $admin, CommissionSettlements $settlements)
    {
        $this->admin = $admin;
        $this->settlements = $settlements;
    }

    public function title(string $key): string
    {
        return [
            'obligations' => 'Commission obligations', 'collections' => 'Commission collections (verified)', 'outstanding' => 'Outstanding and overdue commission',
            'performance' => 'Settlement performance', 'adjustments' => 'Adjustments and exceptions',
        ][$key];
    }

    /** @return array<string, string> column key => label */
    public function columns(string $key): array
    {
        switch ($key) {
            case 'obligations':
                return ['assessed_at' => 'Assessed at', 'provider' => 'Provider', 'job' => 'Job', 'payment_report' => 'Payment report', 'category' => 'Category', 'profession' => 'Profession',
                    'payment_ugx' => 'Confirmed payment (UGX)', 'rate' => 'Rate', 'policy_version' => 'Policy version', 'commission_ugx' => 'Commission assessed (UGX)',
                    'reversed_ugx' => 'Reversed (UGX)', 'outstanding_ugx' => 'Still on the ledger (UGX)', 'approved_adjustments' => 'Approved adjustments (not posted)'];
            case 'collections':
                return ['decided_at' => 'Verified at', 'provider' => 'Provider', 'channel' => 'Method', 'reference' => 'Reference', 'paid_on' => 'Paid on', 'reported_ugx' => 'Reported (UGX)',
                    'verified_ugx' => 'Verified received (UGX)', 'credited_ugx' => 'Applied to commission (UGX)', 'excess_ugx' => 'Not applied (UGX)', 'cycle' => 'Settlement', 'payment' => 'Payment record'];
            case 'outstanding':
                return ['provider' => 'Provider', 'outstanding_ugx' => 'Outstanding (UGX)', 'cycle' => 'Current settlement', 'cycle_status' => 'Settlement state', 'due_at' => 'Due', 'overdue' => 'Overdue',
                    'last_verified_at' => 'Last verified payment'];
            case 'performance':
                return ['metric' => 'Measure', 'count' => 'Count', 'amount_ugx' => 'Amount (UGX)'];
            default:
                return ['record' => 'Record', 'id' => 'Number', 'at' => 'Date', 'provider' => 'Provider', 'status' => 'Status', 'kind' => 'Type', 'amount_ugx' => 'Amount (UGX)', 'detail' => 'Detail', 'note' => 'Decision or note'];
        }
    }

    /** The period line: "2026-11-01 to 2026-11-30", or "As of now" for the one report that is a position, not activity */
    public function periodLabel(string $key, array $filters): string
    {
        if ($key === 'outstanding') {
            return 'As of '.now()->toDateTimeString();
        }
        [$from, $to] = $this->admin->period($filters);

        return $this->admin->periodLabel($from, $to);
    }

    /** One page of a report for the screen */
    public function page(string $key, array $filters, int $page, int $per = 25): array
    {
        $this->guardKey($key);
        if ($key === 'performance') {
            $rows = $this->performance($filters);

            return ['report' => $key, 'title' => $this->title($key), 'period' => $this->periodLabel($key, $filters), 'columns' => $this->columns($key), 'items' => $rows, 'page' => 1, 'per_page' => count($rows), 'total' => count($rows)];
        }
        $query = $this->query($key, $filters);
        // (a grouped or combined query is counted as a whole, never by its first group)
        $total = DB::query()->fromSub(clone $query, 't')->count();
        $rows = $query->forPage(max($page, 1), $per)->get()->map(function ($r) use ($key) {
            return $this->map($key, $r);
        })->values()->all();

        return ['report' => $key, 'title' => $this->title($key), 'period' => $this->periodLabel($key, $filters), 'columns' => $this->columns($key), 'items' => $rows, 'page' => max($page, 1), 'per_page' => $per, 'total' => $total];
    }

    /** Streams a report as CSV to the output (batches; capped). */
    public function csv(string $key, array $filters)
    {
        $this->guardKey($key);
        $period = $this->periodLabel($key, $filters);
        $columns = $this->columns($key);
        $title = $this->title($key);

        return response()->streamDownload(function () use ($key, $filters, $period, $columns, $title) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // so spreadsheets read UTF-8
            fputcsv($out, [self::safe('# '.$title)]);
            fputcsv($out, [self::safe('# Period: '.$period)]);
            fputcsv($out, [self::safe('# Amounts are whole UGX. Generated '.now()->toDateTimeString())]);
            fputcsv($out, array_values($columns));
            $written = 0;
            if ($key === 'performance') {
                foreach ($this->performance($filters) as $row) {
                    fputcsv($out, array_map([self::class, 'safe'], array_values($this->ordered($columns, $row))));
                }
            } else {
                $query = $this->query($key, $filters);
                for ($page = 1; $written < self::MAX_EXPORT_ROWS; $page++) {
                    $rows = (clone $query)->forPage($page, self::BATCH)->get();
                    if ($rows->isEmpty()) {
                        break;
                    }
                    foreach ($rows as $r) {
                        fputcsv($out, array_map([self::class, 'safe'], array_values($this->ordered($columns, $this->map($key, $r)))));
                        $written++;
                    }
                    unset($rows);
                }
                if ($written >= self::MAX_EXPORT_ROWS) {
                    fputcsv($out, [self::safe('# Stopped at '.self::MAX_EXPORT_ROWS.' rows: narrow the period or the provider to see the rest')]);
                }
            }
            fclose($out);
        }, 'workmate-'.$key.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** Whole numbers stay numbers; text a spreadsheet could run as a formula (=, +, -, @, tab, return) is prefixed so it stays text */
    public static function safe($value)
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $text = (string) $value;
        if ($text !== '' && preg_match('/^[\s]*[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
    }

    private function ordered(array $columns, array $row): array
    {
        $out = [];
        foreach (array_keys($columns) as $k) {
            $out[$k] = $row[$k] ?? null;
        }

        return $out;
    }

    private function guardKey(string $key): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
    }

    /* ---------------- The queries ---------------- */

    private function query(string $key, array $filters): Builder
    {
        $provider = ! empty($filters['provider']) ? (int) $filters['provider'] : null;
        if ($key === 'outstanding') {
            return DB::table('commission_ledger_entries as e')->join('provider_profiles as p', 'p.id', '=', 'e.provider_profile_id')
                ->when($provider, function ($q) use ($provider) {
                    $q->where('e.provider_profile_id', $provider);
                })
                ->groupBy('e.provider_profile_id', 'p.display_name')->havingRaw('sum(e.amount_ugx) > 0')
                ->select('e.provider_profile_id as provider_id', 'p.display_name as provider', DB::raw('sum(e.amount_ugx) as outstanding_ugx'))
                ->orderByDesc('outstanding_ugx')->orderBy('e.provider_profile_id');
        }
        [$from, $to] = $this->admin->period($filters);

        if ($key === 'obligations') {
            return DB::table('commission_assessments as a')
                ->join('commission_shadow_snapshots as s', 's.id', '=', 'a.snapshot_id')
                ->leftJoin('categories as c', 'c.id', '=', 's.category_id')->leftJoin('professions as pr', 'pr.id', '=', 's.profession_id')
                ->leftJoin('provider_profiles as p', 'p.id', '=', 'a.provider_profile_id')
                ->whereBetween('a.assessed_at', [$from, $to])
                ->when($provider, function ($q) use ($provider) {
                    $q->where('a.provider_profile_id', $provider);
                })
                ->when(! empty($filters['category']), function ($q) use ($filters) {
                    $q->where('s.category_id', (int) $filters['category']);
                })
                ->when(! empty($filters['profession']), function ($q) use ($filters) {
                    $q->where('s.profession_id', (int) $filters['profession']);
                })
                ->select('a.id', 'a.assessed_at', 'p.display_name as provider', 'a.service_request_id as job', 'a.payment_report_id as payment_report', 'c.name as category', 'pr.name as profession',
                    'a.amount_ugx as payment_ugx', 'a.rate_bp', 'a.policy_version', 'a.commission_ugx',
                    DB::raw("(select coalesce(-sum(e.amount_ugx), 0) from commission_ledger_entries e where e.assessment_id = a.id and e.entry_type = 'reversal') as reversed_ugx"),
                    DB::raw('(select coalesce(sum(e.amount_ugx), 0) from commission_ledger_entries e where e.assessment_id = a.id) as outstanding_ugx'),
                    DB::raw("(select count(*) from commission_adjustment_requests r where r.assessment_id = a.id and r.status = 'approved') as approved_adjustments"))
                ->orderBy('a.id');
        }
        if ($key === 'collections') {
            return DB::table('commission_payment_reports as r')->leftJoin('provider_profiles as p', 'p.id', '=', 'r.provider_profile_id')
                ->where('r.status', 'verified')->whereBetween('r.decided_at', [$from, $to])
                ->when($provider, function ($q) use ($provider) {
                    $q->where('r.provider_profile_id', $provider);
                })
                ->select('r.id', 'r.decided_at', 'p.display_name as provider', 'r.channel', 'r.reference', 'r.paid_on', 'r.amount_ugx as reported_ugx', 'r.verified_amount_ugx as verified_ugx',
                    'r.credited_ugx', 'r.excess_ugx', 'r.cycle_id as cycle')
                ->orderBy('r.id');
        }

        // adjustments and exceptions, as one list
        $adjust = DB::table('commission_adjustment_requests')->whereBetween('requested_at', [$from, $to])->select(
            DB::raw("'adjustment' as record"), 'id', 'requested_at as at', 'provider_profile_id', 'status', 'type as kind', 'amount_ugx', DB::raw("concat(direction, ' what is owed') as detail"),
            'decision_reason as note');
        $except = DB::table('commission_exceptions')->whereBetween('created_at', [$from, $to])->select(
            DB::raw("'exception' as record"), 'id', 'created_at as at', 'provider_profile_id', 'status', 'kind', 'amount_ugx', 'detail', 'resolution_note as note');

        return DB::query()->fromSub($adjust->unionAll($except), 'u')->leftJoin('provider_profiles as p', 'p.id', '=', 'u.provider_profile_id')
            ->when($provider, function ($q) use ($provider) {
                $q->where('u.provider_profile_id', $provider);
            })
            ->select('u.record', 'u.id', 'u.at', 'p.display_name as provider', 'u.status', 'u.kind', 'u.amount_ugx', 'u.detail', 'u.note')
            ->orderByDesc('u.at')->orderBy('u.record')->orderByDesc('u.id');
    }

    private function map(string $key, $r): array
    {
        switch ($key) {
            case 'obligations':
                return [
                    'assessed_at' => Carbon::parse($r->assessed_at)->toDateTimeString(), 'provider' => $r->provider, 'job' => (int) $r->job, 'payment_report' => (int) $r->payment_report,
                    'category' => $r->category, 'profession' => $r->profession, 'payment_ugx' => (int) $r->payment_ugx, 'rate' => CommissionPolicies::percent((int) $r->rate_bp),
                    'policy_version' => (int) $r->policy_version, 'commission_ugx' => (int) $r->commission_ugx, 'reversed_ugx' => (int) $r->reversed_ugx,
                    'outstanding_ugx' => (int) $r->outstanding_ugx, 'approved_adjustments' => (int) $r->approved_adjustments,
                ];
            case 'collections':
                return [
                    'decided_at' => Carbon::parse($r->decided_at)->toDateTimeString(), 'provider' => $r->provider, 'channel' => $r->channel, 'reference' => $r->reference,
                    'paid_on' => $r->paid_on, 'reported_ugx' => (int) $r->reported_ugx, 'verified_ugx' => (int) $r->verified_ugx, 'credited_ugx' => (int) $r->credited_ugx,
                    'excess_ugx' => (int) $r->excess_ugx, 'cycle' => (int) $r->cycle, 'payment' => (int) $r->id,
                ];
            case 'outstanding':
                $cycle = $this->currentCycle((int) $r->provider_id);
                $last = DB::table('commission_payment_reports')->where('provider_profile_id', $r->provider_id)->where('status', 'verified')->max('decided_at');

                return [
                    'provider' => $r->provider, 'outstanding_ugx' => (int) $r->outstanding_ugx, 'cycle' => $cycle ? $cycle['id'] : null, 'cycle_status' => $cycle ? $cycle['status'] : null,
                    'due_at' => $cycle && $cycle['due_at'] ? Carbon::parse($cycle['due_at'])->toDateTimeString() : null, 'overdue' => $cycle && $cycle['status'] === 'overdue' ? 'Yes' : 'No',
                    'last_verified_at' => $last ? Carbon::parse($last)->toDateTimeString() : null,
                ];
            default:
                return [
                    'record' => $r->record, 'id' => (int) $r->id, 'at' => Carbon::parse($r->at)->toDateTimeString(), 'provider' => $r->provider, 'status' => $r->status, 'kind' => $r->kind,
                    'amount_ugx' => (int) $r->amount_ugx, 'detail' => $r->detail, 'note' => $r->note,
                ];
        }
    }

    /** The provider's newest cycle that still has something to pay (found by query, however old it is) */
    private function currentCycle(int $providerId): ?array
    {
        $id = $this->settlements->cycleSummary(['provider' => $providerId])->where('outstanding_ugx', '>', 0)->orderByDesc('id')->value('id');

        return $id ? $this->settlements->state((int) $id) : null;
    }

    /** Settlement performance: every cycle by state (counted by the database) and verified payment activity in the period */
    private function performance(array $filters): array
    {
        [$from, $to] = $this->admin->period($filters);
        $provider = ! empty($filters['provider']) ? (int) $filters['provider'] : null;
        $totals = $this->settlements->statusTotals($provider ? ['provider' => $provider] : []);
        $payments = function ($status, $col) use ($from, $to, $provider) {
            return DB::table('commission_payment_reports')->where('status', $status)->whereBetween($col, [$from, $to])->when($provider, function ($q) use ($provider) {
                $q->where('provider_profile_id', $provider);
            });
        };
        $rows = [['metric' => 'Settlement cycles (all)', 'count' => array_sum(array_column($totals, 'count')), 'amount_ugx' => null]];
        foreach (['settled' => 'Settled', 'open' => 'Open', 'due' => 'Due', 'partially_paid' => 'Partly paid', 'overdue' => 'Overdue', 'void' => 'Nothing owed'] as $k => $label) {
            $rows[] = ['metric' => $label.' cycles', 'count' => $totals[$k]['count'] ?? 0, 'amount_ugx' => in_array($k, ['open', 'due', 'partially_paid', 'overdue'], true) ? ($totals[$k]['outstanding_ugx'] ?? 0) : null];
        }
        $rows[] = ['metric' => 'Verified payments in the period', 'count' => (clone $payments('verified', 'decided_at'))->count(), 'amount_ugx' => (int) (clone $payments('verified', 'decided_at'))->sum('verified_amount_ugx')];
        $rows[] = ['metric' => 'Rejected payments in the period', 'count' => (clone $payments('rejected', 'decided_at'))->count(), 'amount_ugx' => null];
        $rows[] = ['metric' => 'Payments awaiting verification now', 'count' => DB::table('commission_payment_reports')->where('status', 'pending')->when($provider, function ($q) use ($provider) {
            $q->where('provider_profile_id', $provider);
        })->count(), 'amount_ugx' => null];

        return $rows;
    }
}
