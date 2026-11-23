<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One line of the commission ledger (Phase B6): written once, never changed or removed. The outstanding amount is the sum of the lines. */
class CommissionLedgerEntry extends Model
{
    public const ASSESSMENT = 'assessment';
    public const REVERSAL = 'reversal';
    /** Phase B7: a verified commission payment taking some of what is owed away (negative, belongs to a cycle and a payment, not to one assessment) */
    public const SETTLEMENT = 'settlement';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['assessment_id' => 'integer', 'cycle_id' => 'integer', 'commission_payment_id' => 'integer', 'provider_profile_id' => 'integer', 'amount_ugx' => 'integer', 'dispute_id' => 'integer', 'actor_id' => 'integer', 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('The commission ledger is never changed.');
        });
        static::deleting(function () {
            throw new LogicException('The commission ledger is never deleted.');
        });
    }

    public function assessment()
    {
        return $this->belongsTo(CommissionAssessment::class, 'assessment_id');
    }
}
