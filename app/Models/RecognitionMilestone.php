<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A badge earned by inviting a number of new members (admins set them) */
class RecognitionMilestone extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'threshold' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
