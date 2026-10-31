<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Work a customer needs done: either a job posted for any provider to apply
 * to (provider_profile_id null), or a request sent to one provider.
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

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }

    public function isPostedJob(): bool
    {
        return $this->provider_profile_id === null;
    }

    /** Open jobs anyone can see and apply to */
    public function scopeOpenJobs($query)
    {
        return $query->whereNull('provider_profile_id')->where('status', self::STATUS_OPEN);
    }
}
