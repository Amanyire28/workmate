<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A provider's application for a service request, with an optional price. */
class Quotation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    // Someone else was hired
    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'message',
        'start_time',
        'amount',
    ];

    protected $casts = [
        'service_request_id' => 'integer',
        'provider_profile_id' => 'integer',
        'amount' => 'integer',
    ];

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id')->withTrashed();
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }
}
