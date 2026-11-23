<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\JobFinalPrice;
use App\Models\JobPaymentEvent;
use App\Models\JobPaymentReport;
use App\Models\JobPriceAgreement;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Concerns\LocksPricedRequests;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase B3: payments a provider REPORTS having received directly from the customer. WorkMate does not take or
 * process them. A report is a statement by the provider, and it counts as CONFIRMED only after the customer
 * explicitly confirms it: never through silence, time, or any background process.
 *
 *   provider reports ─► pending_confirmation ─► confirmed   (the customer confirms: final)
 *                                            ├─► disputed    (the customer says it is not right: stays disputed)
 *                                            └─► cancelled   (the provider withdrew it, with a reason)
 *   disputed ─► cancelled   (the provider withdraws it; a corrected report can point back to it)
 *
 * What a report says (amount, method, date, reference, note) never changes; a mistake is fixed by withdrawing it and
 * reporting a corrected one. A report the customer confirmed can't be withdrawn by the provider alone, and nobody here
 * can resolve a dispute on anyone's behalf: no authority for that has been approved yet.
 *
 * Several reports per job are allowed (part payments). Totals are always worked out here, in whole UGX, and a report
 * that would take the reported total above the final price is refused rather than silently accepted. Reporting a
 * payment does not complete a job, completing a job does not imply payment, and none of it creates a commission or debt.
 * Every action runs in one transaction with the request row locked; repeating one that already happened changes and
 * notifies nothing twice.
 */
class PaymentReports
{
    use LocksPricedRequests;

    public const MIN_AMOUNT = 100;
    public const MAX_AMOUNT = 100000000;
    public const MAX_NOTE = 300;
    public const MAX_REFERENCE = 60;

    /** Payments can be reported once the job is accepted, also after it is done; never for a cancelled job */
    private const REPORTABLE = [ServiceRequest::STATUS_SCHEDULED, ServiceRequest::STATUS_IN_PROGRESS, ServiceRequest::STATUS_COMPLETED];

    /** @var Pricing */
    private $pricing;

    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    /** @var FinalPrices */
    private $finalPrices;

    /** @var CommissionShadow */
    private $shadow;

    public function __construct(Pricing $pricing, AuditLogger $audit, EventNotifications $notify, FinalPrices $finalPrices, CommissionShadow $shadow)
    {
        $this->shadow = $shadow;
        $this->pricing = $pricing;
        $this->audit = $audit;
        $this->notify = $notify;
        $this->finalPrices = $finalPrices;
    }

    /* ---------------- The provider business ---------------- */

    /**
     * Reports a payment received directly from the customer. Only the business owner or a manager. A retry with the
     * same client_token returns the first report. `corrects` may name a report of this job that was withdrawn.
     *
     * @param  array{amount: int, method: string, paid_on: ?string, reference: ?string, note: ?string, client_token: ?string, corrects: ?int}  $data
     */
    public function report(User $user, int $requestId, array $data): JobPaymentReport
    {
        $this->guardEnabled();
        $amount = $this->wholeUgx($data['amount'] ?? null);
        $method = $data['method'] ?? null;
        if (! in_array($method, JobPaymentReport::METHODS, true)) {
            throw new BusinessRuleException('method_invalid', 'Choose how the customer paid.', 422);
        }
        $paidOn = $this->date($data['paid_on'] ?? null);
        $reference = $this->reference($data['reference'] ?? null);
        $note = $this->note($data['note'] ?? null);
        $token = isset($data['client_token']) && $data['client_token'] !== '' ? (string) $data['client_token'] : null;
        $corrects = isset($data['corrects']) && $data['corrects'] !== '' ? (int) $data['corrects'] : null;

        return DB::transaction(function () use ($user, $requestId, $amount, $method, $paidOn, $reference, $note, $token, $corrects) {
            $request = $this->lockForProvider($user, $requestId);

            // The same try again (a dropped connection): the first report, with nothing new recorded or sent
            if ($token) {
                $same = JobPaymentReport::where('service_request_id', $request->id)->where('client_token', $token)->first();
                if ($same) {
                    return $same;
                }
            }
            if (! in_array($request->status, self::REPORTABLE, true)) {
                throw new BusinessRuleException('request_status_conflict', 'A payment can be reported once the job is accepted, and not for a cancelled job.', 409);
            }
            $this->guardDate($request, $paidOn);
            if ($corrects !== null) {
                $old = JobPaymentReport::where('service_request_id', $request->id)->whereKey($corrects)->first();
                if (! $old || $old->status !== JobPaymentReport::CANCELLED) {
                    throw new BusinessRuleException('corrects_invalid', 'You can only correct a report you have withdrawn.', 422);
                }
            }

            // The agreed price, locked as the final price (nothing is invented when there is none)
            $final = $this->finalPrices->lockIfAgreed($request, $user);

            $counted = JobPaymentReport::where('service_request_id', $request->id)->whereIn('status', [JobPaymentReport::PENDING, JobPaymentReport::CONFIRMED, JobPaymentReport::DISPUTED])->get();
            $same = $counted->first(function (JobPaymentReport $r) use ($amount, $method, $paidOn, $reference) {
                return $r->status === JobPaymentReport::PENDING && (int) $r->amount_ugx === $amount && $r->method === $method
                    && optional($r->paid_on)->toDateString() === $paidOn && (string) $r->reference === (string) $reference;
            });
            if ($same) {
                throw new BusinessRuleException('duplicate_report', 'You already reported this payment. It is waiting for the customer.', 409);
            }
            if ($final) {
                $left = max((int) $final->amount_ugx - (int) $counted->sum('amount_ugx'), 0);
                if ($amount > $left) {
                    throw new BusinessRuleException('exceeds_final_price', 'That is more than what is left to pay (UGX '.number_format($left).'). If the price changed, agree the new price with the customer first.', 422);
                }
            }

            $report = new JobPaymentReport();
            $report->forceFill([
                'service_request_id' => $request->id,
                'provider_profile_id' => $request->provider_profile_id,
                'customer_id' => $request->customer_id,
                'reported_by_user_id' => $user->getKey(),
                'amount_ugx' => $amount,
                'method' => $method,
                'paid_on' => $paidOn,
                'reference' => $reference,
                'note' => $note,
                'status' => JobPaymentReport::PENDING,
                'agreement_id' => $final ? $final->agreement_id : null,
                'final_price_id' => $final ? $final->id : null,
                'corrects_id' => $corrects,
                'client_token' => $token,
            ])->save();

            $this->record($request, $report, 'reported', $user, 'provider', null, $report->status, $note);
            $this->audit->record('marketplace.payment_reported', $report, [], ['service_request_id' => $request->id, 'amount_ugx' => $amount, 'method' => $method, 'corrects' => $corrects], null, $user);
            $this->notify->paymentReported($report, $request, $user);

            return $report;
        });
    }

    /**
     * The provider withdraws a report the customer has not confirmed (waiting, or disputed), with a reason. A report the
     * customer confirmed is not the provider's to take back.
     */
    public function cancel(User $user, int $requestId, int $paymentId, ?string $reason): JobPaymentReport
    {
        $this->guardEnabled();
        $reason = $this->note($reason);

        return DB::transaction(function () use ($user, $requestId, $paymentId, $reason) {
            $request = $this->lockForProvider($user, $requestId);
            $report = $this->find($request, $paymentId);

            if ($report->status === JobPaymentReport::CANCELLED) {
                return $report;
            }
            if ($report->status === JobPaymentReport::CONFIRMED) {
                throw new BusinessRuleException('already_confirmed', 'The customer confirmed this payment, so it can’t be taken back here. Contact WorkMate if it is wrong.', 409);
            }
            if ($reason === null || mb_strlen($reason) < 3) {
                throw new BusinessRuleException('reason_required', 'Say why you are taking this report back.', 422);
            }

            $was = $report->status;
            $this->move($report, JobPaymentReport::CANCELLED, ['cancelled_by_user_id' => $user->getKey(), 'cancelled_at' => now(), 'cancel_reason' => $reason], 'cancelled', $user, 'provider', $reason);
            if ($was === JobPaymentReport::DISPUTED) {
                // Phase B5: taking a disputed report back ends its case
                app(PaymentDisputes::class)->reportWithdrawn($report, $user);
            }
            $this->notify->paymentCancelled($report, $request, $user, $was);

            return $report;
        });
    }

    /* ---------------- The customer ---------------- */

    /**
     * "Confirm payment": the customer says the payment happened. They send the amount they are looking at, which has to
     * be the report's. Only the customer of that request, and only a report still waiting for them.
     */
    public function confirm(User $customer, int $requestId, int $paymentId, $amount): JobPaymentReport
    {
        $this->guardEnabled();

        $report = DB::transaction(function () use ($customer, $requestId, $paymentId, $amount) {
            $request = $this->lockForCustomer($customer, $requestId);
            $report = $this->find($request, $paymentId);

            if ($report->status === JobPaymentReport::CONFIRMED && (int) $report->responded_by_user_id === (int) $customer->getKey()) {
                return $report;
            }
            $this->guardCanAnswer($report);
            if (! is_numeric($amount) || (int) $amount !== (int) $report->amount_ugx) {
                throw new BusinessRuleException('stale_report', 'The payment you are looking at is out of date. Please look at it again.', 409);
            }

            $this->move($report, JobPaymentReport::CONFIRMED, ['responded_by_user_id' => $customer->getKey(), 'responded_at' => now()], 'confirmed', $customer, 'customer', null);
            $this->notify->paymentConfirmed($report, $request, $customer);

            return $report;
        });

        // Shadow mode (off by default): the estimated commission of this confirmed payment. It runs after the confirmation is
        // saved and can never change or undo it; a problem there is only logged (a scheduled catch-up does it again).
        // Nobody is notified and nothing is owed.
        $this->shadow->processQuietly($report);

        return $report;
    }

    /** "It isn't right": the customer disputes the report. It stays disputed; nobody can resolve it for them here. */
    public function dispute(User $customer, int $requestId, int $paymentId, ?string $note): JobPaymentReport
    {
        $this->guardEnabled();
        $note = $this->note($note);

        return DB::transaction(function () use ($customer, $requestId, $paymentId, $note) {
            $request = $this->lockForCustomer($customer, $requestId);
            $report = $this->find($request, $paymentId);

            if ($report->status === JobPaymentReport::DISPUTED && (int) $report->responded_by_user_id === (int) $customer->getKey()) {
                return $report;
            }
            $this->guardCanAnswer($report);

            $this->move($report, JobPaymentReport::DISPUTED, ['responded_by_user_id' => $customer->getKey(), 'responded_at' => now(), 'response_note' => $note], 'disputed', $customer, 'customer', $note);
            // Phase B5: the dispute becomes a case both sides can talk in and either can escalate (one case per report)
            app(PaymentDisputes::class)->opened($report, $customer, $note);
            $this->notify->paymentDisputed($report, $request, $customer);

            return $report;
        });
    }

    /**
     * Phase B5: a DISPUTED report moves on because its dispute was settled (both sides agreed, or an administrator decided it).
     * Only from disputed, only to confirmed or cancelled, and written to the payment history and audit log like every other step.
     * Used by App\Services\PaymentDisputes inside its own transaction (the request and the report are already locked).
     */
    public function settleDisputed(JobPaymentReport $report, string $to, array $fields, string $event, ?User $actor, string $role, ?string $note): void
    {
        if ($report->status !== JobPaymentReport::DISPUTED || ! in_array($to, [JobPaymentReport::CONFIRMED, JobPaymentReport::CANCELLED], true)) {
            throw new BusinessRuleException('report_changed', 'The payment report is no longer in question.', 409);
        }
        $this->move($report, $to, $fields, $event, $actor, $role, $note);
    }

    /* ---------------- What the apps are shown ---------------- */

    /**
     * Totals for a set of reports against the final price, in whole UGX. Disputed and waiting reports are never counted
     * as confirmed; withdrawn ones count for nothing. `remaining` is what the confirmed payments have not yet covered
     * (null with no final price) and `over_by` says so plainly if confirmed payments exceed it.
     *
     * @param  Collection  $reports  JobPaymentReport models
     * @return array{reported: int, confirmed: int, pending: int, disputed: int, remaining: ?int, over_by: int}
     */
    public static function totals(Collection $reports, ?int $price): array
    {
        $sum = function ($status) use ($reports) {
            return (int) $reports->where('status', $status)->sum('amount_ugx');
        };
        $confirmed = $sum(JobPaymentReport::CONFIRMED);
        $pending = $sum(JobPaymentReport::PENDING);
        $disputed = $sum(JobPaymentReport::DISPUTED);

        return [
            'reported' => $confirmed + $pending + $disputed,
            'confirmed' => $confirmed,
            'pending' => $pending,
            'disputed' => $disputed,
            'remaining' => $price === null ? null : max($price - $confirmed, 0),
            'over_by' => $price === null ? 0 : max($confirmed - $price, 0),
        ];
    }

    /**
     * Where a request's payments stand, for both sides of it: the agreed price, the final price, every report with its
     * decision, and the totals. Null while pricing options are off. Uses the relations when they are already loaded.
     */
    public static function summary(ServiceRequest $r): ?array
    {
        if (! app(Pricing::class)->enabled()) {
            return null;
        }
        $reports = $r->relationLoaded('paymentReports') ? $r->paymentReports : $r->paymentReports()->get();
        $final = $r->relationLoaded('currentFinalPrice') ? $r->currentFinalPrice : $r->currentFinalPrice()->first();
        $agreed = $r->relationLoaded('agreedAgreement') ? $r->agreedAgreement : $r->agreedAgreement()->first();

        return [
            'agreed_amount' => $agreed ? $agreed->amount_ugx : null,
            'final_price' => FinalPrices::present($final),
            'totals' => self::totals($reports, $final ? (int) $final->amount_ugx : null),
            'reports' => PaymentDisputes::attach($reports->sortBy('id')->map(function (JobPaymentReport $p) {
                return self::present($p);
            })->values()->all()),
        ];
    }

    public static function present(JobPaymentReport $p): array
    {
        return [
            'id' => $p->id,
            'amount' => $p->amount_ugx,
            'method' => $p->method,
            'paid_on' => optional($p->paid_on)->toDateString(),
            'reference' => $p->reference,
            'note' => $p->note,
            'status' => $p->status,
            'reported_at' => $p->created_at->toIso8601String(),
            'responded_at' => optional($p->responded_at)->toIso8601String(),
            'response_note' => $p->response_note,
            'cancelled_at' => optional($p->cancelled_at)->toIso8601String(),
            'cancel_reason' => $p->cancel_reason,
            'corrects_id' => $p->corrects_id,
        ];
    }

    /** Everything about a request's money steps, oldest first: the final price versions, the reports and each step */
    public function history(ServiceRequest $request): array
    {
        $reports = JobPaymentReport::where('service_request_id', $request->id)->orderBy('id')->get();
        $final = JobFinalPrice::where('service_request_id', $request->id)->orderBy('revision_no')->get();
        $currentFinal = $final->firstWhere('status', JobFinalPrice::CURRENT);

        return [
            'agreed_amount' => optional(JobPriceAgreement::where('service_request_id', $request->id)->where('agreed_slot', 1)->first())->amount_ugx,
            'final_prices' => $final->map(function (JobFinalPrice $f) {
                return FinalPrices::present($f) + ['status' => $f->status];
            })->values()->all(),
            'totals' => self::totals($reports, $currentFinal ? (int) $currentFinal->amount_ugx : null),
            'reports' => PaymentDisputes::attach($reports->map(function (JobPaymentReport $p) {
                return self::present($p);
            })->values()->all()),
            'events' => JobPaymentEvent::where('service_request_id', $request->id)->orderBy('id')->get()->map(function (JobPaymentEvent $e) {
                return [
                    'event' => $e->event, 'by' => $e->actor_role, 'payment_id' => $e->payment_id, 'from' => $e->from_status, 'to' => $e->to_status,
                    'amount' => $e->amount_ugx, 'note' => $e->note, 'at' => $e->created_at->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }

    /* ---------------- Internals ---------------- */

    /** One of this request's reports, locked; one that belongs to another request is "not found" */
    private function find(ServiceRequest $request, int $paymentId): JobPaymentReport
    {
        $report = JobPaymentReport::where('service_request_id', $request->id)->whereKey($paymentId)->lockForUpdate()->first();
        if (! $report) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }

        return $report;
    }

    /** The customer may only answer a report still waiting for them (also after the job was cancelled or finished) */
    private function guardCanAnswer(JobPaymentReport $report): void
    {
        if ($report->status === JobPaymentReport::CANCELLED) {
            throw new BusinessRuleException('report_cancelled', 'The provider took this payment report back.', 409);
        }
        if ($report->status === JobPaymentReport::DISPUTED) {
            throw new BusinessRuleException('already_decided', 'You said this payment is not right. The provider can send a corrected report.', 409);
        }
        if ($report->status !== JobPaymentReport::PENDING) {
            throw new BusinessRuleException('already_decided', 'This payment has already been answered.', 409);
        }
    }

    private function move(JobPaymentReport $report, string $to, array $fields, string $event, ?User $actor, string $role, ?string $note): void
    {
        $from = $report->status;
        $report->forceFill($fields + ['status' => $to])->save();

        $request = $report->request;
        $this->record($request, $report, $event, $actor, $role, $from, $to, $note);
        $this->audit->record('marketplace.payment_'.$event, $report, ['status' => $from], ['status' => $to, 'amount_ugx' => $report->amount_ugx], $note, $actor);
    }

    private function record(ServiceRequest $request, JobPaymentReport $report, string $event, ?User $actor, string $role, ?string $from, ?string $to, ?string $note): void
    {
        $row = new JobPaymentEvent();
        $row->forceFill([
            'service_request_id' => $request->id,
            'payment_id' => $report->id,
            'event' => $event,
            'actor_id' => $actor ? $actor->getKey() : null,
            'actor_role' => $role,
            'from_status' => $from,
            'to_status' => $to,
            'amount_ugx' => $report->amount_ugx,
            'note' => $note ? mb_substr($note, 0, self::MAX_NOTE) : null,
            'created_at' => now(),
        ])->save();
    }

    /** Whole UGX only: digits, no fractions or signs, within the limits */
    private function wholeUgx($value): int
    {
        if ($value === null || is_bool($value) || is_float($value) || ! ctype_digit((string) $value)
            || (int) $value < self::MIN_AMOUNT || (int) $value > self::MAX_AMOUNT) {
            throw new BusinessRuleException('amount_invalid', 'Enter the amount in UGX, from '.number_format(self::MIN_AMOUNT).' to '.number_format(self::MAX_AMOUNT).'.', 422);
        }

        return (int) $value;
    }

    /** A date as "2026-11-20", or none */
    private function date($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new BusinessRuleException('date_invalid', 'Choose the date the customer paid.', 422);
        }

        return $value;
    }

    /** Not in the future, and not before the job was asked for (Uganda time) */
    private function guardDate(ServiceRequest $request, ?string $paidOn): void
    {
        if ($paidOn === null) {
            return;
        }
        $today = Carbon::now(Scheduling::TIMEZONE)->toDateString();
        $asked = $request->created_at->copy()->setTimezone(Scheduling::TIMEZONE)->toDateString();
        if ($paidOn > $today) {
            throw new BusinessRuleException('date_future', 'The payment date can’t be in the future.', 422);
        }
        if ($paidOn < $asked) {
            throw new BusinessRuleException('date_before_job', 'The payment date can’t be before the job was asked for.', 422);
        }
    }

    /** An optional short reference the provider chooses to give: letters, numbers and a few separators only */
    private function reference($value): ?string
    {
        $value = $this->text($value ?? '', self::MAX_REFERENCE + 1);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > self::MAX_REFERENCE || ! preg_match('/^[A-Za-z0-9 ._\/#:-]+$/u', $value)) {
            throw new BusinessRuleException('reference_invalid', 'Use only letters, numbers and - . / in the reference, up to '.self::MAX_REFERENCE.' characters. Never type a PIN or password.', 422);
        }

        return $value;
    }
}
