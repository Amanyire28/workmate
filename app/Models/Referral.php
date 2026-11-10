<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One account invited another. Changes go through App\Services\Referrals;
 * nothing here is ever set from a request.
 */
class Referral extends Model
{
    /** Signed up through the link; their email isn't confirmed yet */
    public const STATUS_REGISTERED = 'registered';
    /** Confirmed, but something looked unusual: a person decides */
    public const STATUS_REVIEW = 'review';
    /** Counts towards the inviter's badges */
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_REJECTED = 'rejected';
    /** Counted once, then taken back after a review */
    public const STATUS_REVOKED = 'revoked';

    protected $guarded = ['*'];

    protected $casts = [
        'referrer_id' => 'integer',
        'referred_id' => 'integer',
        'coins' => 'integer',
        'risk_flags' => 'array',
        'link_opened_at' => 'datetime',
        'qualified_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
