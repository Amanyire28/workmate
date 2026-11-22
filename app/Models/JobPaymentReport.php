<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A payment the provider REPORTS having received directly from the customer (Phase B3). WorkMate does not take
 * the payment: a report is a statement, and it counts as confirmed only after the customer explicitly confirms it.
 * What was reported (amount, method, date, reference, note) never changes after it is saved; a mistake is fixed by
 * withdrawing the report and reporting a corrected one. Only the decision fields move, through App\Services\PaymentReports.
 */
class JobPaymentReport extends Model
{
    public const PENDING = 'pending_confirmation';
    public const CONFIRMED = 'confirmed';
    public const DISPUTED = 'disputed';
    public const CANCELLED = 'cancelled';

    public const METHODS = ['cash', 'mobile_money', 'bank_transfer', 'other'];

    private const FROZEN = [
        'service_request_id', 'provider_profile_id', 'customer_id', 'reported_by_user_id', 'amount_ugx', 'method', 'paid_on',
        'reference', 'note', 'agreement_id', 'final_price_id', 'corrects_id', 'client_token',
    ];

    protected $guarded = ['*'];

    protected $casts = [
        'service_request_id' => 'integer',
        'provider_profile_id' => 'integer',
        'customer_id' => 'integer',
        'reported_by_user_id' => 'integer',
        'amount_ugx' => 'integer',
        'agreement_id' => 'integer',
        'final_price_id' => 'integer',
        'corrects_id' => 'integer',
        'responded_by_user_id' => 'integer',
        'cancelled_by_user_id' => 'integer',
        'paid_on' => 'date',
        'responded_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function (JobPaymentReport $r) {
            foreach (self::FROZEN as $field) {
                if ($r->isDirty($field)) {
                    throw new LogicException("A payment report cannot be changed after it is saved ({$field}). Withdraw it and report a corrected one.");
                }
            }
        });
        static::deleting(function () {
            throw new LogicException('Payment reports are never deleted.');
        });
    }

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id')->withTrashed();
    }
}
