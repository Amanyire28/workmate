<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Provider capabilities for an account. Status changes go through
 * App\Services\ProviderApplications, which enforces the allowed transitions.
 */
class ProviderProfile extends Model
{
    use SoftDeletes;

    public const TYPES = ['individual', 'group', 'company'];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_MORE_INFO_REQUIRED = 'more_info_required';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';

    /** Statuses in which the applicant may edit their details */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_MORE_INFO_REQUIRED, self::STATUS_APPROVED];

    /** Status and dates are set only by the application service, never from a request. */
    protected $fillable = [
        'type',
        'display_name',
        'bio',
        'business_registration_number',
        'skills',
        'availability_status',
        'availability_note',
        'next_available',
        'price_from',
        'price_basis',
    ];

    protected $casts = [
        'owner_id' => 'integer',
        'base_area_id' => 'integer',
        'is_sample' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
        'skills' => 'array',
        'price_from' => 'integer',
        'approved_at' => 'datetime',
        'suspended_at' => 'datetime',
        'document_verified_at' => 'datetime',
        'blue_tick_awarded_at' => 'datetime',
    ];

    public const AVAILABILITY_STATUSES = ['available', 'busy', 'unavailable'];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    public function coverUrl(): ?string
    {
        return $this->cover_path ? url('/api/v1/covers/'.basename($this->cover_path)) : null;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->hasMany(ProviderMember::class);
    }

    public function professions()
    {
        return $this->belongsToMany(Profession::class, 'provider_professions')
            ->withPivot('years_experience', 'is_primary')
            ->withTimestamps();
    }

    /** Professions typed under "Other" */
    public function professionSuggestions()
    {
        return $this->hasMany(ProfessionSuggestion::class);
    }

    public function documentVerification()
    {
        return $this->hasOne(DocumentVerification::class);
    }

    public function baseArea()
    {
        return $this->belongsTo(Area::class, 'base_area_id');
    }

    public function serviceAreas()
    {
        return $this->belongsToMany(Area::class, 'provider_service_areas');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /** Approved providers whose owner account is active: the ones customers can find */
    public function scopePubliclyListed($query)
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->whereHas('owner', function ($owner) {
                $owner->where('status', User::STATUS_ACTIVE);
            });
    }

    public function applications()
    {
        return $this->hasMany(ProviderApplication::class);
    }

    public function latestApplication()
    {
        return $this->hasOne(ProviderApplication::class)->latestOfMany();
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->getKey())->exists();
    }

    /** The profession (category) of the first, main skill */
    public function mainCategoryId(): ?int
    {
        $professions = $this->professions()->get();
        $main = $professions->first(function ($p) {
            return (bool) $p->pivot->is_primary;
        }) ?: $professions->first();

        return $main ? (int) $main->category_id : null;
    }
}
