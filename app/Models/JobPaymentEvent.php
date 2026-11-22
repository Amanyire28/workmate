<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One step of a payment report or of the final price, written once and never changed or removed (Phase B3). */
class JobPaymentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'service_request_id' => 'integer',
        'payment_id' => 'integer',
        'actor_id' => 'integer',
        'amount_ugx' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('The payment history is never changed.');
        });
        static::deleting(function () {
            throw new LogicException('The payment history is never deleted.');
        });
    }
}
