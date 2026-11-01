<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A push notification an administrator sent to every subscribed device,
 * with how many devices the push services accepted it for.
 */
class PushAnnouncement extends Model
{
    protected $fillable = [
        'title',
        'body',
        'url',
    ];

    protected $casts = [
        'sent_by' => 'integer',
        'recipients' => 'integer',
        'delivered' => 'integer',
        'failed' => 'integer',
        'expired' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
