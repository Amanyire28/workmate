<?php

namespace App\Models;

use App\Services\CommunityMentions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A comment on a community post, or a reply to one (one level deep) */
class CommunityComment extends Model
{
    use SoftDeletes;

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REMOVED = 'removed';

    public const MAX_BODY = 1000;

    protected $guarded = ['*'];

    /** @var bool whether the person viewing the list liked it (not stored) */
    public $likedByViewer = false;

    protected $attributes = [
        'status' => self::STATUS_PUBLISHED,
    ];

    protected $casts = [
        'community_post_id' => 'integer',
        'user_id' => 'integer',
        'parent_id' => 'integer',
        'removed_by' => 'integer',
        'removed_at' => 'datetime',
    ];

    protected static function booted()
    {
        // Deleted for good: its mentions go too (soft-deleted ones keep them, unseen)
        static::forceDeleted(function (self $item) {
            app(CommunityMentions::class)->forget(CommunityMentions::TYPE_COMMENT, $item->id);
        });
    }

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id')->withTrashed();
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function replies()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @mentions in it, each with the person (and what decides where their name links) */
    public function mentions()
    {
        return $this->hasMany(CommunityMention::class, 'mentionable_id')
            ->where('mentionable_type', CommunityMentions::TYPE_COMMENT)
            ->with('user.providerProfile');
    }

    public function isVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && ! $this->trashed();
    }
}
