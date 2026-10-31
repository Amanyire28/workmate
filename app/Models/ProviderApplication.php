<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderApplication extends Model
{
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_MORE_INFO_REQUIRED = 'more_info_required';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // Written only by App\Services\ProviderApplications
    protected $guarded = ['*'];

    protected $casts = [
        'provider_profile_id' => 'integer',
        'decided_by' => 'integer',
        'snapshot' => 'array',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function profile()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id')->withTrashed();
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
