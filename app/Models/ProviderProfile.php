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
        'availability_updated_at' => 'datetime',
        'accepts_immediate' => 'boolean',
        'accepts_bookings' => 'boolean',
    ];

    public const AVAILABILITY_STATUSES = ['available', 'busy', 'unavailable'];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    /** "Recent work" on their profile */
    public function workItems()
    {
        return $this->hasMany(ProviderWorkItem::class);
    }

    /** "Services & Prices" */
    public function services()
    {
        return $this->hasMany(ProviderService::class);
    }

    /** Requests sent to them, and posted jobs they were hired for */
    public function receivedRequests()
    {
        return $this->hasMany(ServiceRequest::class);
    }

    /** Working on a confirmed job right now (started, or within its booked time) */
    public static function busyNowQuery($query)
    {
        $now = now();

        return $query->where(function ($q) use ($now) {
            $q->where('status', ServiceRequest::STATUS_IN_PROGRESS)
                ->orWhere(function ($q) use ($now) {
                    $q->where('status', ServiceRequest::STATUS_SCHEDULED)
                        ->where('scheduled_start_at', '<=', $now)->where('scheduled_end_at', '>', $now);
                });
        });
    }

    /**
     * What customers are told:
     *   available  "Available now": takes immediate work and isn't on a job
     *   busy       "Busy · Accepting bookings": on a job now (or not taking
     *              immediate work) but takes bookings for later
     *   paused     "Bookings paused": not taking new requests
     * Uses busy_now when the listing loaded it (withExists), else asks.
     */
    public function workStatus(): string
    {
        if (! $this->accepts_immediate && ! $this->accepts_bookings) {
            return 'paused';
        }
        $busyNow = array_key_exists('busy_now', $this->attributes)
            ? (bool) $this->attributes['busy_now']
            : self::busyNowQuery($this->receivedRequests())->exists();
        if ($busyNow || ! $this->accepts_immediate) {
            return $this->accepts_bookings ? 'busy' : 'paused';
        }

        return 'available';
    }

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
