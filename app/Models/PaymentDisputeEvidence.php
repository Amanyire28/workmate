<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A photo or PDF attached to a dispute message. Private: the file's path stays on the server and is never sent to any app. */
class PaymentDisputeEvidence extends Model
{
    public const DIRECTORY = 'dispute-evidence';
    public const MAX_PER_MESSAGE = 5;
    public const MAX_PER_DISPUTE = 20;
    public const MAX_KILOBYTES = 5120;

    protected $table = 'payment_dispute_evidence';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['path'];

    protected $casts = ['payment_dispute_id' => 'integer', 'message_id' => 'integer', 'uploaded_by_user_id' => 'integer', 'size' => 'integer', 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('Evidence is never changed.');
        });
        static::deleting(function () {
            throw new LogicException('Evidence is never deleted.');
        });
    }
}
