<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An optional consent given or withdrawn (for example marketing). Each change
 * adds a row, so the latest row is the current choice and the rest is the record.
 */
class UserConsent extends Model
{
    public const PURPOSES = ['marketing'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'user_id' => 'integer',
        'granted' => 'boolean',
        'created_at' => 'datetime',
    ];
}
