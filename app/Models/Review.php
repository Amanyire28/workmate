<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer's review of a provider. Ratings shown on profiles are always
 * calculated from published reviews, never stored as a number.
 */
class Review extends Model
{
    public const STATUS_PUBLISHED = 'published';

    protected $guarded = ['*'];

    protected $casts = [
        'provider_profile_id' => 'integer',
        'reviewer_id' => 'integer',
        'rating' => 'integer',
        'would_hire_again' => 'boolean',
        'is_sample' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }
}
