<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A profession an applicant typed under "Other", waiting for an administrator */
class ProfessionSuggestion extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ADDED = 'added';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_DECLINED = 'declined';

    // Written only by the provider application and suggestion services
    protected $guarded = ['*'];

    protected $casts = [
        'provider_profile_id' => 'integer',
        'category_id' => 'integer',
        'position' => 'integer',
        'years_experience' => 'integer',
        'profession_id' => 'integer',
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function profile()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id')->withTrashed();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function profession()
    {
        return $this->belongsTo(Profession::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
