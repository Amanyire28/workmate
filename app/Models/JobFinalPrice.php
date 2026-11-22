<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The final price of a job (Phase B3): a snapshot of the price the customer ACCEPTED in the agreement workflow,
 * never a number anyone types in. A later accepted amendment saves a new version and supersedes this one, so the
 * agreed amount of every version stays on record. Only the status fields move, through App\Services\FinalPrices.
 */
class JobFinalPrice extends Model
{
    public const CURRENT = 'current';
    public const SUPERSEDED = 'superseded';
    public const SOURCE_AGREEMENT = 'agreement';

    private const FROZEN = ['service_request_id', 'customer_id', 'provider_profile_id', 'agreement_id', 'revision_no', 'amount_ugx', 'source', 'created_by_user_id'];

    protected $guarded = ['*'];

    protected $casts = [
        'service_request_id' => 'integer',
        'customer_id' => 'integer',
        'provider_profile_id' => 'integer',
        'agreement_id' => 'integer',
        'revision_no' => 'integer',
        'amount_ugx' => 'integer',
        'current_slot' => 'integer',
        'created_by_user_id' => 'integer',
        'superseded_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function (JobFinalPrice $p) {
            foreach (self::FROZEN as $field) {
                if ($p->isDirty($field)) {
                    throw new LogicException("A final price cannot be changed after it is saved ({$field}).");
                }
            }
        });
        static::deleting(function () {
            throw new LogicException('Final prices are never deleted.');
        });
    }

    public function agreement()
    {
        return $this->belongsTo(JobPriceAgreement::class, 'agreement_id');
    }
}
