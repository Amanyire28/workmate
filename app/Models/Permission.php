<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'key',
        'description',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    /** Whether a Gate ability name is one of the defined permission keys. */
    public static function isDefined(string $key): bool
    {
        return array_key_exists($key, config('permissions.permissions', []));
    }
}
