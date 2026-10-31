<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One anonymous install event from the web app: the install offer was shown,
 * the browser confirmed an install, or the app was first opened from its icon.
 */
class AppInstallEvent extends Model
{
    public const EVENTS = ['prompt_shown', 'installed', 'first_launch'];

    public const PLATFORMS = ['android', 'ios', 'desktop'];

    public const SOURCES = ['button', 'browser'];

    /** Counted once per device ever; prompts are counted once per device per day */
    public const ONCE_PER_DEVICE = ['installed', 'first_launch'];

    public const UPDATED_AT = null;

    protected $fillable = ['device_id', 'event', 'platform', 'source'];

    protected $casts = [
        'occurred_on' => 'date',
    ];
}
