<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The SHADOW commission calculated for one customer-confirmed payment (Phase B4): an estimate of what WorkMate could
 * earn, "Estimated, not collected". It is not an invoice, a balance, a debt or revenue, and it is never shown to
 * providers or customers. One current row per payment report; a row is never edited or deleted (the model refuses): a
 * correction is a new revision that supersedes it. Created only by App\Services\CommissionShadow.
 */
class CommissionShadowSnapshot extends Model
{
    public const CALCULATED = 'calculated';
    public const EXCLUDED = 'excluded';
    public const MODE_SHADOW = 'shadow';

    /** Only these can move after a row is saved: it stops being the current one when a newer revision supersedes it */
    private const MOVABLE = ['current_slot', 'superseded_at', 'updated_at'];

    protected $guarded = ['*'];

    protected $casts = [
        'payment_report_id' => 'integer',
        'service_request_id' => 'integer',
        'provider_profile_id' => 'integer',
        'revision_no' => 'integer',
        'amount_ugx' => 'integer',
        'policy_id' => 'integer',
        'policy_version' => 'integer',
        'rate_bp' => 'integer',
        'commission_ugx' => 'integer',
        'provider_net_ugx' => 'integer',
        'engine_version' => 'integer',
        'category_id' => 'integer',
        'profession_id' => 'integer',
        'area_id' => 'integer',
        'current_slot' => 'integer',
        'basis_at' => 'datetime',
        'superseded_at' => 'datetime',
        'trace' => 'array',
        'supersedes_id' => 'integer',
    ];

    protected static function booted()
    {
        static::updating(function (CommissionShadowSnapshot $s) {
            foreach (array_keys($s->getDirty()) as $field) {
                if (! in_array($field, self::MOVABLE, true)) {
                    throw new LogicException("A commission estimate cannot be changed after it is saved ({$field}). A correction is a new revision.");
                }
            }
        });
        static::deleting(function () {
            throw new LogicException('Commission estimates are never deleted.');
        });
    }

    public function paymentReport()
    {
        return $this->belongsTo(JobPaymentReport::class, 'payment_report_id');
    }
}
