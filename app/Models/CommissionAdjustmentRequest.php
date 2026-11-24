<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A proposed correction to what a provider owes (Phase B8), and the decision on it. It is a RECORD: nothing about it writes to the ledger.
 * Posting an approved adjustment is deliberately not built until the correction policy (types, signs, what may be corrected) is approved,
 * so `posting_status` stays "not_posted". What was proposed never changes; only the decision fields move, once.
 */
class CommissionAdjustmentRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    // The situations the brief names. They say what a request is ABOUT; they do not define any behaviour or any sign.
    public const TYPES = ['accounting_error', 'obligation_correction', 'credit_allocation_correction', 'reconciliation_correction'];
    public const DIRECTIONS = ['increase', 'decrease'];

    private const FROZEN = [
        'provider_profile_id', 'type', 'direction', 'amount_ugx', 'assessment_id', 'commission_payment_id', 'cycle_id', 'exception_id', 'payment_report_id',
        'dispute_id', 'reference', 'reason', 'provider_reason', 'client_token', 'requested_by_user_id', 'requested_at',
    ];

    protected $guarded = ['*'];

    protected $casts = [
        'provider_profile_id' => 'integer', 'amount_ugx' => 'integer', 'assessment_id' => 'integer', 'commission_payment_id' => 'integer', 'cycle_id' => 'integer',
        'exception_id' => 'integer', 'payment_report_id' => 'integer', 'dispute_id' => 'integer', 'requested_by_user_id' => 'integer', 'decided_by_user_id' => 'integer',
        'requested_at' => 'datetime', 'decided_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function (CommissionAdjustmentRequest $r) {
            foreach (self::FROZEN as $field) {
                if ($r->isDirty($field)) {
                    throw new LogicException("An adjustment request cannot be changed after it is made ({$field}).");
                }
            }
            if ($r->getOriginal('status') !== self::PENDING) {
                throw new LogicException('A decided adjustment request is never changed.');
            }
        });
        static::deleting(function () {
            throw new LogicException('Adjustment requests are never deleted.');
        });
    }
}
