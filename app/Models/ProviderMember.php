<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderMember extends Model
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_MEMBER = 'member';

    protected $fillable = [
        'user_id',
        'role',
    ];

    // Not mass-assignable: only the owner changes it, through App\Services\ProviderFinanceAccess

    protected $casts = [
        'provider_profile_id' => 'integer',
        'user_id' => 'integer',
        'can_view_finances' => 'boolean',
    ];

    public function profile()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
