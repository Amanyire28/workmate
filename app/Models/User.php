<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DEACTIVATED = 'deactivated';

    /**
     * The attributes that are mass assignable.
     *
     * Status is deliberately absent: only server-side actions change it.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'locale',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'notification_preferences' => 'array',
    ];

    /**
     * Match the column defaults so new models are correct before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'en',
        'status' => self::STATUS_ACTIVE,
    ];

    /** @var array<int, string>|null */
    protected $permissionKeysCache = null;

    public function roles()
    {
        return $this->belongsToMany(Role::class)->withPivot('assigned_by')->withTimestamps();
    }

    /** "Aisha Nakato" → "Aisha N.", for places where the full name isn't needed */
    public static function shortNameOf(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = ($parts[0] ?? '') ?: 'Customer';
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1).'.' : '';

        return mb_substr(trim($first.' '.$last), 0, 60);
    }

    /** Web address of the profile photo, or null when there isn't one */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? url('/api/v1/avatars/'.basename($this->avatar_path)) : null;
    }

    /** Community: the accounts following this one, and the ones it follows */
    public function followers()
    {
        return $this->belongsToMany(self::class, 'follows', 'followed_id', 'follower_id');
    }

    public function following()
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'followed_id');
    }

    public function communityPosts()
    {
        return $this->hasMany(CommunityPost::class);
    }

    /** The provider profile this account owns, if it has applied. */
    public function providerProfile()
    {
        return $this->hasOne(ProviderProfile::class, 'owner_id');
    }

    /**
     * Whether the account may use provider features right now. Every account
     * is also a customer; provider capability is added on approval.
     */
    public function isProvider(): bool
    {
        return $this->isActive()
            && $this->providerProfile !== null
            && $this->providerProfile->isApproved();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasRole(string $key): bool
    {
        return $this->roles->contains('key', $key);
    }

    /**
     * Permission keys granted through the user's roles. A suspended or
     * deactivated account holds none, whatever its roles.
     *
     * @return array<int, string>
     */
    public function permissionKeys(): array
    {
        if (! $this->isActive()) {
            return [];
        }

        if ($this->permissionKeysCache === null) {
            $this->permissionKeysCache = Permission::query()
                ->whereHas('roles.users', function ($query) {
                    $query->where('users.id', $this->getKey());
                })
                ->orderBy('key')
                ->pluck('key')
                ->all();
        }

        return $this->permissionKeysCache;
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }

    /** Call after changing the user's roles within the same request. */
    public function flushPermissionCache(): void
    {
        $this->permissionKeysCache = null;
        $this->unsetRelation('roles');
    }
}
