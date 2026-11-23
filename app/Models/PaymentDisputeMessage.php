<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Something said in a payment dispute, by the customer, the provider business or a WorkMate administrator. Never edited or removed. */
class PaymentDisputeMessage extends Model
{
    public const KIND_MESSAGE = 'message';
    public const KIND_INFO_REQUEST = 'info_request';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['payment_dispute_id' => 'integer', 'author_id' => 'integer', 'created_at' => 'datetime'];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('A dispute message is never changed.');
        });
        static::deleting(function () {
            throw new LogicException('A dispute message is never deleted.');
        });
    }

    public function attachments()
    {
        return $this->hasMany(PaymentDisputeEvidence::class, 'message_id')->orderBy('id');
    }
}
