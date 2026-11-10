<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job a provider shows on their profile under "Recent work", or as one of
 * up to three featured projects at the top. Changes go through
 * App\Services\ProviderWork.
 */
class ProviderWorkItem extends Model
{
    use SoftDeletes;

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REMOVED = 'removed';

    /** How many a provider can show at once */
    public const MAX_ITEMS = 30;
    public const MAX_PHOTOS = 4;

    /** Featured projects: flagship jobs at the top of the profile, with as many photos as the provider likes up to six */
    public const MAX_FEATURED = 3;
    public const FEATURED_MAX_PHOTOS = 6;

    protected $guarded = ['*'];

    protected $casts = [
        'provider_profile_id' => 'integer',
        'category_id' => 'integer',
        'area_id' => 'integer',
        'done_on' => 'date',
        'is_featured' => 'boolean',
        'removed_at' => 'datetime',
    ];

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    public function photos()
    {
        return $this->hasMany(ProviderWorkPhoto::class)->orderBy('position');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /** Anyone can see it: published, not deleted, on a provider customers can find */
    public function isVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && ! $this->trashed()
            && $this->provider && ProviderProfile::publiclyListed()->whereKey($this->provider_profile_id)->exists();
    }
}
