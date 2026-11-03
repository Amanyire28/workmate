<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One photo in a community post, in a full and a thumbnail size */
class CommunityPostMedia extends Model
{
    protected $table = 'community_post_media';

    protected $guarded = ['*'];

    protected $casts = [
        'community_post_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'position' => 'integer',
    ];

    // Storage paths stay on the server
    protected $hidden = ['path', 'thumb_path'];

    public function post()
    {
        return $this->belongsTo(CommunityPost::class, 'community_post_id')->withTrashed();
    }

    public function url(bool $thumb = false): string
    {
        return url('/api/v1/community/media/'.$this->uuid.($thumb ? '-t' : '').'.jpg');
    }
}
