<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CommissionAssessment;
use App\Models\CommissionException;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPaymentReport;
use App\Models\CommissionSettlementCycle;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\Concerns\LocksPricedRequests;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase B7: how a provider settles the commission recorded in B6, and how WorkMate checks it was really paid.
 *
 * CYCLES. Each assessment (B6) belongs to exactly one settlement cycle of its provider, chosen once and never moved:
 *   it joins the provider's latest cycle while that cycle is still OPEN (its trigger not reached), otherwise a new cycle starts.
 *   The trigger is the EARLIER of: seven days after the first payable obligation in the cycle, or the obligation that brings the cycle
 *   to three DISTINCT jobs with commission (a job counts once however many of its payments are assessed; a job whose obligation was
 *   fully reversed counts for nothing). Nothing about a cycle is stored except who it belongs to: owed, credits, due date and state are
 *   always worked out from its obligations, reversals and verified credits, so a payment never resets a deadline and no balance can be
 *   edited. Cycle choice is made under a lock on the provider, in the order the obligations were assessed, so it is the same however many
 *   things happen at once.
 *
 *   OVERDUE. The seven-day deadline is the approved outer limit: an unpaid amount after it is overdue. For a cycle that became due early
 *   (three jobs) the code does not invent a deadline: it stays "due" until the seven-day limit, unless finance.settlement_three_job_window_hours
 *   (0 = none, the default) gives it a window. No penalty, interest, restriction, suspension or deduction exists anywhere in this class.
 *
 * PAYMENTS. A provider REPORTS paying WorkMate (mobile money, bank, or cash only if switched on); that statement creates no credit.
 * Finance (settlements.verify) independently confirms receipt and verifies it, which writes ONE negative SETTLEMENT entry into the B6 ledger
 * for that cycle, capped at what the cycle still owes; anything beyond that is recorded as an open reconciliation EXCEPTION, never applied
 * to another cycle, refunded or kept as a wallet. A rejected or pending report credits nothing. Nobody can verify a payment of their own
 * business. Verifying twice, or reporting the same reference twice, cannot create a second credit (unique ledger reference, unique active
 * reference, and the report row is locked).
 *
 * SAFE BY DEFAULT. Everything that writes needs finance.commission_settlement_enabled (off by default). No gateway, no collection.
 */
class CommissionSettlements
{
    use LocksPricedRequests;

    public const SEVEN_DAYS = 7;
    public const JOBS_TRIGGER = 3;
    public const MIN_AMOUNT = 100;
    public const MAX_AMOUNT = 100000000;
    public const MAX_NOTE = 300;
    public const MAX_REFERENCE = 60;
    public const MAX_PENDING = 5;

    /** @var PlatformSettings */
    private $settings;

    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    /** @var PrivateFiles */
    private $files;

    /** @var Pricing (for the shared trait helpers) */
    private $pricing;

    public function __construct(PlatformSettings $settings, AuditLogger $audit, EventNotifications $notify, PrivateFiles $files, Pricing $pricing)
    {
        $this->settings = $settings;
        $this->audit = $audit;
        $this->notify = $notify;
        $this->files = $files;
        $this->pricing = $pricing;
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('finance.commission_settlement_enabled');
    }

    /** The channels a provider may report paying through (cash only when an approved way of collecting it is switched on) */
    public function channels(): array
    {
        $channels = ['mtn_momo', 'airtel_money', 'bank_transfer'];
        if ((bool) $this->settings->get('finance.commission_accepts_cash')) {
            $channels[] = 'cash';
        }

        return $channels;
    }

    /** How WorkMate says to pay, exactly as approved in settings; null when none is approved (nothing is invented) */
    public function instructions(): ?string
    {
        $text = trim((string) $this->settings->get('finance.commission_payment_instructions'));

        return $text !== '' ? $text : null;
    }

    /* ---------------- Cycles ---------------- */

    /**
     * Puts an assessment in its cycle, once. Called inside the transaction that records it (the request and payment are locked), and by the
     * catch-up for assessments recorded while settlement was off. The provider is locked so cycle choice is one at a time.
     */
    public function allocate(CommissionAssessment $a): void
    {
        if (! $this->enabled() || DB::table('commission_cycle_obligations')->where('assessment_id', $a->id)->exists()) {
            return;
        }
        ProviderProfile::withTrashed()->whereKey($a->provider_profile_id)->lockForUpdate()->first();
        if (DB::table('commission_cycle_obligations')->where('assessment_id', $a->id)->exists()) {
            return;
        }

        $at = $a->assessed_at->copy();
        $latest = CommissionSettlementCycle::where('provider_profile_id', $a->provider_profile_id)->orderByDesc('id')->first();
        if ($latest && $this->state($latest->id, $at)['status'] === 'open') {
            $cycleId = (int) $latest->id;
        } else {
            $cycle = new CommissionSettlementCycle();
            $cycle->forceFill(['provider_profile_id' => $a->provider_profile_id, 'created_at' => $at])->save();
            $cycleId = (int) $cycle->id;
        }
        DB::table('commission_cycle_obligations')->insert(['cycle_id' => $cycleId, 'assessment_id' => $a->id, 'created_at' => $at]);

        $state = $this->state($cycleId, $at);
        if ($state['jobs'] >= self::JOBS_TRIGGER && $state['trigger'] === 'three_jobs') {
            // Kept for good: a reversal later may bring the live count back under three, but it cannot un-reach the trigger
            DB::table('commission_cycle_events')->insertOrIgnore([
                'cycle_id' => $cycleId, 'event' => 'three_jobs_reached', 'jobs' => $state['jobs'], 'happened_at' => Carbon::parse($state['due_at']), 'created_at' => now(),
            ]);
            $this->notice('three_jobs', (int) $a->provider_profile_id, $cycleId, 'cycle:'.$cycleId.':three_jobs');
        }
    }

    /**
     * Everything about one cycle, worked out from its obligations, reversals and verified credits. $at is "now" (or the moment an
     * obligation was assessed, when deciding which cycle it joins).
     *
     * @return array<string, mixed>
     */
    public function state(int $cycleId, ?Carbon $at = null): array
    {
        $at = $at ?: now();
        $cycle = CommissionSettlementCycle::find($cycleId);
        $rows = DB::table('commission_cycle_obligations as o')
            ->join('commission_assessments as a', 'a.id', '=', 'o.assessment_id')
            ->leftJoin('commission_ledger_entries as e', 'e.assessment_id', '=', 'a.id')
            ->where('o.cycle_id', $cycleId)
            ->groupBy('a.id', 'a.service_request_id', 'a.assessed_at')
            ->orderBy('a.assessed_at')->orderBy('a.id')
            ->get(['a.id', 'a.service_request_id', 'a.assessed_at', DB::raw('coalesce(sum(e.amount_ugx), 0) as open_ugx')]);
        $active = $rows->filter(function ($r) use ($at) {
            // Only obligations that existed by $at count towards deciding where a new one goes
            return (int) $r->open_ugx > 0 && Carbon::parse($r->assessed_at)->lte($at);
        })->values();

        $firstAt = $active->isEmpty() ? null : Carbon::parse($active->first()->assessed_at);
        $sevenAt = $firstAt ? $firstAt->copy()->addDays(self::SEVEN_DAYS) : null;
        $jobs = [];
        $thirdAt = null;
        foreach ($active as $r) {
            if (! isset($jobs[$r->service_request_id])) {
                $jobs[$r->service_request_id] = true;
                if (count($jobs) === self::JOBS_TRIGGER) {
                    $thirdAt = Carbon::parse($r->assessed_at);
                }
            }
        }

        $dueAt = $sevenAt;
        $trigger = $sevenAt ? 'seven_days' : null;
        if ($thirdAt && (! $sevenAt || $thirdAt->lt($sevenAt))) {
            $dueAt = $thirdAt;
            $trigger = 'three_jobs';
        }
        // The seven-day deadline is the approved outer limit; a cycle that became due earlier has only the optional window
        $overdueAt = $sevenAt;
        $window = (int) $this->settings->get('finance.settlement_three_job_window_hours');
        if ($trigger === 'three_jobs' && $window > 0 && $thirdAt && $thirdAt->copy()->addHours($window)->lt($sevenAt)) {
            $overdueAt = $thirdAt->copy()->addHours($window);
        }

        $payable = (int) $rows->sum('open_ugx');
        $credits = -(int) CommissionLedgerEntry::where('cycle_id', $cycleId)->where('entry_type', CommissionLedgerEntry::SETTLEMENT)->sum('amount_ugx');
        $outstanding = $payable - $credits;

        if ($payable === 0 && $credits === 0) {
            $status = 'void';        // everything in it was reversed: nothing was ever payable
        } elseif ($outstanding <= 0) {
            $status = 'settled';
        } elseif ($overdueAt && $at->gt($overdueAt)) {
            $status = 'overdue';
        } elseif ($credits > 0) {
            $status = 'partially_paid';
        } elseif ($dueAt && $at->gte($dueAt)) {
            $status = 'due';
        } else {
            $status = 'open';
        }

        // What happened, as recorded then (never recalculated): the three-job trigger, even if a reversal has since taken the live count back
        $history = DB::table('commission_cycle_events')->where('cycle_id', $cycleId)->orderBy('id')->get()->map(function ($e) use ($trigger, $jobs) {
            return [
                'event' => $e->event, 'at' => Carbon::parse($e->happened_at)->toIso8601String(), 'jobs' => (int) $e->jobs,
                // Whether the cycle still stands on it today, or a reversal has since taken it back below three jobs
                'in_effect' => $trigger === 'three_jobs' && count($jobs) >= self::JOBS_TRIGGER,
            ];
        })->all();

        return [
            'id' => $cycleId, 'provider_id' => $cycle ? (int) $cycle->provider_profile_id : null, 'status' => $status,
            'history' => $history,
            'jobs' => count($jobs), 'first_obligation_at' => $firstAt ? $firstAt->toIso8601String() : null,
            'due_at' => $dueAt ? $dueAt->toIso8601String() : null, 'trigger' => $trigger,
            'overdue_after' => $overdueAt ? $overdueAt->toIso8601String() : null,
            'payable_ugx' => $payable, 'credits_ugx' => $credits, 'outstanding_ugx' => $outstanding,
            'created_at' => $cycle ? $cycle->created_at->toIso8601String() : null,
        ];
    }

    /* ---------------- A provider reports paying WorkMate ---------------- */

    /**
     * @param  array{cycle_id: int, amount: mixed, channel: string, reference: ?string, paid_on: ?string, note: ?string, client_token: ?string}  $data
     */
    public function report(User $user, array $data, ?UploadedFile $file): CommissionPaymentReport
    {
        $this->guardEnabled();
        $amount = $this->wholeUgx($data['amount'] ?? null);
        $channel = $data['channel'] ?? null;
        if (! in_array($channel, $this->channels(), true)) {
            throw new BusinessRuleException('channel_invalid', 'Choose how you paid WorkMate.', 422);
        }
        $reference = $this->reference($data['reference'] ?? null, $channel);
        $paidOn = $this->date($data['paid_on'] ?? null);
        $note = $this->note($data['note'] ?? null);
        $token = isset($data['client_token']) && $data['client_token'] !== '' ? mb_substr((string) $data['client_token'], 0, 64) : null;
        $cycleId = (int) ($data['cycle_id'] ?? 0);

        $stored = null;
        try {
            $report = DB::transaction(function () use ($user, $amount, $channel, $reference, $paidOn, $note, $token, $cycleId, $file, &$stored) {
                $cycle = CommissionSettlementCycle::find($cycleId);
                $providerId = $cycle && in_array((int) $cycle->provider_profile_id, $this->providerIdsFor($user), true) ? (int) $cycle->provider_profile_id : null;
                if (! $providerId) {
                    throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
                }
                ProviderProfile::whereKey($providerId)->lockForUpdate()->first();

                if ($token) {
                    $same = CommissionPaymentReport::where('provider_profile_id', $providerId)->where('client_token', $token)->first();
                    if ($same) {
                        return $same;
                    }
                }
                if ($this->state($cycleId)['outstanding_ugx'] <= 0) {
                    throw new BusinessRuleException('cycle_settled', 'This settlement has nothing left to pay.', 409);
                }
                if (CommissionPaymentReport::where('provider_profile_id', $providerId)->where('status', CommissionPaymentReport::PENDING)->count() >= self::MAX_PENDING) {
                    throw new BusinessRuleException('too_many_pending', 'You already have '.self::MAX_PENDING.' payments waiting to be checked. Wait for WorkMate to check them first.', 429);
                }
                $key = $reference !== null ? $channel.':'.mb_strtolower($reference) : null;
                if ($key && CommissionPaymentReport::where('active_reference', $key)->exists()) {
                    throw new BusinessRuleException('duplicate_reference', 'That payment reference was already reported.', 409);
                }

                $evidence = null;
                if ($file) {
                    $evidence = $this->files->store($file, 'commission-evidence', true);
                    $stored = $evidence['path'];
                }
                $row = new CommissionPaymentReport();
                $row->forceFill([
                    'provider_profile_id' => $providerId, 'cycle_id' => $cycleId, 'reported_by_user_id' => $user->getKey(), 'amount_ugx' => $amount,
                    'channel' => $channel, 'reference' => $reference, 'active_reference' => $key, 'paid_on' => $paidOn, 'note' => $note, 'client_token' => $token,
                    'evidence_path' => $evidence ? $evidence['path'] : null, 'evidence_name' => $evidence ? mb_substr($evidence['name'], 0, 120) : null,
                    'evidence_mime' => $evidence ? $evidence['mime'] : null, 'evidence_size' => $evidence ? $evidence['size'] : null,
                    'status' => CommissionPaymentReport::PENDING,
                ])->save();
                $this->audit->record('commission.payment_reported', $row, [], ['cycle_id' => $cycleId, 'channel' => $channel, 'amount_ugx' => $amount], null, $user);
                $this->notice('payment_awaiting', $providerId, $cycleId, 'payment:'.$row->id.':awaiting', $row->id);

                return $row;
            }, 3);
        } catch (\Throwable $e) {
            if ($stored) {
                $this->files->discard([$stored]);
            }
            throw $e;
        }

        return $report;
    }

    /* ---------------- Finance verifies ---------------- */

    /**
     * Finance confirms the money really arrived. `$receiptConfirmed` is the explicit "I checked this against WorkMate's own records".
     * Credits at most what the cycle still owes; the rest is an exception. Once: a repeat returns the same decision.
     */
    public function verify(User $admin, int $id, $verifiedAmount, ?string $note, bool $receiptConfirmed): CommissionPaymentReport
    {
        if (! $receiptConfirmed) {
            throw new BusinessRuleException('receipt_not_confirmed', 'Confirm that you checked the money arrived in WorkMate’s own records before verifying.', 422);
        }
        $amount = $this->wholeUgx($verifiedAmount);
        $note = $this->note($note);

        return DB::transaction(function () use ($admin, $id, $amount, $note) {
            $probe = CommissionPaymentReport::find($id);
            if (! $probe) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            $this->guardNotOwnBusiness($admin, $probe);
            $report = CommissionPaymentReport::whereKey($id)->lockForUpdate()->first();
            ProviderProfile::withTrashed()->whereKey($report->provider_profile_id)->lockForUpdate()->first();

            if ($report->status === CommissionPaymentReport::VERIFIED) {
                if ((int) $report->decided_by_user_id === (int) $admin->getKey() && (int) $report->verified_amount_ugx === $amount) {
                    return $report;
                }
                throw new BusinessRuleException('already_decided', 'This payment has already been verified.', 409);
            }
            if ($report->status !== CommissionPaymentReport::PENDING) {
                throw new BusinessRuleException('already_decided', 'This payment has already been decided.', 409);
            }
            if ($amount !== (int) $report->amount_ugx && ($note === null || mb_strlen($note) < 5)) {
                throw new BusinessRuleException('note_required', 'The amount you confirmed differs from what was reported. Say why.', 422);
            }

            $owed = max($this->state((int) $report->cycle_id)['outstanding_ugx'], 0);
            $credited = min($amount, $owed);
            $excess = $amount - $credited;

            $entryId = null;
            if ($credited > 0) {
                $entry = new CommissionLedgerEntry();
                $entry->forceFill([
                    'assessment_id' => null, 'cycle_id' => $report->cycle_id, 'commission_payment_id' => $report->id, 'provider_profile_id' => $report->provider_profile_id,
                    'entry_type' => CommissionLedgerEntry::SETTLEMENT, 'amount_ugx' => -$credited, 'reason' => 'payment_verified', 'reference' => 'settle:'.$report->id,
                    'dispute_id' => null, 'actor_id' => $admin->getKey(), 'created_at' => now(),
                ])->save();
                $entryId = (int) $entry->id;
            }
            if ($excess > 0) {
                $this->exception(CommissionException::OVERPAYMENT, (int) $report->provider_profile_id, (int) $report->id, (int) $report->cycle_id, $excess,
                    'More was received than the cycle owed; the extra is not applied anywhere until a reconciliation decision.');
            }

            $report->forceFill([
                'status' => CommissionPaymentReport::VERIFIED, 'decided_by_user_id' => $admin->getKey(), 'decided_at' => now(), 'decision_note' => $note,
                'verified_amount_ugx' => $amount, 'credited_ugx' => $credited, 'excess_ugx' => $excess, 'ledger_entry_id' => $entryId,
            ])->save();
            $this->audit->record('commission.payment_verified', $report, ['status' => 'pending'], [
                'status' => 'verified', 'verified_amount_ugx' => $amount, 'credited_ugx' => $credited, 'excess_ugx' => $excess, 'cycle_id' => (int) $report->cycle_id,
            ], $note, $admin);
            $this->notice('payment_verified', (int) $report->provider_profile_id, (int) $report->cycle_id, 'payment:'.$report->id.':verified', $report->id, $admin);
            $this->reconcileProvider((int) $report->provider_profile_id);

            return $report;
        }, 3);
    }

    /** Finance does not accept a reported payment. It credits nothing; the reason is kept and the provider can read it. */
    public function reject(User $admin, int $id, ?string $reason): CommissionPaymentReport
    {
        $reason = $this->note($reason);
        if ($reason === null || mb_strlen($reason) < 5) {
            throw new BusinessRuleException('reason_required', 'Say why this payment is not accepted. The provider will read it.', 422);
        }

        return DB::transaction(function () use ($admin, $id, $reason) {
            $probe = CommissionPaymentReport::find($id);
            if (! $probe) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            $this->guardNotOwnBusiness($admin, $probe);
            $report = CommissionPaymentReport::whereKey($id)->lockForUpdate()->first();

            if ($report->status === CommissionPaymentReport::REJECTED && (int) $report->decided_by_user_id === (int) $admin->getKey()) {
                return $report;
            }
            if ($report->status !== CommissionPaymentReport::PENDING) {
                throw new BusinessRuleException('already_decided', 'This payment has already been decided.', 409);
            }
            $report->forceFill([
                'status' => CommissionPaymentReport::REJECTED, 'decided_by_user_id' => $admin->getKey(), 'decided_at' => now(), 'decision_note' => $reason,
                'active_reference' => null, 'credited_ugx' => 0,
            ])->save();
            $this->audit->record('commission.payment_rejected', $report, ['status' => 'pending'], ['status' => 'rejected'], $reason, $admin);
            $this->notice('payment_rejected', (int) $report->provider_profile_id, (int) $report->cycle_id, 'payment:'.$report->id.':rejected', $report->id, $admin);

            return $report;
        }, 3);
    }

    /** Nobody decides on a payment of a business they belong to, or one they reported themselves */
    private function guardNotOwnBusiness(User $admin, CommissionPaymentReport $report): void
    {
        $own = (int) $report->reported_by_user_id === (int) $admin->getKey()
            || ProviderMember::where('provider_profile_id', $report->provider_profile_id)->where('user_id', $admin->getKey())->exists();
        if ($own) {
            throw new BusinessRuleException('own_payment', 'You can’t decide on a payment from your own business.', 403);
        }
    }

    /* ---------------- Reconciliation ---------------- */

    /**
     * A provider whose credits exceed what they owe (an obligation was reversed after they paid) is recorded as an open exception. Nothing
     * is refunded, moved or kept as a wallet: it waits for an approved reconciliation decision. Safe to repeat.
     */
    public function reconcileProvider(int $providerId): void
    {
        $balance = (int) CommissionLedgerEntry::where('provider_profile_id', $providerId)->sum('amount_ugx');
        $open = CommissionException::where('provider_profile_id', $providerId)->where('kind', CommissionException::EXCESS_CREDIT)->where('status', 'open')->first();
        if ($balance < 0) {
            if ($open) {
                if ((int) $open->amount_ugx !== -$balance) {
                    $open->forceFill(['amount_ugx' => -$balance])->save();
                }
            } else {
                $this->exception(CommissionException::EXCESS_CREDIT, $providerId, null, null, -$balance,
                    'Verified payments now exceed what is owed (an obligation was reversed). Waiting for a reconciliation decision.');
            }
        } elseif ($open) {
            $open->forceFill(['status' => 'resolved', 'detail' => 'The balance is back to zero or above.'])->save();
        }
    }

    private function exception(string $kind, int $providerId, ?int $paymentId, ?int $cycleId, int $amount, string $detail): void
    {
        $row = new CommissionException();
        $row->forceFill([
            'provider_profile_id' => $providerId, 'kind' => $kind, 'commission_payment_id' => $paymentId, 'cycle_id' => $cycleId, 'amount_ugx' => $amount,
            'status' => 'open', 'detail' => $detail, 'created_at' => now(),
        ])->save();
        $this->audit->record('commission.exception_opened', $row, [], ['kind' => $kind, 'amount_ugx' => $amount, 'provider_profile_id' => $providerId], $detail, null);
    }

    /* ---------------- The scheduled catch-up ---------------- */

    /**
     * Bounded and idempotent. Puts assessments recorded while settlement was off into their cycles (in the order they were assessed), then
     * sends the reminders that are due: a cycle due within a day, and a cycle past its deadline. Each reminder goes out once.
     *
     * @return array{allocated: int, reminders: int}
     */
    public function catchUp(int $limit = 300): array
    {
        $out = ['allocated' => 0, 'reminders' => 0];
        if (! $this->enabled()) {
            return $out;
        }
        $missing = CommissionAssessment::query()->leftJoin('commission_cycle_obligations as o', 'o.assessment_id', '=', 'commission_assessments.id')
            ->whereNull('o.id')->orderBy('commission_assessments.assessed_at')->orderBy('commission_assessments.id')->limit($limit)->get(['commission_assessments.*']);
        foreach ($missing as $a) {
            DB::transaction(function () use ($a) {
                $this->allocate($a);
            });
            $out['allocated']++;
        }

        foreach (CommissionSettlementCycle::orderByDesc('id')->limit($limit)->get() as $cycle) {
            $s = $this->state((int) $cycle->id);
            if ($s['outstanding_ugx'] <= 0 || ! $s['due_at']) {
                continue;
            }
            if ($s['status'] === 'overdue' && $this->notice('overdue', (int) $cycle->provider_profile_id, (int) $cycle->id, 'cycle:'.$cycle->id.':overdue')) {
                $out['reminders']++;
            } elseif (in_array($s['status'], ['open', 'due', 'partially_paid'], true) && now()->addDay()->gte(Carbon::parse($s['overdue_after'] ?: $s['due_at']))
                && $this->notice('approaching', (int) $cycle->provider_profile_id, (int) $cycle->id, 'cycle:'.$cycle->id.':approaching')) {
                $out['reminders']++;
            }
        }

        return $out;
    }

    /** Sends a reminder once (the key is claimed first). Returns whether it was sent now. */
    private function notice(string $kind, int $providerId, ?int $cycleId, string $key, ?int $paymentId = null, ?User $actor = null): bool
    {
        $claimed = DB::table('commission_settlement_notices')->insertOrIgnore(['notice_key' => $key, 'created_at' => now()]);
        if (! $claimed) {
            return false;
        }
        $this->notify->settlement($kind, $providerId, $cycleId, $paymentId, $actor);

        return true;
    }

    /* ---------------- Who and what is shown ---------------- */

    public function providerIdsFor(User $user): array
    {
        return ProviderMember::where('user_id', $user->getKey())->whereIn('role', [ProviderMember::ROLE_OWNER, ProviderMember::ROLE_MANAGER])->pluck('provider_profile_id')->all();
    }

    /** The settlement part of a provider's own view (null while settlement is off) */
    public function forProvider(array $providerIds): ?array
    {
        if (! $this->enabled() || ! $providerIds) {
            return null;
        }
        $cycles = CommissionSettlementCycle::whereIn('provider_profile_id', $providerIds)->orderByDesc('id')->limit(12)->get();
        $states = $cycles->map(function ($c) {
            return $this->state((int) $c->id);
        })->all();
        $reports = CommissionPaymentReport::whereIn('provider_profile_id', $providerIds)->orderByDesc('id')->limit(20)->get();
        $current = collect($states)->first(function ($s) {
            return in_array($s['status'], ['open', 'due', 'partially_paid', 'overdue'], true);
        });

        return [
            'instructions' => $this->instructions(),
            'channels' => $this->channels(),
            'current' => $current,
            'cycles' => $states,
            'payments' => $reports->map(function (CommissionPaymentReport $r) {
                return $this->presentPayment($r, false);
            })->values()->all(),
            'pending_ugx' => (int) CommissionPaymentReport::whereIn('provider_profile_id', $providerIds)->where('status', CommissionPaymentReport::PENDING)->sum('amount_ugx'),
            'verified_ugx' => (int) CommissionPaymentReport::whereIn('provider_profile_id', $providerIds)->where('status', CommissionPaymentReport::VERIFIED)->sum('credited_ugx'),
        ];
    }

    public function presentPayment(CommissionPaymentReport $r, bool $forAdmin): array
    {
        return [
            'id' => (int) $r->id, 'cycle_id' => (int) $r->cycle_id, 'amount_ugx' => (int) $r->amount_ugx, 'channel' => $r->channel, 'reference' => $r->reference,
            'paid_on' => optional($r->paid_on)->toDateString(), 'note' => $r->note, 'status' => $r->status, 'reported_at' => $r->created_at->toIso8601String(),
            'decided_at' => optional($r->decided_at)->toIso8601String(), 'decision_note' => $r->decision_note,
            'credited_ugx' => $r->credited_ugx, 'excess_ugx' => $r->excess_ugx, 'verified_amount_ugx' => $r->verified_amount_ugx,
            'evidence' => $r->evidence_mime ? ['name' => $r->evidence_name, 'mime' => $r->evidence_mime, 'size' => $r->evidence_size] : null,
        ] + ($forAdmin ? ['provider_id' => (int) $r->provider_profile_id] : []);
    }

    /** A reported payment's proof, for its own business or an administrator: [path, mime, name] */
    public function evidence(CommissionPaymentReport $r): array
    {
        if (! $r->evidence_path) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }

        return ['path' => $r->evidence_path, 'mime' => $r->evidence_mime, 'name' => $r->evidence_name ?: 'Evidence'];
    }

    /** Administrators: cycles (filtered, newest first) with their derived state. Bounded to the latest 500 matching cycles. */
    public function adminCycles(array $filters): array
    {
        $q = CommissionSettlementCycle::query();
        if (! empty($filters['provider'])) {
            $q->where('provider_profile_id', (int) $filters['provider']);
        }
        if (! empty($filters['from'])) {
            $q->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('created_at', '<=', $filters['to']);
        }
        $names = [];
        $all = $q->orderByDesc('id')->limit(500)->get()->map(function ($c) {
            return $this->state((int) $c->id);
        });
        if (! empty($filters['status'])) {
            $all = $all->where('status', $filters['status']);
        }
        $all = $all->values();
        $page = max((int) ($filters['page'] ?? 1), 1);
        $per = 25;
        $slice = $all->forPage($page, $per)->values();
        $names = ProviderProfile::withTrashed()->whereIn('id', $slice->pluck('provider_id'))->pluck('display_name', 'id');

        return [
            'items' => $slice->map(function ($s) use ($names) {
                return $s + ['provider' => $names[$s['provider_id']] ?? null];
            })->all(),
            'counts' => $all->groupBy('status')->map->count()->all(),
            'page' => $page, 'per_page' => $per, 'total' => $all->count(),
        ];
    }

    public function adminCycle(int $id): ?array
    {
        $cycle = CommissionSettlementCycle::find($id);
        if (! $cycle) {
            return null;
        }
        $provider = ProviderProfile::withTrashed()->find($cycle->provider_profile_id);
        $assessmentIds = DB::table('commission_cycle_obligations')->where('cycle_id', $id)->pluck('assessment_id');

        return $this->state($id) + [
            'provider' => $provider ? $provider->display_name : null,
            'obligations' => CommissionAssessment::whereIn('id', $assessmentIds)->orderBy('id')->get()->map(function ($a) {
                return ['id' => (int) $a->id, 'request_id' => (int) $a->service_request_id, 'commission_ugx' => (int) $a->commission_ugx, 'rate_bp' => (int) $a->rate_bp, 'assessed_at' => $a->assessed_at->toIso8601String()];
            })->all(),
            'ledger' => CommissionLedgerEntry::where(function ($w) use ($assessmentIds, $id) {
                $w->whereIn('assessment_id', $assessmentIds)->orWhere('cycle_id', $id);
            })->orderBy('id')->get()->map(function ($e) {
                return ['type' => $e->entry_type, 'amount_ugx' => (int) $e->amount_ugx, 'reason' => $e->reason, 'reference' => $e->reference, 'at' => $e->created_at->toIso8601String()];
            })->all(),
            'payments' => CommissionPaymentReport::where('cycle_id', $id)->orderBy('id')->get()->map(function ($r) {
                return $this->presentPayment($r, true);
            })->all(),
        ];
    }

    public function adminPayments(array $filters): array
    {
        $q = CommissionPaymentReport::query();
        $status = $filters['status'] ?? 'pending';
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if (! empty($filters['provider'])) {
            $q->where('provider_profile_id', (int) $filters['provider']);
        }
        $total = (clone $q)->count();
        $page = max((int) ($filters['page'] ?? 1), 1);
        $rows = $q->orderByDesc('id')->forPage($page, 25)->get();
        $names = ProviderProfile::withTrashed()->whereIn('id', $rows->pluck('provider_profile_id'))->pluck('display_name', 'id');

        return [
            'items' => $rows->map(function ($r) use ($names) {
                $cycle = $this->state((int) $r->cycle_id);

                return $this->presentPayment($r, true) + ['provider' => $names[$r->provider_profile_id] ?? null, 'cycle_status' => $cycle['status'], 'cycle_outstanding_ugx' => $cycle['outstanding_ugx']];
            })->all(),
            'counts' => CommissionPaymentReport::select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all(),
            'page' => $page, 'per_page' => 25, 'total' => $total,
        ];
    }

    public function adminExceptions(): array
    {
        $rows = CommissionException::orderByDesc('id')->limit(100)->get();
        $names = ProviderProfile::withTrashed()->whereIn('id', $rows->pluck('provider_profile_id'))->pluck('display_name', 'id');

        return $rows->map(function ($e) use ($names) {
            return [
                'id' => (int) $e->id, 'kind' => $e->kind, 'provider_id' => (int) $e->provider_profile_id, 'provider' => $names[$e->provider_profile_id] ?? null,
                'amount_ugx' => (int) $e->amount_ugx, 'status' => $e->status, 'detail' => $e->detail, 'payment_id' => $e->commission_payment_id, 'cycle_id' => $e->cycle_id, 'at' => $e->created_at->toIso8601String(),
            ];
        })->all();
    }

    /* ---------------- Checks ---------------- */

    private function guardEnabled(): void
    {
        if (! $this->enabled()) {
            throw new BusinessRuleException('settlement_unavailable', 'This isn’t available right now.', 409);
        }
    }

    private function wholeUgx($value): int
    {
        if ($value === null || is_bool($value) || is_float($value) || ! ctype_digit((string) $value) || (int) $value < self::MIN_AMOUNT || (int) $value > self::MAX_AMOUNT) {
            throw new BusinessRuleException('amount_invalid', 'Enter the amount in UGX, from '.number_format(self::MIN_AMOUNT).' to '.number_format(self::MAX_AMOUNT).'.', 422);
        }

        return (int) $value;
    }

    private function reference($value, string $channel): ?string
    {
        $value = $this->text($value ?? '', self::MAX_REFERENCE + 1);
        if ($value === '') {
            if ($channel === 'cash') {
                return null;
            }
            throw new BusinessRuleException('reference_required', 'Enter the transaction reference from your payment message or receipt.', 422);
        }
        if (mb_strlen($value) > self::MAX_REFERENCE || ! preg_match('/^[A-Za-z0-9 ._\/#:-]+$/u', $value)) {
            throw new BusinessRuleException('reference_invalid', 'Use only letters, numbers and - . / in the reference. Never type a PIN or password.', 422);
        }

        return $value;
    }

    private function date($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new BusinessRuleException('date_invalid', 'Choose the date you paid.', 422);
        }
        if ($value > Carbon::now(Scheduling::TIMEZONE)->toDateString()) {
            throw new BusinessRuleException('date_future', 'The payment date can’t be in the future.', 422);
        }

        return $value;
    }
}
