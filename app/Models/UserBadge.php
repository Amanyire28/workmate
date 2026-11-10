<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A recognition badge someone earned; revoked ones stay on record */
class UserBadge extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'user_id' => 'integer',
        'recognition_milestone_id' => 'integer',
        'awarded_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function milestone()
    {
        return $this->belongsTo(RecognitionMilestone::class, 'recognition_milestone_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeHeld($query)
    {
        return $query->whereNull('revoked_at');
    }
}
