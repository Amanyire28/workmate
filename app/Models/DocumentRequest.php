<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A document an administrator asked a provider to add */
class DocumentRequest extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['*'];

    protected $casts = [
        'document_verification_id' => 'integer',
        'requested_by' => 'integer',
        'fulfilled_at' => 'datetime',
    ];

    public function verification()
    {
        return $this->belongsTo(DocumentVerification::class, 'document_verification_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }
}
