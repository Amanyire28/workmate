<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A photo or PDF sent in a chat. Private: only the two sides can open it. */
class MessageAttachment extends Model
{
    public const KIND_PHOTO = 'photo';
    public const KIND_FILE = 'file';

    public const PHOTO_DIRECTORY = 'chat';
    public const FILE_DIRECTORY = 'chat-files';

    // Per message
    public const MAX = 5;

    protected $guarded = ['*'];

    protected $casts = [
        'message_id' => 'integer',
        'conversation_id' => 'integer',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'position' => 'integer',
    ];

    // Storage paths stay on the server
    protected $hidden = ['path', 'thumb_path'];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function url(bool $thumb = false): string
    {
        $file = $this->kind === self::KIND_PHOTO ? $this->uuid.($thumb ? '-t' : '').'.jpg' : $this->uuid.'.pdf';

        return url('/api/v1/me/conversations/'.$this->conversation_id.'/attachments/'.$file);
    }

    /** What the API returns: links, never paths */
    public function present(): array
    {
        return [
            'id' => $this->uuid,
            'kind' => $this->kind,
            'url' => $this->url(),
            'thumb_url' => $this->kind === self::KIND_PHOTO ? $this->url(true) : null,
            'name' => $this->original_name,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
