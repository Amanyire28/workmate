<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A confidential report about a provider. The provider is never told who
 * sent it, and it has no effect until the safety team confirms it.
 */
class ProviderReport extends Model
{
    public const REASONS = ['scam', 'offline', 'no_show', 'unsafe', 'fake', 'quality', 'other'];

    protected $fillable = [
        'reason',
        'details',
    ];

    protected $casts = [
        'provider_profile_id' => 'integer',
        'reporter_id' => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }
}
