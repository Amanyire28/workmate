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

    /** What the provider says about price: a guide for the customer, never the price of the job */
    public const ESTIMATE_FIXED = 'fixed';
    public const ESTIMATE_RANGE = 'range';
    public const ESTIMATE_INSPECTION = 'inspection_required';
    public const ESTIMATE_TYPES = [self::ESTIMATE_FIXED, self::ESTIMATE_RANGE, self::ESTIMATE_INSPECTION];

    protected $fillable = [
        'message',
        'start_time',
        'amount',
        'amount_max',
        'estimate_type',
    ];

    protected $casts = [
        'service_request_id' => 'integer',
        'provider_profile_id' => 'integer',
        'amount' => 'integer',
        'amount_max' => 'integer',
        'profession_id' => 'integer',
    ];

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id')->withTrashed();
    }

    public function profession()
    {
        return $this->belongsTo(Profession::class);
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }
}
