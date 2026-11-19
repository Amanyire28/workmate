<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One photo in a community post (a full and a thumbnail size), or the post's
 * one video. A video lives in Cloudflare R2 (video_key); path and thumb_path
 * then hold its poster image, or '' when there is none.
 */
class CommunityPostMedia extends Model
{
    public const TYPE_PHOTO = 'photo';
    public const TYPE_VIDEO = 'video';

    protected $table = 'community_post_media';

    protected $guarded = ['*'];

    protected $attributes = [
        'type' => self::TYPE_PHOTO,
    ];

    protected $casts = [
        'community_post_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'position' => 'integer',
        'duration' => 'float',
        'size' => 'integer',
        'watermarked_at' => 'datetime',
        'video_deleted_at' => 'datetime',
    ];

    // Storage paths and keys stay on the server
    protected $hidden = ['path', 'thumb_path', 'original_path', 'watermark_error', 'video_key'];

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id')->withTrashed();
    }

    public function scopePhotos($query)
    {
        return $query->where('type', self::TYPE_PHOTO);
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    /** Has an image to show (every photo; a video only when it has a poster) */
    public function hasImage(): bool
    {
        return (string) $this->path !== '';
    }

    public function url(bool $thumb = false): string
    {
        return url('/api/v1/community/media/'.$this->uuid.($thumb ? '-t' : '').'.jpg');
    }
}
