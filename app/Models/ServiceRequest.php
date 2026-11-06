<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Work a customer needs done: either a job posted for any provider to apply
 * to (is_posted_job; provider_profile_id is set once someone is hired), or a
 * request sent to one provider.
 */
class ServiceRequest extends Model
{
    use SoftDeletes;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'category_id',
        'title',
        'description',
        'area_id',
        'area_name',
        'preferred_time',
        'workers_needed',
        'budget',
        'budget_basis',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'category_id' => 'integer',
        'provider_profile_id' => 'integer',
        'area_id' => 'integer',
        'workers_needed' => 'integer',
        'budget' => 'integer',
        'is_sample' => 'boolean',
        'is_posted_job' => 'boolean',
        'responded_at' => 'datetime',
        'review_prompted_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    /** The customer's rating of the provider, once the work is done */
    public function review()
    {
        return $this->hasOne(Review::class);
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }

    public function photos()
    {
        return $this->hasMany(ServiceRequestPhoto::class)->orderBy('position');
    }

    public function isPostedJob(): bool
    {
        return (bool) $this->is_posted_job;
    }

    /**
     * Photos of a job show the inside of someone's home, so only the people
     * involved see them: the customer; the provider the request went to (or
     * who was hired), and everyone in that business; for an open posted job,
     * approved providers who might apply, and those who already did; and
     * administrators who can see the marketplace.
     */
    public function photosVisibleTo(?User $user): bool
    {
        if (! $user || ! $user->isActive()) {
            return false;
        }
        if ($this->customer_id === (int) $user->getKey() || $user->can('marketplace.view')) {
            return true;
        }
        if ($this->isPostedJob() && $this->status === self::STATUS_OPEN && $this->provider_profile_id === null && $user->isProvider()) {
            return true;
        }
        $businesses = self::businessesOf($user);
        if ($this->provider_profile_id && $businesses->contains($this->provider_profile_id)) {
            return true;
        }

        return $this->isPostedJob() && $businesses->isNotEmpty()
            && $this->quotations()->whereIn('provider_profile_id', $businesses)->exists();
    }

    /** The provider businesses the user belongs to, looked up once per request (kept on the user object) */
    private static function businessesOf(User $user)
    {
        if (! $user->relationLoaded('memberBusinessIds')) {
            $user->setRelation('memberBusinessIds', ProviderMember::where('user_id', $user->getKey())->pluck('provider_profile_id'));
        }

        return $user->getRelation('memberBusinessIds');
    }

    /** Requests sent to a provider business the user belongs to */
    public function scopeReceivedBy($query, User $user)
    {
        return $query->whereNotNull('provider_profile_id')
            ->whereIn('provider_profile_id', ProviderMember::where('user_id', $user->getKey())->select('provider_profile_id'));
    }

    /** Open jobs anyone can see and apply to */
    public function scopeOpenJobs($query)
    {
        return $query->where('is_posted_job', true)->whereNull('provider_profile_id')->where('status', self::STATUS_OPEN);
    }
}
