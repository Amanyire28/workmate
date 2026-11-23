<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\JobPaymentReport;
use App\Models\PaymentDispute;
use App\Models\PaymentDisputeEvent;
use App\Models\PaymentDisputeEvidence;
use App\Models\PaymentDisputeMessage;
use App\Models\ProviderMember;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Concerns\LocksPricedRequests;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase B5: what happens after a customer says a payment report is not right.
 *
 *   open ─► discussion ─► escalated ─► under_review ─► resolved
 *     └─────────┴────────────┴───────────────┴──────────► resolved (the two sides agree; or the provider withdraws the report)
 *
 * The case is OPENED by the existing dispute action (PaymentReports::dispute): there is one case per disputed report, and the
 * report's own status stays the one record of whether a payment is confirmed. This class never creates a second status.
 *
 * THE TWO SIDES (the customer, and the provider business's owner or manager, who already speak for it on money matters) can
 * write to each other, attach evidence, and escalate to WorkMate (either one, alone, with a reason). They can only settle it
 * between themselves by BOTH agreeing to the same outcome: one proposes, the other accepts exactly that. Nobody can confirm a
 * payment or close the case for the other.
 *
 * THE ADMINISTRATOR (disputes.review to take a case and ask questions, disputes.resolve to decide) records a written, attributed
 * decision. What a decision does to the payment is deliberately small and honest:
 *   not_established  the report is withdrawn (disputed -> cancelled), like any withdrawal, attributed to the administrator;
 *   received         nothing is rewritten: an administrator can NOT stand in for the customer's confirmation, so the decision is
 *                    recorded and flagged "financial correction required" (the report stays as the customer left it);
 *   partial          the same, with the amount WorkMate found established (the provider can withdraw and report the corrected amount);
 *   insufficient_evidence  closed with no finding about payment.
 * Nothing here collects money, creates a commission, debt, invoice or balance, or changes a shadow estimate by itself: only a
 * confirmation both sides agreed to is passed on to the existing estimate step, which stays in shadow mode.
 *
 * Every change runs in one transaction with the request locked first, then the payment, then the case (the same order as the
 * rest of the money steps). A repeat that already happened changes and notifies nothing twice. The case's timeline, messages and
 * evidence are append-only.
 */
class PaymentDisputes
{
    use LocksPricedRequests;

    public const MAX_NOTE = 500;
    public const MAX_MESSAGE = 1000;

    /** @var Pricing */
    private $pricing;

    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    /** @var PaymentReports */
    private $payments;

    /** @var PrivateFiles */
    private $files;

    /** @var CommissionShadow */
    private $shadow;

    public function __construct(Pricing $pricing, AuditLogger $audit, EventNotifications $notify, PaymentReports $payments, PrivateFiles $files, CommissionShadow $shadow)
    {
        $this->pricing = $pricing;
        $this->audit = $audit;
        $this->notify = $notify;
        $this->payments = $payments;
        $this->files = $files;
        $this->shadow = $shadow;
    }

    /* ---------------- Hooks the payment steps call ---------------- */

    /** The customer disputed a report: the case opens with it (inside the same transaction). One case per report. */
    public function opened(JobPaymentReport $report, User $customer, ?string $note): PaymentDispute
    {
        $existing = PaymentDispute::where('payment_report_id', $report->id)->first();
        if ($existing) {
            return $existing;
        }

        $dispute = new PaymentDispute();
        $dispute->forceFill([
            'payment_report_id' => $report->id,
            'service_request_id' => $report->service_request_id,
            'provider_profile_id' => $report->provider_profile_id,
            'customer_id' => $report->customer_id,
            'status' => PaymentDispute::OPEN,
            'opened_by_user_id' => $customer->getKey(),
            'opened_at' => now(),
            'last_activity_at' => now(),
        ])->save();
        $this->event($dispute, 'opened', $customer, 'customer', null, PaymentDispute::OPEN, $note);

        return $dispute;
    }

    /** The provider took a disputed report back (the existing withdrawal): the case ends with it, with no decision to make */
    public function reportWithdrawn(JobPaymentReport $report, User $provider): void
    {
        $dispute = PaymentDispute::where('payment_report_id', $report->id)->lockForUpdate()->first();
        if (! $dispute) {
            return;
        }
        if ($dispute->isResolved()) {
            // The case is closed and its decision stands exactly as recorded. If that decision left the report alone, taking the
            // report back (the existing way to report the right amount) is written into the case's history and the audit log,
            // so it can never look like the decision was undone in silence.
            if (in_array($dispute->resolution_type, [PaymentDispute::DECIDE_RECEIVED, PaymentDispute::DECIDE_PARTIAL, PaymentDispute::DECIDE_INSUFFICIENT], true)) {
                $this->event($dispute, 'report_withdrawn', $provider, 'provider', null, null, 'After the WorkMate decision (' . $dispute->resolution_type . '), the provider took the payment report back.');
                $this->audit->record('payment_dispute.report_withdrawn_after_decision', $dispute, [], ['decision' => $dispute->resolution_type], null, $provider);
            }

            return;
        }
        $from = $dispute->status;
        $dispute->forceFill([
            'status' => PaymentDispute::RESOLVED, 'resolved_at' => now(), 'resolved_by_user_id' => $provider->getKey(),
            'resolution_type' => PaymentDispute::BY_PROVIDER_WITHDRAWAL, 'resolution_effect' => 'withdrawn', 'last_activity_at' => now(),
            'proposal_outcome' => null, 'proposal_by_user_id' => null, 'proposal_by_role' => null, 'proposal_at' => null,
        ])->save();
        $this->event($dispute, 'resolved', $provider, 'provider', $from, PaymentDispute::RESOLVED, 'The provider took the payment report back.');
        $this->audit->record('payment_dispute.resolved', $dispute, ['status' => $from], ['status' => 'resolved', 'type' => PaymentDispute::BY_PROVIDER_WITHDRAWAL], null, $provider);
    }

    /**
     * Disputes made before this feature existed have no case yet: they get one, dated from the customer's answer, still "open"
     * (never escalated, never announced). Safe to run any number of times: a report that has a case is skipped, and the database
     * allows one case per report. Bounded: at most 10 batches of 200 a run (the scheduled command repeats until none are left).
     */
    public function backfill(): int
    {
        $made = 0;
        for ($batch = 0; $batch < 10; $batch++) {
            $missing = JobPaymentReport::query()->leftJoin('payment_disputes as pd', 'pd.payment_report_id', '=', 'job_payment_reports.id')
                ->where('job_payment_reports.status', JobPaymentReport::DISPUTED)->whereNull('pd.id')
                ->orderBy('job_payment_reports.id')->limit(200)->get(['job_payment_reports.*']);
            if ($missing->isEmpty()) {
                break;
            }
            $made += $this->backfillBatch($missing);
        }

        return $made;
    }

    private function backfillBatch($missing): int
    {
        $made = 0;
        foreach ($missing as $report) {
            try {
                DB::transaction(function () use ($report, &$made) {
                    if (PaymentDispute::where('payment_report_id', $report->id)->exists()) {
                        return;
                    }
                    $dispute = new PaymentDispute();
                    $dispute->forceFill([
                        'payment_report_id' => $report->id, 'service_request_id' => $report->service_request_id,
                        'provider_profile_id' => $report->provider_profile_id, 'customer_id' => $report->customer_id,
                        'status' => PaymentDispute::OPEN, 'opened_by_user_id' => $report->responded_by_user_id,
                        'opened_at' => $report->responded_at ?: now(), 'last_activity_at' => now(),
                    ])->save();
                    $this->event($dispute, 'opened', null, 'system', null, PaymentDispute::OPEN, 'Case created for a payment the customer had already questioned.');
                    $made++;
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // another request created it first: that is the answer
            }
        }

        return $made;
    }

    /* ---------------- The two sides ---------------- */

    /** One case, for its customer or its provider business only. Anyone else gets "not found". */
    public function show(User $user, int $id): array
    {
        $this->guardEnabled();
        $dispute = PaymentDispute::find($id);
        $role = $dispute ? $this->roleOf($user, $dispute) : null;
        if (! $role) {
            throw $this->notFound();
        }

        return $this->present($dispute, $role, $user);
    }

    /** Writes in the case: text, evidence files, or both. A retry with the same client_token returns the first message. */
    public function post(User $user, int $id, ?string $body, array $files, ?string $token): array
    {
        $this->guardEnabled();
        $body = $this->text($body ?? '', self::MAX_MESSAGE);
        $files = array_values(array_filter($files));
        if ($body === '' && ! $files) {
            throw new BusinessRuleException('message_empty', 'Write something or attach a photo or PDF.', 422);
        }
        if (count($files) > PaymentDisputeEvidence::MAX_PER_MESSAGE) {
            throw new BusinessRuleException('too_many_files', 'Attach up to '.PaymentDisputeEvidence::MAX_PER_MESSAGE.' files at a time.', 422);
        }
        $token = $token !== null && $token !== '' ? mb_substr($token, 0, 64) : null;

        $stored = [];
        try {
            $result = $this->acting($user, $id, function (PaymentDispute $d, string $role, ServiceRequest $request) use ($user, $body, $files, $token, &$stored) {
                if ($token) {
                    $same = PaymentDisputeMessage::where('payment_dispute_id', $d->id)->where('client_token', $token)->first();
                    if ($same) {
                        return [$d, $role];
                    }
                }
                $this->guardOpen($d);
                if ($files && PaymentDisputeEvidence::where('payment_dispute_id', $d->id)->count() + count($files) > PaymentDisputeEvidence::MAX_PER_DISPUTE) {
                    throw new BusinessRuleException('too_much_evidence', 'This case already has the most files it can hold ('.PaymentDisputeEvidence::MAX_PER_DISPUTE.').', 422);
                }

                $message = new PaymentDisputeMessage();
                $message->forceFill([
                    'payment_dispute_id' => $d->id, 'author_id' => $user->getKey(), 'author_role' => $role, 'kind' => PaymentDisputeMessage::KIND_MESSAGE,
                    'body' => $body !== '' ? $body : null, 'client_token' => $token, 'created_at' => now(),
                ])->save();
                foreach ($files as $file) {
                    $this->storeEvidence($d, $message, $user, $role, $file, $stored);
                }

                $from = $d->status;
                $fields = ['last_activity_at' => now()];
                if ($d->status === PaymentDispute::OPEN) {
                    $fields['status'] = PaymentDispute::DISCUSSION;
                }
                $d->forceFill($fields)->save();
                if ($from !== $d->status) {
                    $this->event($d, 'discussion_started', $user, $role, $from, $d->status, null);
                }
                $this->event($d, 'message', $user, $role, null, null, $files ? count($files).' file(s) attached' : null);
                $this->notify->disputeActivity('message', $d, $request, $user, $role);

                return [$d, $role];
            });
        } catch (\Throwable $e) {
            // Nothing refers to files saved for a message that did not save
            $this->files->discard($stored);
            throw $e;
        }

        return $this->present($result[0], $result[1], $user);
    }

    /** Either side asks WorkMate to look at it. Alone, with a reason; a repeat changes nothing and notifies nobody. */
    public function escalate(User $user, int $id, ?string $reason, ?string $note): array
    {
        $this->guardEnabled();
        if (! in_array($reason, PaymentDispute::ESCALATION_REASONS, true)) {
            throw new BusinessRuleException('reason_required', 'Choose why you are asking WorkMate to step in.', 422);
        }
        $note = $this->text($note ?? '', self::MAX_NOTE);
        if (mb_strlen($note) < 5) {
            throw new BusinessRuleException('note_required', 'Say in a few words why you are asking WorkMate to step in.', 422);
        }

        $result = $this->acting($user, $id, function (PaymentDispute $d, string $role, ServiceRequest $request) use ($user, $reason, $note) {
            if (in_array($d->status, [PaymentDispute::ESCALATED, PaymentDispute::UNDER_REVIEW], true)) {
                return [$d, $role];
            }
            $this->guardOpen($d);
            $from = $d->status;
            $d->forceFill([
                'status' => PaymentDispute::ESCALATED, 'escalated_at' => now(), 'escalated_by_user_id' => $user->getKey(), 'escalated_by_role' => $role,
                'escalation_reason' => $reason, 'escalation_note' => $note, 'last_activity_at' => now(),
            ])->save();
            $this->event($d, 'escalated', $user, $role, $from, PaymentDispute::ESCALATED, $reason.': '.$note);
            $this->audit->record('payment_dispute.escalated', $d, ['status' => $from], ['status' => 'escalated', 'reason' => $reason], $note, $user);
            $this->notify->disputeActivity('escalated', $d, $request, $user, $role);
            // The people who review disputes hear about it too (first escalation only: a repeat returned above)
            $this->notify->disputeEscalatedToAdmins($d, $request, $user);

            return [$d, $role];
        });

        return $this->present($result[0], $result[1], $user);
    }

    /** One side suggests an outcome. It only takes effect when the OTHER side accepts exactly that. */
    public function propose(User $user, int $id, ?string $outcome): array
    {
        $this->guardEnabled();
        if (! in_array($outcome, PaymentDispute::AGREEMENTS, true)) {
            throw new BusinessRuleException('outcome_invalid', 'Choose what you suggest.', 422);
        }

        $result = $this->acting($user, $id, function (PaymentDispute $d, string $role, ServiceRequest $request) use ($user, $outcome) {
            $this->guardOpen($d);
            if ($d->proposal_outcome === $outcome && $d->proposal_by_role === $role) {
                return [$d, $role];
            }
            $d->forceFill([
                'proposal_outcome' => $outcome, 'proposal_by_user_id' => $user->getKey(), 'proposal_by_role' => $role, 'proposal_at' => now(), 'last_activity_at' => now(),
            ])->save();
            $this->event($d, 'proposed', $user, $role, null, null, $outcome);
            $this->notify->disputeActivity('proposed', $d, $request, $user, $role);

            return [$d, $role];
        });

        return $this->present($result[0], $result[1], $user);
    }

    /** The other side agrees to what was suggested. Both sides have then explicitly agreed, and the outcome is applied. */
    public function accept(User $user, int $id, ?string $outcome): array
    {
        $this->guardEnabled();
        $confirmed = null;

        $result = $this->acting($user, $id, function (PaymentDispute $d, string $role, ServiceRequest $request, JobPaymentReport $report) use ($user, $outcome, &$confirmed) {
            if ($d->isResolved() && in_array($d->resolution_type, [PaymentDispute::BY_AGREEMENT_RECEIVED, PaymentDispute::BY_AGREEMENT_NOT_RECEIVED], true)
                && $this->agreementType($outcome) === $d->resolution_type) {
                return [$d, $role];
            }
            $this->guardOpen($d);
            if (! $d->proposal_outcome) {
                throw new BusinessRuleException('no_proposal', 'There is nothing to agree to right now.', 409);
            }
            if ($d->proposal_by_role === $role) {
                throw new BusinessRuleException('own_proposal', 'The other side needs to agree to your suggestion.', 409);
            }
            if ($outcome !== $d->proposal_outcome) {
                throw new BusinessRuleException('stale_proposal', 'The suggestion changed. Please look at it again.', 409);
            }
            if ($report->status !== JobPaymentReport::DISPUTED) {
                throw new BusinessRuleException('report_changed', 'The payment report is no longer in question.', 409);
            }

            if ($outcome === PaymentDispute::AGREE_RECEIVED) {
                // The customer is one of the two who agreed: this IS their explicit confirmation, so it is recorded as one
                $this->payments->settleDisputed($report, JobPaymentReport::CONFIRMED,
                    ['responded_by_user_id' => $report->customer_id, 'responded_at' => now()], 'confirmed', $this->userOrNull($report->customer_id), 'customer',
                    'Both sides agreed the payment was received (dispute #'.$d->id.').');
                $confirmed = $report;
                $type = PaymentDispute::BY_AGREEMENT_RECEIVED;
                $effect = 'confirmed';
            } else {
                $providerUser = $role === 'provider' ? $user : $this->userOrNull($d->proposal_by_user_id);
                $this->payments->settleDisputed($report, JobPaymentReport::CANCELLED,
                    ['cancelled_by_user_id' => $providerUser ? $providerUser->getKey() : $user->getKey(), 'cancelled_at' => now(),
                        'cancel_reason' => 'Both sides agreed the payment was not received (dispute #'.$d->id.').'], 'cancelled', $providerUser, 'provider',
                    'Both sides agreed the payment was not received (dispute #'.$d->id.').');
                $type = PaymentDispute::BY_AGREEMENT_NOT_RECEIVED;
                $effect = 'withdrawn';
            }

            $from = $d->status;
            $d->forceFill([
                'status' => PaymentDispute::RESOLVED, 'resolved_at' => now(), 'resolved_by_user_id' => $user->getKey(), 'resolution_type' => $type,
                'resolution_effect' => $effect, 'last_activity_at' => now(),
            ])->save();
            $this->event($d, 'agreed', $user, $role, $from, PaymentDispute::RESOLVED, $outcome);
            $this->audit->record('payment_dispute.agreed', $d, ['status' => $from], ['status' => 'resolved', 'outcome' => $outcome, 'effect' => $effect], null, $user);
            $this->notify->disputeActivity('agreed', $d, $request, $user, $role);

            return [$d, $role];
        });

        if ($confirmed) {
            // Shadow mode (off by default): the existing estimate step, after the agreement is saved; never able to undo it
            $this->shadow->processQuietly($confirmed);
        }

        return $this->present($result[0], $result[1], $user);
    }

    /** Either side withdraws or turns down the open suggestion; nothing else changes */
    public function decline(User $user, int $id): array
    {
        $this->guardEnabled();
        $result = $this->acting($user, $id, function (PaymentDispute $d, string $role, ServiceRequest $request) use ($user) {
            $this->guardOpen($d);
            if (! $d->proposal_outcome) {
                return [$d, $role];
            }
            $outcome = $d->proposal_outcome;
            $d->forceFill(['proposal_outcome' => null, 'proposal_by_user_id' => null, 'proposal_by_role' => null, 'proposal_at' => null, 'last_activity_at' => now()])->save();
            $this->event($d, 'proposal_cleared', $user, $role, null, null, $outcome);

            return [$d, $role];
        });

        return $this->present($result[0], $result[1], $user);
    }

    /** An evidence file, for the case's own two sides: [path, mime, name] */
    public function evidenceFor(User $user, int $id, string $uuid): array
    {
        $this->guardEnabled();
        $dispute = PaymentDispute::find($id);
        if (! $dispute || ! $this->roleOf($user, $dispute)) {
            throw $this->notFound();
        }

        return $this->evidenceRow($dispute, $uuid);
    }

    /* ---------------- The administrator ---------------- */

    /** The queue: counts by stage, and the cases (newest activity first). Only cases that reached WorkMate unless asked for more. */
    public function queue(array $filters): array
    {
        $query = PaymentDispute::query();
        $scope = $filters['scope'] ?? 'escalated';
        if (isset($filters['status']) && $filters['status'] !== '') {
            // "open" is the two early stages together
            $query->whereIn('status', $filters['status'] === PaymentDispute::OPEN ? [PaymentDispute::OPEN, PaymentDispute::DISCUSSION] : [$filters['status']]);
        } elseif ($scope === 'escalated') {
            $query->whereIn('status', [PaymentDispute::ESCALATED, PaymentDispute::UNDER_REVIEW, PaymentDispute::RESOLVED])->whereNotNull('escalated_at');
        }
        if (! empty($filters['from'])) {
            $query->where('opened_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('opened_at', '<=', $filters['to']);
        }
        if (! empty($filters['q'])) {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(function ($w) use ($needle, $filters) {
                $w->whereHas('request', function ($q) use ($needle) {
                    $q->where('title', 'like', $needle);
                })->orWhereIn('provider_profile_id', \App\Models\ProviderProfile::where('display_name', 'like', $needle)->select('id'))
                    ->orWhereIn('customer_id', User::where('name', 'like', $needle)->select('id'));
                if (ctype_digit((string) $filters['q'])) {
                    $w->orWhere('id', (int) $filters['q']);
                }
            });
        }
        $per = 25;
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('last_activity_at')->orderByDesc('id')->forPage(max((int) ($filters['page'] ?? 1), 1), $per)->get();

        $counts = PaymentDispute::select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return [
            'counts' => [
                'open' => (int) ($counts[PaymentDispute::OPEN] ?? 0) + (int) ($counts[PaymentDispute::DISCUSSION] ?? 0),
                'escalated' => (int) ($counts[PaymentDispute::ESCALATED] ?? 0),
                'under_review' => (int) ($counts[PaymentDispute::UNDER_REVIEW] ?? 0),
                'resolved' => (int) ($counts[PaymentDispute::RESOLVED] ?? 0),
            ],
            'items' => $rows->map(function (PaymentDispute $d) {
                return $this->row($d);
            })->values()->all(),
            'page' => max((int) ($filters['page'] ?? 1), 1), 'per_page' => $per, 'total' => $total,
        ];
    }

    public function adminShow(int $id): array
    {
        $dispute = PaymentDispute::find($id);
        if (! $dispute) {
            throw $this->notFound();
        }

        return $this->present($dispute, 'admin', null) + [
            'payments' => $this->payments->history($dispute->request),
        ];
    }

    /** An administrator takes an escalated case into review. The same administrator again changes nothing. */
    public function startReview(User $admin, int $id): array
    {
        $this->adminActing($id, function (PaymentDispute $d) use ($admin) {
            if ($d->status === PaymentDispute::UNDER_REVIEW) {
                return;
            }
            if ($d->status !== PaymentDispute::ESCALATED) {
                throw new BusinessRuleException('not_escalated', 'Only a case that was escalated to WorkMate can be reviewed.', 409);
            }
            $d->forceFill(['status' => PaymentDispute::UNDER_REVIEW, 'review_started_at' => now(), 'reviewer_id' => $admin->getKey(), 'last_activity_at' => now()])->save();
            $this->event($d, 'review_started', $admin, 'admin', PaymentDispute::ESCALATED, PaymentDispute::UNDER_REVIEW, null);
            $this->audit->record('payment_dispute.review_started', $d, ['status' => 'escalated'], ['status' => 'under_review'], null, $admin);
            $this->notify->disputeActivity('review_started', $d, $d->request, $admin, 'admin');
        });

        return $this->adminShow($id);
    }

    /** The administrator asks the customer, the provider or both for something more. The case keeps its stage. */
    public function requestInfo(User $admin, int $id, ?string $to, ?string $message, ?string $token = null): array
    {
        if (! in_array($to, ['customer', 'provider', 'both'], true)) {
            throw new BusinessRuleException('audience_invalid', 'Choose who you are asking.', 422);
        }
        $message = $this->text($message ?? '', self::MAX_MESSAGE);
        if (mb_strlen($message) < 5) {
            throw new BusinessRuleException('message_required', 'Say what you need to know.', 422);
        }

        $this->adminActing($id, function (PaymentDispute $d) use ($admin, $to, $message, $token) {
            if ($token && PaymentDisputeMessage::where('payment_dispute_id', $d->id)->where('client_token', mb_substr($token, 0, 64))->exists()) {
                return;
            }
            if (! in_array($d->status, [PaymentDispute::ESCALATED, PaymentDispute::UNDER_REVIEW], true)) {
                throw new BusinessRuleException('not_escalated', 'You can ask for information once the case is with WorkMate.', 409);
            }
            $row = new PaymentDisputeMessage();
            $row->forceFill([
                'payment_dispute_id' => $d->id, 'author_id' => $admin->getKey(), 'author_role' => 'admin', 'kind' => PaymentDisputeMessage::KIND_INFO_REQUEST,
                'addressed_to' => $to, 'body' => $message, 'client_token' => $token ? mb_substr($token, 0, 64) : null, 'created_at' => now(),
            ])->save();
            $d->forceFill(['last_activity_at' => now()])->save();
            $this->event($d, 'info_requested', $admin, 'admin', null, null, 'To '.$to);
            $this->audit->record('payment_dispute.info_requested', $d, [], ['to' => $to], null, $admin);
            $this->notify->disputeActivity('info_requested', $d, $d->request, $admin, 'admin', $to);
        });

        return $this->adminShow($id);
    }

    /**
     * The reasoned decision that closes a case. Only from "under review". Two administrators deciding at once: the first is
     * recorded and the second is told it is already decided (the same decision again changes and notifies nothing).
     */
    public function decide(User $admin, int $id, ?string $outcome, ?string $reason, $amount = null): array
    {
        if (! in_array($outcome, PaymentDispute::DECISIONS, true)) {
            throw new BusinessRuleException('outcome_invalid', 'Choose the decision.', 422);
        }
        $reason = $this->text($reason ?? '', self::MAX_NOTE);
        if (mb_strlen($reason) < 10) {
            throw new BusinessRuleException('reason_required', 'Write the reason for the decision (at least 10 characters). Both sides will read it.', 422);
        }
        if ($outcome === PaymentDispute::DECIDE_PARTIAL) {
            if ($amount === null || is_bool($amount) || is_float($amount) || ! ctype_digit((string) $amount) || (int) $amount < PaymentReports::MIN_AMOUNT) {
                throw new BusinessRuleException('amount_invalid', 'Enter the amount (whole UGX) that was established as received.', 422);
            }
            $amount = (int) $amount;
        } else {
            $amount = null;
        }

        $this->adminActing($id, function (PaymentDispute $d, JobPaymentReport $report) use ($admin, $outcome, $reason, $amount) {
            if ($d->isResolved()) {
                if ($d->resolution_type === $outcome && (int) $d->resolved_by_user_id === (int) $admin->getKey()) {
                    return;
                }
                throw new BusinessRuleException('already_resolved', 'This case has already been decided.', 409);
            }
            if ($d->status !== PaymentDispute::UNDER_REVIEW) {
                throw new BusinessRuleException('not_under_review', 'Take the case into review before deciding it.', 409);
            }
            if ($outcome === PaymentDispute::DECIDE_PARTIAL && $amount >= (int) $report->amount_ugx) {
                throw new BusinessRuleException('amount_invalid', 'A partial payment is less than the reported UGX '.number_format($report->amount_ugx).'. If all of it was received, choose "Payment received".', 422);
            }

            $effect = 'none';
            $correction = false;
            if ($outcome === PaymentDispute::DECIDE_NOT_ESTABLISHED) {
                if ($report->status !== JobPaymentReport::DISPUTED) {
                    throw new BusinessRuleException('report_changed', 'The payment report is no longer in question.', 409);
                }
                $this->payments->settleDisputed($report, JobPaymentReport::CANCELLED,
                    ['cancelled_by_user_id' => $admin->getKey(), 'cancelled_at' => now(), 'cancel_reason' => 'WorkMate decision (dispute #'.$d->id.'): payment not established as received.'],
                    'cancelled', $admin, 'admin', 'WorkMate decision (dispute #'.$d->id.'): payment not established as received. '.$reason);
                $effect = 'withdrawn';
            } elseif (in_array($outcome, [PaymentDispute::DECIDE_RECEIVED, PaymentDispute::DECIDE_PARTIAL], true)) {
                // An administrator cannot stand in for the customer's confirmation. The decision is recorded and flagged, and the
                // payment report keeps exactly what the customer left it as.
                $correction = true;
            }

            $from = $d->status;
            $d->forceFill([
                'status' => PaymentDispute::RESOLVED, 'resolved_at' => now(), 'resolved_by_user_id' => $admin->getKey(), 'resolution_type' => $outcome,
                'resolution_reason' => $reason, 'resolution_amount_ugx' => $amount, 'resolution_effect' => $effect,
                'financial_correction_required' => $correction, 'last_activity_at' => now(),
                'proposal_outcome' => null, 'proposal_by_user_id' => null, 'proposal_by_role' => null, 'proposal_at' => null,
            ])->save();
            $this->event($d, 'decided', $admin, 'admin', $from, PaymentDispute::RESOLVED, $outcome.': '.$reason);
            $this->audit->record('payment_dispute.decided', $d, ['status' => $from], [
                'status' => 'resolved', 'outcome' => $outcome, 'effect' => $effect, 'amount_ugx' => $amount, 'financial_correction_required' => $correction,
            ], $reason, $admin);
            $this->notify->disputeActivity('decided', $d, $d->request, $admin, 'admin');
        });

        return $this->adminShow($id);
    }

    /** An evidence file for an administrator with disputes.view. Every opening is written to the audit log. */
    public function evidenceForAdmin(User $admin, int $id, string $uuid): array
    {
        $dispute = PaymentDispute::find($id);
        if (! $dispute) {
            throw $this->notFound();
        }
        $file = $this->evidenceRow($dispute, $uuid);
        $this->audit->record('payment_dispute.evidence_viewed', $dispute, [], ['file' => $uuid], null, $admin);

        return $file;
    }

    /* ---------------- What the screens are shown ---------------- */

    /** Adds each report's case (id and stage) to the payment lists both sides and the administrators read */
    public static function attach(array $reports): array
    {
        $ids = array_column($reports, 'id');
        if (! $ids) {
            return $reports;
        }
        $cases = PaymentDispute::whereIn('payment_report_id', $ids)->get(['id', 'payment_report_id', 'status'])->keyBy('payment_report_id');

        return array_map(function ($r) use ($cases) {
            $case = $cases->get($r['id']);
            $r['dispute'] = $case ? ['id' => (int) $case->id, 'status' => $case->status] : null;

            return $r;
        }, $reports);
    }

    private function row(PaymentDispute $d): array
    {
        $report = $d->report;
        $provider = \App\Models\ProviderProfile::withTrashed()->find($d->provider_profile_id);
        $customer = User::find($d->customer_id);

        return [
            'id' => (int) $d->id, 'status' => $d->status, 'resolution_type' => $d->resolution_type,
            'customer' => $customer ? $customer->name : null, 'provider' => $provider ? $provider->display_name : null,
            'amount_ugx' => $report ? (int) $report->amount_ugx : null, 'request_id' => (int) $d->service_request_id,
            'opened_at' => $d->opened_at->toIso8601String(), 'escalated_at' => optional($d->escalated_at)->toIso8601String(),
            'resolved_at' => optional($d->resolved_at)->toIso8601String(), 'last_activity_at' => optional($d->last_activity_at)->toIso8601String(),
            'financial_correction_required' => (bool) $d->financial_correction_required,
        ];
    }

    /**
     * The case as one viewer sees it. $role is 'customer', 'provider' or 'admin'. Money figures appear here, in the app, and
     * never in a notification. File paths are never included, only a link that is checked on every request.
     */
    private function present(PaymentDispute $d, string $role, ?User $viewer): array
    {
        $report = $d->report()->first();
        $request = ServiceRequest::withTrashed()->with(['category', 'provider', 'customer'])->find($d->service_request_id);
        $agreed = $request ? $request->agreedAgreement()->first() : null;
        $final = $request ? $request->currentFinalPrice()->first() : null;
        $names = [
            'customer' => $request && $request->customer ? $this->first($request->customer->name) : 'The customer',
            'provider' => $request && $request->provider ? $request->provider->display_name : 'The provider',
            'admin' => 'WorkMate',
        ];
        $base = $role === 'admin' ? '/admin/disputes/'.$d->id : '/me/disputes/'.$d->id;
        $open = ! $d->isResolved();

        $messages = $d->messages()->with('attachments')->get();
        $users = User::whereIn('id', $messages->pluck('author_id')->unique())->get()->keyBy('id');

        return [
            'id' => (int) $d->id,
            'status' => $d->status,
            'you' => $role,
            'opened_at' => $d->opened_at->toIso8601String(),
            'payment' => $report ? PaymentReports::present($report) : null,
            'job' => $request ? [
                'id' => (int) $request->id, 'title' => $request->title, 'service' => optional($request->category)->name,
                'agreed_amount' => $agreed ? (int) $agreed->amount_ugx : null, 'final_price' => $final ? (int) $final->amount_ugx : null,
            ] : null,
            'parties' => ['customer' => $names['customer'], 'provider' => $names['provider']],
            'can' => $role === 'admin' ? [] : [
                'message' => $open, 'escalate' => $open && ! in_array($d->status, [PaymentDispute::ESCALATED, PaymentDispute::UNDER_REVIEW], true),
                'propose' => $open, 'accept' => $open && $d->proposal_outcome && $d->proposal_by_role !== $role,
                'decline' => $open && (bool) $d->proposal_outcome,
            ],
            'proposal' => $d->proposal_outcome ? [
                'outcome' => $d->proposal_outcome, 'by' => $d->proposal_by_role, 'mine' => $d->proposal_by_role === $role, 'at' => optional($d->proposal_at)->toIso8601String(),
            ] : null,
            'escalation' => $d->escalated_at ? [
                'at' => $d->escalated_at->toIso8601String(), 'by' => $d->escalated_by_role, 'reason' => $d->escalation_reason, 'note' => $d->escalation_note,
            ] : null,
            'review' => $d->review_started_at ? ['at' => $d->review_started_at->toIso8601String()] : null,
            'resolution' => $d->isResolved() ? [
                'type' => $d->resolution_type, 'at' => $d->resolved_at->toIso8601String(), 'reason' => $d->resolution_reason,
                'amount' => $d->resolution_amount_ugx, 'effect' => $d->resolution_effect, 'financial_correction_required' => (bool) $d->financial_correction_required,
            ] : null,
            'messages' => $messages->map(function (PaymentDisputeMessage $m) use ($d, $users, $names, $viewer, $base) {
                $author = $users->get($m->author_id);

                return [
                    'id' => (int) $m->id, 'by' => $m->author_role, 'name' => $names[$m->author_role] ?? '', 'mine' => $viewer ? (int) $m->author_id === (int) $viewer->getKey() : false,
                    'avatar_url' => $m->author_role !== 'admin' && $author ? $author->avatarUrl(96) : null,
                    'kind' => $m->kind, 'addressed_to' => $m->addressed_to, 'body' => $m->body, 'at' => $m->created_at->toIso8601String(),
                    'files' => $m->attachments->map(function (PaymentDisputeEvidence $e) use ($base) {
                        return ['uuid' => $e->uuid, 'name' => $e->original_name, 'mime' => $e->mime, 'size' => $e->size, 'url' => '/api/v1'.$base.'/evidence/'.$e->uuid];
                    })->values()->all(),
                ];
            })->values()->all(),
            'timeline' => $d->events()->get()->map(function (PaymentDisputeEvent $e) use ($names) {
                return [
                    'event' => $e->event, 'by' => $e->actor_role, 'name' => $names[$e->actor_role] ?? 'WorkMate', 'from' => $e->from_status, 'to' => $e->to_status,
                    'note' => $e->note, 'at' => $e->created_at->toIso8601String(),
                ];
            })->values()->all(),
            // Administrators only: who has it, and whether a separate financial correction is still needed
            'admin' => $role === 'admin' ? [
                'reviewer' => $d->reviewer_id ? optional(User::find($d->reviewer_id))->name : null,
                'financial_correction_required' => (bool) $d->financial_correction_required,
                'customer_id' => (int) $d->customer_id, 'provider_profile_id' => (int) $d->provider_profile_id,
            ] : null,
        ];
    }

    /* ---------------- Internals ---------------- */

    /** Runs a participant's action with the locks in the usual order (request, payment, case), after checking who they are */
    private function acting(User $user, int $id, callable $do)
    {
        return DB::transaction(function () use ($user, $id, $do) {
            $probe = PaymentDispute::find($id);
            $role = $probe ? $this->roleOf($user, $probe) : null;
            if (! $role) {
                throw $this->notFound();
            }
            $request = $role === 'customer' ? $this->lockForCustomer($user, $probe->service_request_id) : $this->lockForProvider($user, $probe->service_request_id);
            $report = JobPaymentReport::whereKey($probe->payment_report_id)->lockForUpdate()->first();
            $dispute = PaymentDispute::whereKey($id)->lockForUpdate()->first();

            return $do($dispute, $role, $request, $report);
        }, 3);
    }

    /** The administrator's version: the same lock order, with no ownership check (the permission is the check) */
    private function adminActing(int $id, callable $do): void
    {
        DB::transaction(function () use ($id, $do) {
            $probe = PaymentDispute::find($id);
            if (! $probe) {
                throw $this->notFound();
            }
            ServiceRequest::withTrashed()->whereKey($probe->service_request_id)->lockForUpdate()->first();
            $report = JobPaymentReport::whereKey($probe->payment_report_id)->lockForUpdate()->first();
            $dispute = PaymentDispute::whereKey($id)->lockForUpdate()->first();
            $do($dispute, $report);
        }, 3);
    }

    /** customer, provider (owner or manager of the business), or null for anyone else */
    private function roleOf(User $user, PaymentDispute $d): ?string
    {
        if ((int) $d->customer_id === (int) $user->getKey()) {
            return 'customer';
        }
        $isManager = ProviderMember::where('provider_profile_id', $d->provider_profile_id)->where('user_id', $user->getKey())
            ->whereIn('role', [ProviderMember::ROLE_OWNER, ProviderMember::ROLE_MANAGER])->exists();

        return $isManager ? 'provider' : null;
    }

    private function guardOpen(PaymentDispute $d): void
    {
        if ($d->isResolved()) {
            throw new BusinessRuleException('dispute_closed', 'This payment question has been closed.', 409);
        }
    }

    private function agreementType(?string $outcome): ?string
    {
        return $outcome === PaymentDispute::AGREE_RECEIVED ? PaymentDispute::BY_AGREEMENT_RECEIVED : ($outcome === PaymentDispute::AGREE_NOT_RECEIVED ? PaymentDispute::BY_AGREEMENT_NOT_RECEIVED : null);
    }

    private function notFound(): BusinessRuleException
    {
        return new BusinessRuleException('not_found', 'The requested item was not found.', 404);
    }

    private function userOrNull($id): ?User
    {
        return $id ? User::find($id) : null;
    }

    private function first(?string $name): string
    {
        $name = trim((string) $name);

        return $name !== '' ? explode(' ', $name)[0] : 'The customer';
    }

    private function event(PaymentDispute $d, string $event, ?User $actor, string $role, ?string $from, ?string $to, ?string $note): void
    {
        $row = new PaymentDisputeEvent();
        $row->forceFill([
            'payment_dispute_id' => $d->id, 'event' => $event, 'actor_id' => $actor ? $actor->getKey() : null, 'actor_role' => $role,
            'from_status' => $from, 'to_status' => $to, 'note' => $note ? mb_substr($note, 0, self::MAX_NOTE) : null, 'created_at' => now(),
        ])->save();
    }

    /** Saves one evidence file: a photo is re-drawn (hidden data such as GPS dropped), a PDF is checked to be one. Private disk, random name. */
    private function storeEvidence(PaymentDispute $d, PaymentDisputeMessage $message, User $user, string $role, UploadedFile $file, array &$stored): void
    {
        $saved = $this->files->store($file, PaymentDisputeEvidence::DIRECTORY, true);
        $stored[] = $saved['path'];
        $row = new PaymentDisputeEvidence();
        $row->forceFill([
            'payment_dispute_id' => $d->id, 'message_id' => $message->id, 'uploaded_by_user_id' => $user->getKey(), 'uploader_role' => $role,
            'uuid' => pathinfo($saved['path'], PATHINFO_FILENAME), 'path' => $saved['path'], 'original_name' => mb_substr($saved['name'], 0, 120),
            'mime' => $saved['mime'], 'size' => $saved['size'], 'created_at' => now(),
        ])->save();
    }

    private function evidenceRow(PaymentDispute $d, string $uuid): array
    {
        $row = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)
            ? PaymentDisputeEvidence::where('payment_dispute_id', $d->id)->where('uuid', $uuid)->first() : null;
        if (! $row) {
            throw $this->notFound();
        }

        return ['path' => $row->path, 'mime' => $row->mime, 'name' => $row->original_name ?: 'Evidence'];
    }
}
