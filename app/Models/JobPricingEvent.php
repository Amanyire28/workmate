<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One step of a price negotiation, written once and never changed or removed (Phase B2). */
class JobPricingEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'service_request_id' => 'integer',
        'agreement_id' => 'integer',
        'actor_id' => 'integer',
        'amount_ugx' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('The price history is never changed.');
        });
        static::deleting(function () {
            throw new LogicException('The price history is never deleted.');
        });
    }
}
