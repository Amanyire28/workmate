<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Money that cannot be applied without an approved reconciliation decision (Phase B7): an overpayment, or a credit left in excess after an
 * obligation was reversed. It is only recorded and shown. Nothing is refunded, moved or allocated automatically. Never deleted.
 */
class CommissionException extends Model
{
    public const OVERPAYMENT = 'overpayment';
    public const EXCESS_CREDIT = 'excess_credit';
    // Gateway money that cannot be applied automatically: confirmed after the request expired or was cancelled, or for another amount
    public const LATE_PAYMENT = 'late_payment';
    public const GATEWAY_MISMATCH = 'gateway_mismatch';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['provider_profile_id' => 'integer', 'commission_payment_id' => 'integer', 'cycle_id' => 'integer', 'amount_ugx' => 'integer', 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::deleting(function () {
            throw new LogicException('Reconciliation exceptions are never deleted.');
        });
    }
}
