<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\CommissionPaymentReport;
use App\Models\ProviderProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase B10: the administrator's one view of commission payments, manual proofs and gateway requests together. Read only: it adds no
 * figure of its own. Money comes from the same places as every other finance view (verified payment reports, the settlement cycles and the
 * ledger); deciding on a manual payment still goes through CommissionSettlements::verify and reject.
 *
 * Statuses shown: awaiting_verification (a manual proof waiting for finance), pending and processing (a gateway request waiting for the
 * provider's phone), successful (verified and credited), rejected, failed, expired, cancelled.
 */
class CommissionPaymentDirectory
{
    public const PER_PAGE = 25;
    public const STATUSES = ['awaiting_verification', 'pending', 'processing', 'successful', 'rejected', 'failed', 'expired', 'cancelled'];
    public const SORTS = ['submitted_at', 'amount_ugx'];
    public const MAX_EXPORT_ROWS = 5000;

    /** @var CommissionSettlements */
    private $settlements;

    public function __construct(CommissionSettlements $settlements)
    {
        $this->settlements = $settlements;
    }

    /** The cards at the top */
    public function summary(): array
    {
        $totals = $this->settlements->statusTotals();
        $attempts = DB::table('commission_payment_attempts');

        return [
            'verified_ugx' => (int) CommissionPaymentReport::where('status', CommissionPaymentReport::VERIFIED)->sum('credited_ugx'),
            'verified_count' => CommissionPaymentReport::where('status', CommissionPaymentReport::VERIFIED)->count(),
            'outstanding_ugx' => (int) array_sum(array_column($totals, 'outstanding_ugx')),
            'awaiting_count' => CommissionPaymentReport::where('status', CommissionPaymentReport::PENDING)->count(),
            'awaiting_ugx' => (int) CommissionPaymentReport::where('status', CommissionPaymentReport::PENDING)->sum('amount_ugx'),
            'gateway_pending_count' => (clone $attempts)->whereIn('status', ['pending', 'processing'])->where('expires_at', '>', now())->count(),
            'gateway_pending_ugx' => (int) (clone $attempts)->whereIn('status', ['pending', 'processing'])->where('expires_at', '>', now())->sum('amount_ugx'),
            'failed_count' => (clone $attempts)->whereIn('status', ['failed', 'expired'])->count() + CommissionPaymentReport::where('status', CommissionPaymentReport::REJECTED)->count(),
            'enabled' => $this->settlements->enabled(),
        ];
    }

    private function union(): \Illuminate\Database\Query\Builder
    {
        $reports = DB::table('commission_payment_reports as r')->selectRaw(
            "'report' as kind, r.id, coalesce(r.receipt_number, concat('PAY-', r.id)) as payment_ref, r.provider_profile_id, r.amount_ugx, r.channel as method, r.source, "
            ."r.reference as txn_reference, r.created_at as submitted_at, case r.status when 'verified' then 'successful' when 'rejected' then 'rejected' else 'awaiting_verification' end as status, "
            ."case r.status when 'verified' then (case when r.source = 'gateway' then 'automatic' else 'verified' end) when 'rejected' then 'rejected' else 'awaiting' end as verification"
        );
        $attempts = DB::table('commission_payment_attempts as a')->where('a.status', '!=', 'successful')->selectRaw(
            "'attempt' as kind, a.id, a.reference as payment_ref, a.provider_profile_id, a.amount_ugx, a.method, 'gateway' as source, a.gateway_reference as txn_reference, "
            ."a.created_at as submitted_at, a.status, 'not_applicable' as verification"
        );

        return $reports->unionAll($attempts);
    }

    private function filtered(array $filters): \Illuminate\Database\Query\Builder
    {
        $q = DB::query()->fromSub($this->union(), 'p')->leftJoin('provider_profiles as pp', 'pp.id', '=', 'p.provider_profile_id')
            ->select('p.*', 'pp.display_name as provider');
        if (! empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $q->where('p.status', $filters['status']);
        }
        if (! empty($filters['method'])) {
            $q->where('p.method', (string) $filters['method']);
        }
        if (! empty($filters['source']) && in_array($filters['source'], ['manual', 'gateway'], true)) {
            $q->where('p.source', $filters['source']);
        }
        if (! empty($filters['provider'])) {
            $q->where('p.provider_profile_id', (int) $filters['provider']);
        }
        if (! empty($filters['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $filters['q'])).'%';
            $q->where(function ($w) use ($like) {
                $w->where('p.payment_ref', 'like', $like)->orWhere('p.txn_reference', 'like', $like)->orWhere('pp.display_name', 'like', $like);
            });
        }
        if (! empty($filters['from'])) {
            $q->where('p.submitted_at', '>=', Carbon::parse($filters['from'], Scheduling::TIMEZONE)->startOfDay()->utc()->toDateTimeString());
        }
        if (! empty($filters['to'])) {
            $q->where('p.submitted_at', '<', Carbon::parse($filters['to'], Scheduling::TIMEZONE)->addDay()->startOfDay()->utc()->toDateTimeString());
        }

        return $q;
    }

    private function row($r): array
    {
        return [
            'kind' => $r->kind, 'id' => (int) $r->id, 'payment_ref' => $r->payment_ref, 'provider_id' => (int) $r->provider_profile_id, 'provider' => $r->provider,
            'amount_ugx' => (int) $r->amount_ugx, 'method' => $r->method, 'source' => $r->source, 'txn_reference' => $r->txn_reference,
            'submitted_at' => Carbon::parse($r->submitted_at, 'UTC')->toIso8601String(), 'status' => $r->status, 'verification' => $r->verification,
        ];
    }

    public function directory(array $filters, int $page): array
    {
        $sort = in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : 'submitted_at';
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $total = (clone $this->filtered($filters))->count();
        $rows = $this->filtered($filters)->orderBy('p.'.$sort, $dir)->orderBy('p.id', $dir)->forPage($page, self::PER_PAGE)->get();

        return ['items' => $rows->map(function ($r) {
            return $this->row($r);
        })->all(), 'page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total];
    }

    /** Everything about one payment: who, how much, the proof, what happened to it, who decided, where the money went */
    public function detail(string $kind, int $id): array
    {
        if ($kind === 'attempt') {
            $a = DB::table('commission_payment_attempts')->where('id', $id)->first();
            if (! $a) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            $profile = ProviderProfile::withTrashed()->find($a->provider_profile_id);
            $events = DB::table('commission_payment_attempt_events')->where('attempt_id', $id)->orderBy('id')->get();

            return [
                'kind' => 'attempt', 'id' => (int) $id, 'status' => $a->status, 'payment_ref' => $a->reference, 'provider' => $this->provider($profile),
                'payment' => ['amount_ugx' => (int) $a->amount_ugx, 'method' => $a->method, 'phone' => $a->phone_masked, 'gateway_reference' => $a->gateway_reference,
                    'failure' => $a->failure_code, 'expires_at' => Carbon::parse($a->expires_at, 'UTC')->toIso8601String(), 'payment_id' => $a->payment_report_id ? (int) $a->payment_report_id : null],
                'evidence' => null,
                'transactions' => $events->map(function ($e) {
                    return ['event' => $e->event, 'at' => Carbon::parse($e->created_at, 'UTC')->toIso8601String()];
                })->all(),
                'verification' => [], 'allocations' => [], 'audit' => $this->audit('commission_payment_attempts', $id),
            ];
        }
        $r = CommissionPaymentReport::find($id);
        if (! $r) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        $profile = ProviderProfile::withTrashed()->find($r->provider_profile_id);
        $cycle = $this->settlements->state((int) $r->cycle_id);
        $events = $r->attempt_id ? DB::table('commission_payment_attempt_events')->where('attempt_id', $r->attempt_id)->orderBy('id')->get() : collect();
        $decider = $r->decided_by_user_id ? DB::table('users')->where('id', $r->decided_by_user_id)->value('name') : null;

        return [
            'kind' => 'report', 'id' => (int) $id, 'status' => $r->status === 'verified' ? 'successful' : ($r->status === 'rejected' ? 'rejected' : 'awaiting_verification'),
            'payment_ref' => $r->receipt_number ?: 'PAY-'.$r->id, 'provider' => $this->provider($profile),
            'payment' => ['amount_ugx' => (int) $r->amount_ugx, 'method' => $r->channel, 'source' => $r->source, 'reference' => $r->reference, 'paid_on' => optional($r->paid_on)->toDateString(),
                'note' => $r->note, 'submitted_at' => $r->created_at->toIso8601String(), 'settlement_id' => (int) $r->cycle_id, 'settlement_status' => $cycle['status'],
                'settlement_outstanding_ugx' => max($cycle['outstanding_ugx'], 0)],
            'evidence' => $r->evidence_mime ? ['name' => $r->evidence_name, 'mime' => $r->evidence_mime, 'size' => $r->evidence_size] : null,
            'transactions' => $events->map(function ($e) {
                return ['event' => $e->event, 'at' => Carbon::parse($e->created_at, 'UTC')->toIso8601String()];
            })->all(),
            'verification' => $r->status === 'pending' ? [] : [[
                'decision' => $r->status, 'by' => $r->source === 'gateway' ? 'Gateway confirmation' : $decider, 'at' => optional($r->decided_at)->toIso8601String(), 'note' => $r->decision_note,
                'received_ugx' => $r->verified_amount_ugx, 'credited_ugx' => $r->credited_ugx, 'not_applied_ugx' => $r->excess_ugx,
            ]],
            'allocations' => DB::table('commission_ledger_entries')->where('commission_payment_id', $id)->orderBy('id')->get(['cycle_id', 'amount_ugx', 'reference', 'created_at'])->map(function ($l) {
                return ['cycle_id' => (int) $l->cycle_id, 'amount_ugx' => -(int) $l->amount_ugx, 'reference' => $l->reference, 'at' => Carbon::parse($l->created_at, 'UTC')->toIso8601String()];
            })->all(),
            'audit' => $this->audit('commission_payment_reports', $id),
        ];
    }

    private function provider(?ProviderProfile $p): array
    {
        if (! $p) {
            return ['id' => null, 'name' => null, 'outstanding_ugx' => 0];
        }

        return ['id' => (int) $p->id, 'name' => $p->display_name, 'outstanding_ugx' => $this->settlements->payable((int) $p->id)];
    }

    private function audit(string $table, int $id): array
    {
        $type = $table === 'commission_payment_attempts' ? null : CommissionPaymentReport::class;
        if (! $type) {
            return [];
        }

        return AuditLog::where('subject_type', $type)->where('subject_id', $id)->orderBy('id')->limit(50)->get()->map(function ($a) {
            return ['action' => $a->action, 'at' => $a->created_at->toIso8601String(), 'reason' => $a->reason];
        })->all();
    }

    /** The directory as CSV (needs the reports permission; the same filters; formula-neutralised) */
    public function csv(array $filters)
    {
        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map([FinancialReports::class, 'safe'], ['# Commission payments. Amounts are whole UGX. Generated '.now()->toDateTimeString()]));
            fputcsv($out, ['Payment reference', 'Provider', 'Amount (UGX)', 'Method', 'Source', 'Transaction reference', 'Submitted (UTC)', 'Status', 'Verification']);
            $n = 0;
            foreach ($this->filtered($filters)->orderBy('p.submitted_at', 'desc')->orderBy('p.id', 'desc')->limit(self::MAX_EXPORT_ROWS)->cursor() as $r) {
                fputcsv($out, array_map([FinancialReports::class, 'safe'], [$r->payment_ref, $r->provider, (int) $r->amount_ugx, $r->method, $r->source, $r->txn_reference, $r->submitted_at, $r->status, $r->verification]));
                $n++;
            }
            if ($n >= self::MAX_EXPORT_ROWS) {
                fputcsv($out, [FinancialReports::safe('# Stopped at '.self::MAX_EXPORT_ROWS.' rows: narrow the filters to see the rest')]);
            }
            fclose($out);
        }, 'commission-payments-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
