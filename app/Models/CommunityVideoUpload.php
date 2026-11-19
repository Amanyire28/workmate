<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A video on its way from a member's phone to storage (Cloudflare R2). The
 * post is only made once the upload is finished and checked; abandoned ones
 * expire and their files are removed (workmate:video-cleanup).
 */
class CommunityVideoUpload extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CHECKING = 'checking';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $guarded = ['*'];

    protected $casts = [
        'user_id' => 'integer',
        'community_post_id' => 'integer',
        'size' => 'integer',
        'duration' => 'float',
        'width' => 'integer',
        'height' => 'integer',
        'cleanup_attempts' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'object_deleted_at' => 'datetime',
    ];

    // The storage key never leaves the server
    protected $hidden = ['object_key'];

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id')->withTrashed();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
