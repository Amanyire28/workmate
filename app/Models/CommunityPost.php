<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something a member shared about their work. Changes go through
 * App\Services\Community.
 */
class CommunityPost extends Model
{
    use SoftDeletes;

    public const CATEGORIES = [
        'my_work' => 'My Work',
        'tips' => 'Work Tips',
        'experience' => 'Experience',
        'question' => 'Questions',
        'opportunity' => 'Opportunities',
    ];

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REMOVED = 'removed';

    public const MAX_BODY = 3000;
    public const MAX_PHOTOS = 6;

    protected $guarded = ['*'];

    protected $attributes = [
        'status' => self::STATUS_PUBLISHED,
    ];

    protected $casts = [
        'user_id' => 'integer',
        'provider_profile_id' => 'integer',
        'topic_category_id' => 'integer',
        'area_id' => 'integer',
        'removed_by' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'removed_at' => 'datetime',
        'edited_at' => 'datetime',
        'likes_notified_at' => 'datetime',
    ];

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id')->withTrashed();
    }

    public function topic()
    {
        return $this->belongsTo(Category::class, 'topic_category_id');
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function media()
    {
        return $this->hasMany(CommunityPostMedia::class)->orderBy('position');
    }

    public function comments()
    {
        return $this->hasMany(CommunityComment::class);
    }

    /** Published, and the author's account is still active */
    public function scopeVisible($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED)->whereHas('author', function ($q) {
            $q->where('status', User::STATUS_ACTIVE);
        });
    }

    public function isVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && ! $this->trashed()
            && $this->author && ! $this->author->trashed() && $this->author->isActive();
    }
}
