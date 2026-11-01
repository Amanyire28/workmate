<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One browser on one device that turned on push notifications. Deleted when
 * the person turns them off or the push service says it no longer exists.
 */
class PushSubscription extends Model
{
    public const PLATFORMS = ['android', 'ios', 'desktop'];

    public const ENCODINGS = ['aes128gcm', 'aesgcm'];

    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'platform',
    ];

    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    protected $casts = [
        'user_id' => 'integer',
        'last_success_at' => 'datetime',
    ];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
