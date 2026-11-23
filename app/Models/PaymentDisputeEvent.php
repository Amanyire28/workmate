<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One step in a payment dispute, written once and never changed or removed (Phase B5). */
class PaymentDisputeEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['payment_dispute_id' => 'integer', 'actor_id' => 'integer', 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('A dispute timeline is never changed.');
        });
        static::deleting(function () {
            throw new LogicException('A dispute timeline is never deleted.');
        });
    }
}
