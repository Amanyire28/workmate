<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A time a provider can't take bookings. Checked with their confirmed appointments. */
class ProviderUnavailablePeriod extends Model
{
    public const MAX = 50;

    protected $guarded = ['*'];

    protected $casts = [
        'provider_profile_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
