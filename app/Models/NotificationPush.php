<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A push that is waiting, being sent or finished (Step 5). Written and moved
 * on only by App\Services\PushDelivery; dedupe_key is unique, so the same push
 * can't be made twice, and a row is claimed with one atomic update before
 * anything is sent.
 */
class NotificationPush extends Model
{
    public const TYPE_LIKES = 'like_group';
    public const TYPE_FOLLOWS = 'follow_group';
    public const TYPE_DIGEST = 'digest';
    /** An event pushed as it happened (a comment, a mention): its wording is in the payload until it is sent */
    public const TYPE_DIRECT = 'direct';

    public const PENDING = 'pending';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const SKIPPED = 'skipped';
    public const GAVE_UP = 'gave_up';

    protected $guarded = ['*'];

    protected $casts = [
        'user_id' => 'integer',
        'user_notification_id' => 'integer',
        'attempts' => 'integer',
        'silent' => 'boolean',
        'send_after' => 'datetime',
        'next_attempt_at' => 'datetime',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
