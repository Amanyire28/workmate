<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A warning the safety team sent someone, shown in their account */
class AccountWarning extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'user_id' => 'integer',
        'report_id' => 'integer',
        'issued_by' => 'integer',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
