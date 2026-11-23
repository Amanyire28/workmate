<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A stretch of a provider's commission obligations that is settled together (Phase B7). It stores only who it belongs to: what it owes,
 * when it is due and what state it is in are always worked out from its obligations and credits (App\Services\CommissionSettlements),
 * never kept as a field that someone could edit. Never changed or removed.
 */
class CommissionSettlementCycle extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['provider_profile_id' => 'integer', 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('A settlement cycle is never changed.');
        });
        static::deleting(function () {
            throw new LogicException('A settlement cycle is never deleted.');
        });
    }
}
