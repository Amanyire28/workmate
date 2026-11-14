<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One photo of a provider's work, in a full and a thumbnail size */
class ProviderWorkPhoto extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'provider_work_item_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'position' => 'integer',
        'watermarked_at' => 'datetime',
    ];

    // Storage paths stay on the server
    protected $hidden = ['path', 'thumb_path', 'original_path', 'watermark_error'];

    public function item()
    {
        return $this->belongsTo(ProviderWorkItem::class, 'provider_work_item_id')->withTrashed();
    }

    public function url(bool $thumb = false): string
    {
        return url('/api/v1/work/media/'.$this->uuid.($thumb ? '-t' : '').'.jpg');
    }
}
