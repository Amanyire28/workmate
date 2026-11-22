<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One immutable version of a price proposal for a request (Phase B2). The amount, scope and exclusions never
 * change once saved; a revised price is a new version. Only the decision fields move, and only through
 * App\Services\PriceAgreements, which enforces who may do what and when.
 */
class JobPriceAgreement extends Model
{
    public const PENDING_CUSTOMER = 'pending_customer';
    public const REVISION_REQUESTED = 'revision_requested';
    public const AGREED = 'agreed';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';
    public const SUPERSEDED = 'superseded';

    /** A version still being talked about (at most one per request) */
    public const OPEN_STATUSES = [self::PENDING_CUSTOMER, self::REVISION_REQUESTED];

    /** Fields that can never change after the version is saved: the price and what it covers */
    private const FROZEN = [
        'service_request_id', 'provider_profile_id', 'revision_no', 'amount_ugx', 'scope_text', 'exclusions_text',
        'is_amendment', 'after_inspection', 'proposed_by_user_id', 'proposed_at', 'supersedes_id',
        'category_id', 'area_id', 'area_name', 'profession_id', 'profession_source', 'profession_review_needed', 'client_token',
    ];

    protected $guarded = ['*'];

    protected $casts = [
        'service_request_id' => 'integer',
        'provider_profile_id' => 'integer',
        'revision_no' => 'integer',
        'amount_ugx' => 'integer',
        'suggested_amount_ugx' => 'integer',
        'is_amendment' => 'boolean',
        'after_inspection' => 'boolean',
        'profession_review_needed' => 'boolean',
        'proposed_by_user_id' => 'integer',
        'responded_by_user_id' => 'integer',
        'proposed_at' => 'datetime',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
        'open_slot' => 'integer',
        'agreed_slot' => 'integer',
    ];

    protected static function booted()
    {
        static::updating(function (JobPriceAgreement $a) {
            foreach (self::FROZEN as $field) {
                if ($a->isDirty($field)) {
                    throw new LogicException("A price version cannot be changed after it is saved ({$field}). Propose a new version instead.");
                }
            }
        });
        static::deleting(function () {
            throw new LogicException('Price versions are never deleted.');
        });
    }

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id')->withTrashed();
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** A proposal waiting for the customer for longer than its time allowed */
    public function isDue(): bool
    {
        return $this->status === self::PENDING_CUSTOMER && $this->expires_at !== null && $this->expires_at->isPast();
    }
}
