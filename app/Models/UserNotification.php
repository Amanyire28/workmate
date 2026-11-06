<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Something an account was told about, listed under the bell. Written by App\Services\UserNotifications. */
class UserNotification extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'user_id' => 'integer',
        'read_at' => 'datetime',
    ];
}
