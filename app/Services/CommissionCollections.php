<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CommissionException;
use App\Models\CommissionPaymentReport;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\SimulatedGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Phase B10, commission collection through a payment gateway (mobile money), gateway-independent.
 *
 * A provider starts a payment (an ATTEMPT: pending, processing, successful, failed, expired or cancelled). An attempt is never a credit.
 * When the gateway confirms it with a signed callback, the attempt becomes exactly ONE verified payment report and goes through the same
 * CommissionSettlements::creditPayment() that an administrator's verification of a manual payment uses, so the ledger, the settlement
 * cycles, the receipt and every view agree. Nothing here writes a balance: only ledger lines created by creditPayment().
 *
 * Safe against repeats: a client token makes a retried request the same attempt; a repeated or concurrent callback is a no-op (one event
 * key per gateway reference and status, and the attempt is locked); a gateway reference can become a payment once. Money confirmed late
 * (after the request expired or was cancelled) or for another amount is never applied by itself: it opens an audited exception.
 * No gateway secret, PIN or full phone number is stored or returned.
 */
class CommissionCollections
{
    public const METHODS = ['mtn_momo' => 'MTN Mobile Money', 'airtel_money' => 'Airtel Money'];
    public const MAX_OPEN_ATTEMPTS = 3;
    public const UNAVAILABLE = 'Service currently not available.';

    /** @var CommissionSettlements */
    private $settlements;

    /** @var PlatformSettings */
    private $settings;

    /** @var GatewayRegistry */
    private $gateways;

    /** @var ProviderFinanceAccess */
    private $access;

    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    public function __construct(CommissionSettlements $settlements, PlatformSettings $settings, GatewayRegistry $gateways, ProviderFinanceAccess $access, AuditLogger $audit, EventNotifications $notify)
    {
        $this->settlements = $settlements;
        $this->settings = $settings;
        $this->gateways = $gateways;
        $this->access = $access;
        $this->audit = $audit;
        $this->notify = $notify;
    }

    /* ---------------- What the provider can do ---------------- */

    /** The ways to pay, each with whether it can be used now. Nothing about why one is unavailable is ever said. */
    public function methods(): array
    {
        $on = $this->settlements->enabled();
        $out = [];
        foreach (self::METHODS as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label, 'kind' => 'mobile_money', 'available' => $on && (bool) $this->gateways->forMethod($id)];
        }
        $out[] = ['id' => 'manual', 'label' => 'Manual payment with proof', 'kind' => 'manual', 'available' => $on && $this->settlements->instructions() !== null];

        return $out;
    }

    /** Everything the Pay Commission screen needs, from the server's own figures */
    public function overview(User $user, int $providerId): array
    {
        $payable = $this->settlements->payable($providerId);
        $reserved = $this->settlements->reserved($providerId);
        $canPay = $this->access->canPay($user, $providerId);
        $enabled = $this->settlements->enabled();

        return [
            'can_pay' => $canPay,
            // The button is shown only when something is owed, settlement is on and this person may pay
            'show_pay_button' => $enabled && $canPay && $payable > 0,
            'payable_ugx' => $payable,
            'reserved_ugx' => $reserved,
            'available_ugx' => max($payable - $reserved, 0),
            'methods' => $this->methods(),
            // True only while developing and testing locally, where a simulated phone approval exists; never in production
            'simulator' => $this->gateways->simulatorAllowed(),
            'instructions' => $this->settlements->instructions(),
            'open_attempts' => DB::table('commission_payment_attempts')->where('provider_profile_id', $providerId)->whereIn('status', ['pending', 'processing'])
                ->where('expires_at', '>', now())->count(),
        ];
    }

    /* ---------------- Starting a payment ---------------- */

    /**
     * @param  array{method: string, amount: mixed, phone: ?string, client_token: ?string}  $data
     */
    public function initiate(User $user, array $data): array
    {
        if (! $this->settlements->enabled()) {
            throw new BusinessRuleException('method_unavailable', self::UNAVAILABLE, 422);
        }
        $method = (string) ($data['method'] ?? '');
        $gateway = isset(self::METHODS[$method]) ? $this->gateways->forMethod($method) : null;
        if (! $gateway) {
            throw new BusinessRuleException('method_unavailable', self::UNAVAILABLE, 422);
        }
        $amount = $this->amount($data['amount'] ?? null);
        $msisdn = $this->msisdn($data['phone'] ?? null);
        $token = isset($data['client_token']) && $data['client_token'] !== '' ? mb_substr((string) $data['client_token'], 0, 64) : null;

        $ids = $this->settlements->providerIdsFor($user);
        $providerId = $ids ? (int) $ids[0] : 0;
        if (! $providerId) {
            throw new BusinessRuleException('payment_not_allowed', 'You can’t pay commission for this business. Ask the owner.', 403);
        }

        $attempt = DB::transaction(function () use ($user, $providerId, $amount, $method, $msisdn, $token, $gateway) {
            ProviderProfile::whereKey($providerId)->lockForUpdate()->first();
            if (! $this->access->canPay($user, $providerId)) {
                throw new BusinessRuleException('payment_not_allowed', 'You can’t pay commission for this business. Ask the owner.', 403);
            }
            if ($token) {
                $same = DB::table('commission_payment_attempts')->where('provider_profile_id', $providerId)->where('client_token', $token)->first();
                if ($same) {
                    return (int) $same->id;
                }
            }
            $payable = $this->settlements->payable($providerId);
            if ($payable <= 0) {
                throw new BusinessRuleException('nothing_owed', 'No outstanding commission.', 409);
            }
            $room = $payable - $this->settlements->reserved($providerId);
            if ($amount > $room) {
                throw new BusinessRuleException('amount_too_high', $room > 0 ? 'You can pay up to UGX '.number_format($room).' now.' : 'A payment for your outstanding commission is already in progress.', 422);
            }
            $open = DB::table('commission_payment_attempts')->where('provider_profile_id', $providerId)->whereIn('status', ['pending', 'processing'])->where('expires_at', '>', now())->count();
            if ($open >= self::MAX_OPEN_ATTEMPTS) {
                throw new BusinessRuleException('too_many_pending', 'You already have payments waiting for approval. Approve or cancel them first.', 429);
            }
            $id = DB::table('commission_payment_attempts')->insertGetId([
                'provider_profile_id' => $providerId, 'initiated_by_user_id' => $user->getKey(), 'amount_ugx' => $amount, 'method' => $method,
                'phone_masked' => $this->mask($msisdn), 'reference' => 'WMC-'.strtoupper(bin2hex(random_bytes(5))), 'gateway' => $gateway->key(),
                'status' => 'pending', 'client_token' => $token, 'expires_at' => now()->addMinutes((int) config('workmate.payments.attempt_minutes', 15)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($id, 'created', null, null);
            $this->audit->record('commission.payment_attempt_started', null, [], ['attempt_id' => $id, 'method' => $method, 'amount_ugx' => $amount, 'provider_profile_id' => $providerId], null, $user);

            return $id;
        }, 3);

        $row = DB::table('commission_payment_attempts')->where('id', $attempt)->first();
        if ($row->status === 'pending' && $row->gateway_reference === null) {
            // The gateway is asked outside the transaction: a slow gateway never holds a database lock
            try {
                $sent = $gateway->initiate(['reference' => $row->reference, 'amount_ugx' => (int) $row->amount_ugx, 'method' => $method, 'msisdn' => $msisdn]);
                DB::table('commission_payment_attempts')->where('id', $attempt)->where('status', 'pending')->update([
                    'gateway_reference' => $sent['gateway_reference'], 'status' => 'processing', 'updated_at' => now(),
                ]);
                $this->event($attempt, 'sent', null, null);
                $this->notify->settlement('payment_started', (int) $row->provider_profile_id, null, null);
            } catch (Throwable $e) {
                DB::table('commission_payment_attempts')->where('id', $attempt)->update(['status' => 'failed', 'failure_code' => 'gateway_unreachable', 'completed_at' => now(), 'updated_at' => now()]);
                $this->event($attempt, 'failed', 'gateway_unreachable', null);
                throw new BusinessRuleException('method_unavailable', self::UNAVAILABLE, 422);
            }
        }

        return $this->present($this->attemptFor($user, $attempt));
    }

    /** The provider changes their mind before approving. Only an attempt still waiting; a later confirmation becomes an exception. */
    public function cancel(User $user, int $id): array
    {
        $row = $this->attemptFor($user, $id);
        DB::transaction(function () use ($row) {
            $locked = DB::table('commission_payment_attempts')->where('id', $row->id)->lockForUpdate()->first();
            if (in_array($locked->status, ['pending', 'processing'], true)) {
                DB::table('commission_payment_attempts')->where('id', $row->id)->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
                $this->event((int) $row->id, 'cancelled', null, null);
            }
        });

        return $this->present($this->attemptFor($user, $id));
    }

    public function show(User $user, int $id): array
    {
        return $this->present($this->attemptFor($user, $id));
    }

    /* ---------------- The gateway's confirmation ---------------- */

    /**
     * An authenticated callback from the gateway. Repeating it, or two arriving together, changes nothing the second time.
     *
     * @return array<string, mixed>
     */
    public function callback(string $gatewayKey, string $body, ?string $signature): array
    {
        $gateway = $this->gateways->byKey($gatewayKey);
        if (! $gateway) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        try {
            $event = $gateway->verifyCallback($body, $signature);
        } catch (InvalidArgumentException $e) {
            $this->audit->record('commission.gateway_callback_refused', null, [], ['gateway' => $gatewayKey], 'The callback could not be verified', null);
            throw new BusinessRuleException('invalid_signature', 'The request could not be verified.', 401);
        }

        return DB::transaction(function () use ($event, $gatewayKey) {
            $attempt = DB::table('commission_payment_attempts')->where('reference', $event['reference'])->where('gateway', $gatewayKey)->lockForUpdate()->first();
            if (! $attempt) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            if ($attempt->gateway_reference !== null && $attempt->gateway_reference !== $event['gateway_reference']) {
                $this->audit->record('commission.gateway_callback_refused', null, [], ['attempt_id' => (int) $attempt->id], 'The gateway reference did not match', null);
                throw new BusinessRuleException('invalid_signature', 'The request could not be verified.', 409);
            }
            $recorded = DB::table('commission_payment_attempt_events')->insertOrIgnore([
                'attempt_id' => $attempt->id, 'event' => 'callback_'.$event['status'], 'detail' => null,
                'event_key' => 'cb:'.$event['gateway_reference'].':'.$event['status'], 'created_at' => now(),
            ]);
            if (! $recorded) {
                return ['status' => $attempt->status, 'repeated' => true];
            }
            if ($attempt->gateway_reference === null) {
                DB::table('commission_payment_attempts')->where('id', $attempt->id)->update(['gateway_reference' => $event['gateway_reference']]);
            }
            $providerId = (int) $attempt->provider_profile_id;

            if ($event['status'] !== 'successful') {
                if (in_array($attempt->status, ['pending', 'processing'], true)) {
                    $status = $event['status'] === 'cancelled' ? 'cancelled' : 'failed';
                    DB::table('commission_payment_attempts')->where('id', $attempt->id)->update([
                        'status' => $status, 'failure_code' => $status === 'failed' ? 'declined' : null, 'completed_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->event((int) $attempt->id, $status, null, null);
                    $this->notify->settlement('payment_failed', $providerId, null, null);
                }

                return ['status' => $event['status'] === 'cancelled' ? 'cancelled' : 'failed', 'repeated' => false];
            }

            // Money the gateway says it received
            ProviderProfile::withTrashed()->whereKey($providerId)->lockForUpdate()->first();
            if ($attempt->status === 'successful') {
                return ['status' => 'successful', 'repeated' => true];
            }
            if (! in_array($attempt->status, ['pending', 'processing'], true)) {
                $this->settlements->exception(CommissionException::LATE_PAYMENT, $providerId, null, null, (int) $event['amount_ugx'],
                    'The gateway confirmed money for a request that had already '.($attempt->status === 'expired' ? 'expired' : 'ended').'. Not applied until reviewed.');
                $this->event((int) $attempt->id, 'late_confirmation', null, null);

                return ['status' => $attempt->status, 'repeated' => false, 'exception' => true];
            }
            if ((int) $event['amount_ugx'] !== (int) $attempt->amount_ugx) {
                DB::table('commission_payment_attempts')->where('id', $attempt->id)->update(['status' => 'failed', 'failure_code' => 'amount_mismatch', 'completed_at' => now(), 'updated_at' => now()]);
                $this->settlements->exception(CommissionException::GATEWAY_MISMATCH, $providerId, null, null, (int) $event['amount_ugx'],
                    'The gateway confirmed a different amount than was requested. Not applied until reviewed.');
                $this->event((int) $attempt->id, 'mismatch', null, null);

                return ['status' => 'failed', 'repeated' => false, 'exception' => true];
            }

            $anchor = (int) ($this->settlements->payableCycleIds($providerId)[0] ?? 0);
            if ($anchor <= 0) {
                $anchor = (int) DB::table('commission_settlement_cycles')->where('provider_profile_id', $providerId)->orderByDesc('id')->value('id');
            }
            $report = new CommissionPaymentReport();
            $report->forceFill([
                'provider_profile_id' => $providerId, 'cycle_id' => $anchor, 'reported_by_user_id' => $attempt->initiated_by_user_id, 'amount_ugx' => $attempt->amount_ugx,
                'channel' => $attempt->method, 'source' => 'gateway', 'attempt_id' => $attempt->id, 'reference' => $event['gateway_reference'],
                'active_reference' => $attempt->method.':'.mb_strtolower($event['gateway_reference']), 'paid_on' => Carbon::now(Scheduling::TIMEZONE)->toDateString(),
                'status' => CommissionPaymentReport::PENDING,
            ])->save();
            $this->settlements->creditPayment($report, (int) $attempt->amount_ugx, null, null);
            DB::table('commission_payment_attempts')->where('id', $attempt->id)->update([
                'status' => 'successful', 'payment_report_id' => $report->id, 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $this->event((int) $attempt->id, 'successful', null, null);

            return ['status' => 'successful', 'repeated' => false, 'payment_id' => (int) $report->id];
        }, 3);
    }

    /** Requests nobody approved in time. Each is expired once; repeated runs change nothing. */
    public function expireDue(): int
    {
        $n = 0;
        foreach (DB::table('commission_payment_attempts')->whereIn('status', ['pending', 'processing'])->where('expires_at', '<=', now())->orderBy('id')->limit(200)->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$n) {
                $row = DB::table('commission_payment_attempts')->where('id', $id)->lockForUpdate()->first();
                if ($row && in_array($row->status, ['pending', 'processing'], true) && Carbon::parse($row->expires_at)->lte(now())) {
                    DB::table('commission_payment_attempts')->where('id', $id)->update(['status' => 'expired', 'completed_at' => now(), 'updated_at' => now()]);
                    $this->event((int) $id, 'expired', null, null);
                    $this->notify->settlement('payment_failed', (int) $row->provider_profile_id, null, null);
                    $n++;
                }
            });
        }

        return $n;
    }

    /** Development and tests only: what the phone's approval or refusal would send the gateway */
    public function simulate(User $user, int $id, string $outcome): array
    {
        if (! $this->gateways->simulatorAllowed() || ! in_array($outcome, ['successful', 'failed', 'cancelled'], true)) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        $row = $this->attemptFor($user, $id);
        if ($row->gateway !== 'simulator' || $row->gateway_reference === null) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        [$body, $signature] = (new SimulatedGateway())->callbackFor($row->reference, $row->gateway_reference, (int) $row->amount_ugx, $outcome);

        return $this->callback('simulator', $body, $signature);
    }

    /* ---------------- Reading ---------------- */

    public function present($row): array
    {
        $words = [
            'pending' => 'Payment request sent', 'processing' => 'Payment request sent', 'successful' => 'Payment successful',
            'failed' => 'Payment failed', 'expired' => 'Payment expired', 'cancelled' => 'Payment cancelled',
        ];

        return [
            'id' => (int) $row->id, 'reference' => $row->reference, 'method' => $row->method, 'method_label' => self::METHODS[$row->method] ?? $row->method,
            'amount_ugx' => (int) $row->amount_ugx, 'phone' => $row->phone_masked, 'status' => $row->status, 'message' => $words[$row->status] ?? $row->status,
            'expires_at' => Carbon::parse($row->expires_at)->toIso8601String(), 'completed_at' => $row->completed_at ? Carbon::parse($row->completed_at)->toIso8601String() : null,
            'payment_id' => $row->payment_report_id ? (int) $row->payment_report_id : null, 'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }

    /* ---------------- Receipts ---------------- */

    /** The receipt of one verified payment of this person's business, exactly as recorded: what was paid, where it went, what was left after it */
    public function receipt(User $user, int $reportId): array
    {
        $ids = $this->settlements->providerIdsFor($user);
        $r = $ids ? CommissionPaymentReport::where('id', $reportId)->whereIn('provider_profile_id', $ids)->first() : null;
        if (! $r || $r->status !== CommissionPaymentReport::VERIFIED || ! $r->receipt_number) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        $lines = DB::table('commission_ledger_entries')->where('commission_payment_id', $r->id)->orderBy('id')->get(['id', 'cycle_id', 'amount_ugx']);
        $lastId = (int) $lines->max('id');
        $after = $lastId ? (int) DB::table('commission_ledger_entries')->where('provider_profile_id', $r->provider_profile_id)->where('id', '<=', $lastId)->sum('amount_ugx') : null;
        $profile = ProviderProfile::withTrashed()->find($r->provider_profile_id);

        return [
            'receipt_number' => $r->receipt_number, 'payment_id' => (int) $r->id, 'business' => $profile ? $profile->display_name : null,
            'received_ugx' => (int) $r->verified_amount_ugx, 'applied_ugx' => (int) $r->credited_ugx, 'not_applied_ugx' => (int) $r->excess_ugx,
            'method' => $r->channel, 'method_label' => self::METHODS[$r->channel] ?? ($r->channel === 'manual' ? 'Manual payment' : ucwords(str_replace('_', ' ', $r->channel))),
            'reference' => $r->reference, 'paid_on' => optional($r->paid_on)->toDateString(), 'verified_at' => optional($r->decided_at)->toIso8601String(),
            'settlements' => $lines->map(function ($l) {
                return ['cycle_id' => (int) $l->cycle_id, 'amount_ugx' => -(int) $l->amount_ugx];
            })->all(),
            'balance_after_ugx' => $after === null ? null : max($after, 0),
        ];
    }

    public function receiptPdf(User $user, int $reportId)
    {
        $d = $this->receipt($user, $reportId);
        $pdf = new \App\Support\SimplePdf('WorkMate receipt');
        $pdf->heading('Payment receipt', 18);
        $pdf->line($d['receipt_number'], 12, true);
        $pdf->gap(6);
        $pdf->pair('Business', (string) $d['business']);
        $pdf->pair('Payment received', 'UGX '.number_format($d['received_ugx']), true);
        $pdf->pair('Applied to commission', 'UGX '.number_format($d['applied_ugx']));
        if ($d['not_applied_ugx'] > 0) {
            $pdf->pair('Held for review (not applied)', 'UGX '.number_format($d['not_applied_ugx']));
        }
        $pdf->pair('Method', $d['method_label']);
        $pdf->pair('Reference', (string) ($d['reference'] ?: '-'));
        $pdf->pair('Date paid', (string) ($d['paid_on'] ?: '-'));
        $pdf->pair('Verified', $d['verified_at'] ? Carbon::parse($d['verified_at'])->setTimezone(Scheduling::TIMEZONE)->format('j M Y H:i') : '-');
        foreach ($d['settlements'] as $l) {
            $pdf->pair('Settlement #'.$l['cycle_id'], 'UGX '.number_format($l['amount_ugx']));
        }
        $pdf->gap(6);
        $pdf->pair('Commission still owed after this payment', $d['balance_after_ugx'] === null ? '-' : 'UGX '.number_format($d['balance_after_ugx']), true);
        $pdf->gap(8);
        $pdf->line('Whole Ugandan shillings (UGX). Kampala time.', 9);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$d['receipt_number'].'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** One of this person's business's attempts, or "not found" (never another business's) */
    private function attemptFor(User $user, int $id)
    {
        $ids = $this->settlements->providerIdsFor($user);
        $row = $ids ? DB::table('commission_payment_attempts')->where('id', $id)->whereIn('provider_profile_id', $ids)->first() : null;
        if (! $row) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }

        return $row;
    }

    private function event(int $attemptId, string $event, ?string $detail, ?string $key): void
    {
        DB::table('commission_payment_attempt_events')->insert(['attempt_id' => $attemptId, 'event' => $event, 'detail' => $detail, 'event_key' => $key, 'created_at' => now()]);
    }

    private function amount($value): int
    {
        if ($value === null || is_bool($value) || is_float($value) || ! ctype_digit((string) $value) || (int) $value < CommissionSettlements::MIN_AMOUNT || (int) $value > CommissionSettlements::MAX_AMOUNT) {
            throw new BusinessRuleException('amount_invalid', 'Enter the amount in UGX, from '.number_format(CommissionSettlements::MIN_AMOUNT).' to '.number_format(CommissionSettlements::MAX_AMOUNT).'.', 422);
        }

        return (int) $value;
    }

    /** A Ugandan mobile number as 2567XXXXXXXX; anything else is refused */
    private function msisdn($value): string
    {
        $digits = preg_replace('/[\s\-()]/', '', (string) $value);
        if (preg_match('/^(?:\+?256|0)(7\d{8})$/', $digits, $m)) {
            return '256'.$m[1];
        }
        throw new BusinessRuleException('phone_invalid', 'Enter a valid mobile money number, like 0772 123 456.', 422);
    }

    private function mask(string $msisdn): string
    {
        return '0'.substr($msisdn, 3, 3).' *** '.substr($msisdn, -3);
    }
}
