<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One step in investigating a commission reconciliation exception (Phase B8): written once, never changed or removed. */
class CommissionExceptionEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['exception_id' => 'integer', 'actor_id' => 'integer', 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('An exception history is never changed.');
        });
        static::deleting(function () {
            throw new LogicException('An exception history is never deleted.');
        });
    }
}
