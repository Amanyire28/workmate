<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A service on a provider's "Services & Prices" list. The price is what they
 * usually charge: a guide, not an amount owed, unless fixed and agreed.
 * Changes go through App\Services\ProviderServices.
 */
class ProviderService extends Model
{
    public const PRICING_FIXED = 'fixed';
    public const PRICING_FROM = 'from';
    public const PRICING_QUOTE = 'quote';
    public const PRICING_TYPES = [self::PRICING_FIXED, self::PRICING_FROM, self::PRICING_QUOTE];

    public const UNITS = ['hour', 'day', 'visit', 'item', 'job'];

    /** How many a provider can list */
    public const MAX = 30;

    // Whole shillings, within reason
    public const MIN_PRICE = 500;
    public const MAX_PRICE = 100000000;

    protected $guarded = ['*'];

    protected $casts = [
        'provider_profile_id' => 'integer',
        'category_id' => 'integer',
        'price' => 'integer',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
