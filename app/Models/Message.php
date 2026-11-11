<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'body',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'sender_id' => 'integer',
        'service_request_id' => 'integer',
        'read_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /** A request card points at the request itself */
    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class)->withTrashed();
    }

    public function attachments()
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('position');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }
}
