<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A setting an administrator has changed from its default. Read and write
 * settings through App\Services\PlatformSettings, which validates keys.
 */
class PlatformSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'json',
    ];
}
