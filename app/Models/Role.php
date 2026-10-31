<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    /**
     * Routes address roles by key (/roles/finance). Set here rather than as
     * {role:key}, which Laravel 8 would scope to the user's existing roles.
     */
    public function getRouteKeyName()
    {
        return 'key';
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('assigned_by')->withTimestamps();
    }
}
