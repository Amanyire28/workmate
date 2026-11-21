<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An @mention of an account in a community post or comment: who (by account
 * id), and where it sits in the text (start and length in UTF-16 code units).
 * Written only by CommunityMentions, from the text the server checked.
 */
class CommunityMention extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'mentionable_id' => 'integer',
        'user_id' => 'integer',
        'start' => 'integer',
        'length' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
