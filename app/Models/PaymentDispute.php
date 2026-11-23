<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The case opened when a customer disputes a payment report (Phase B5). One per report. It points at the report and never
 * copies it: the report's own status stays the one record of whether a payment is confirmed. Only the case's own status and
 * decision fields move (through App\Services\PaymentDisputes); the timeline is the append-only PaymentDisputeEvent table.
 */
class PaymentDispute extends Model
{
    public const OPEN = 'open';
    public const DISCUSSION = 'discussion';
    public const ESCALATED = 'escalated';
    public const UNDER_REVIEW = 'under_review';
    public const RESOLVED = 'resolved';

    /** The cases that still need an answer */
    public const ACTIVE = [self::OPEN, self::DISCUSSION, self::ESCALATED, self::UNDER_REVIEW];

    // What the two sides can agree on between themselves
    public const AGREE_RECEIVED = 'payment_received';
    public const AGREE_NOT_RECEIVED = 'payment_not_received';
    public const AGREEMENTS = [self::AGREE_RECEIVED, self::AGREE_NOT_RECEIVED];

    // What an administrator can decide
    public const DECIDE_RECEIVED = 'received';
    public const DECIDE_NOT_ESTABLISHED = 'not_established';
    public const DECIDE_PARTIAL = 'partial';
    public const DECIDE_INSUFFICIENT = 'insufficient_evidence';
    public const DECISIONS = [self::DECIDE_RECEIVED, self::DECIDE_NOT_ESTABLISHED, self::DECIDE_PARTIAL, self::DECIDE_INSUFFICIENT];

    // resolution_type values besides the two lists above
    public const BY_PROVIDER_WITHDRAWAL = 'provider_withdrew';
    public const BY_AGREEMENT_RECEIVED = 'agreed_received';
    public const BY_AGREEMENT_NOT_RECEIVED = 'agreed_not_received';

    public const ESCALATION_REASONS = ['no_agreement', 'no_response', 'evidence_disagreement', 'other'];

    protected $guarded = ['*'];

    protected $casts = [
        'payment_report_id' => 'integer',
        'service_request_id' => 'integer',
        'provider_profile_id' => 'integer',
        'customer_id' => 'integer',
        'opened_by_user_id' => 'integer',
        'escalated_by_user_id' => 'integer',
        'reviewer_id' => 'integer',
        'proposal_by_user_id' => 'integer',
        'resolved_by_user_id' => 'integer',
        'resolution_amount_ugx' => 'integer',
        'financial_correction_required' => 'boolean',
        'opened_at' => 'datetime',
        'escalated_at' => 'datetime',
        'review_started_at' => 'datetime',
        'proposal_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    // What a case is about never changes once opened
    private const FROZEN = ['payment_report_id', 'service_request_id', 'provider_profile_id', 'customer_id', 'opened_by_user_id', 'opened_at'];

    protected static function booted()
    {
        static::updating(function (PaymentDispute $d) {
            foreach (self::FROZEN as $field) {
                if ($d->isDirty($field)) {
                    throw new LogicException("A dispute's subject cannot be changed ({$field}).");
                }
            }
            // A closed case is closed: no field moves again
            if ($d->getOriginal('status') === self::RESOLVED) {
                throw new LogicException('A resolved dispute is never changed.');
            }
        });
        static::deleting(function () {
            throw new LogicException('Disputes are never deleted.');
        });
    }

    public function report()
    {
        return $this->belongsTo(JobPaymentReport::class, 'payment_report_id');
    }

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id')->withTrashed();
    }

    public function events()
    {
        return $this->hasMany(PaymentDisputeEvent::class)->orderBy('id');
    }

    public function messages()
    {
        return $this->hasMany(PaymentDisputeMessage::class)->orderBy('id');
    }

    public function evidence()
    {
        return $this->hasMany(PaymentDisputeEvidence::class)->orderBy('id');
    }

    public function isResolved(): bool
    {
        return $this->status === self::RESOLVED;
    }
}
