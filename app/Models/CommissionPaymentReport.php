<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * What a provider business says it paid WorkMate towards a settlement cycle (Phase B7). A report is a STATEMENT: it creates no credit
 * and reduces nothing until finance independently confirms receipt. What was reported never changes; only the decision fields move
 * (once: pending to verified or rejected), through App\Services\CommissionSettlements. Never deleted.
 */
class CommissionPaymentReport extends Model
{
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';

    public const CHANNELS = ['mtn_momo', 'airtel_money', 'bank_transfer', 'cash'];

    private const FROZEN = [
        'provider_profile_id', 'cycle_id', 'reported_by_user_id', 'amount_ugx', 'channel', 'reference', 'paid_on', 'note', 'client_token',
        'evidence_path', 'evidence_name', 'evidence_mime', 'evidence_size',
    ];

    protected $guarded = ['*'];

    protected $hidden = ['evidence_path'];

    protected $casts = [
        'provider_profile_id' => 'integer', 'cycle_id' => 'integer', 'reported_by_user_id' => 'integer', 'amount_ugx' => 'integer',
        'decided_by_user_id' => 'integer', 'verified_amount_ugx' => 'integer', 'credited_ugx' => 'integer', 'excess_ugx' => 'integer',
        'ledger_entry_id' => 'integer', 'paid_on' => 'date', 'decided_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function (CommissionPaymentReport $r) {
            foreach (self::FROZEN as $field) {
                if ($r->isDirty($field)) {
                    throw new LogicException("A commission payment report cannot be changed after it is saved ({$field}).");
                }
            }
            // A decision is made once
            if ($r->getOriginal('status') !== self::PENDING) {
                throw new LogicException('A decided commission payment report is never changed.');
            }
        });
        static::deleting(function () {
            throw new LogicException('Commission payment reports are never deleted.');
        });
    }
}
