<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * What a provider's commission on one customer-confirmed payment was assessed at (Phase B6): the payment, the policy version and
 * the rate, written once and never changed or removed. What is still owed is not stored here: it is the sum of its ledger entries.
 */
class CommissionAssessment extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'payment_report_id' => 'integer', 'snapshot_id' => 'integer', 'service_request_id' => 'integer', 'provider_profile_id' => 'integer',
        'amount_ugx' => 'integer', 'policy_id' => 'integer', 'policy_version' => 'integer', 'rate_bp' => 'integer', 'commission_ugx' => 'integer',
        'assessed_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('A commission assessment is never changed. A correction is a new ledger entry.');
        });
        static::deleting(function () {
            throw new LogicException('A commission assessment is never deleted.');
        });
    }

    public function entries()
    {
        return $this->hasMany(CommissionLedgerEntry::class, 'assessment_id')->orderBy('id');
    }

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id')->withTrashed();
    }
}
