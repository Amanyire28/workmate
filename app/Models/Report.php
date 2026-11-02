<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A report or complaint from a customer or provider: about the other side,
 * or anything else that went wrong. The reported person is never told who
 * sent it, and it changes nothing until the safety team acts on it.
 * Changes go through App\Services\Reports.
 */
class Report extends Model
{
    public const SUBJECT_PROVIDER = 'provider';
    public const SUBJECT_CUSTOMER = 'customer';
    public const SUBJECT_GENERAL = 'general';
    public const SUBJECTS = [self::SUBJECT_PROVIDER, self::SUBJECT_CUSTOMER, self::SUBJECT_GENERAL];

    /** Reasons offered for each kind of report */
    public const REASONS = [
        self::SUBJECT_PROVIDER => ['scam', 'no_show', 'unsafe', 'abusive', 'fake', 'quality', 'offline', 'other'],
        self::SUBJECT_CUSTOMER => ['no_show', 'unsafe', 'abusive', 'not_paid', 'fake', 'other'],
        self::SUBJECT_GENERAL => ['payment', 'safety', 'app_problem', 'other'],
    ];

    public const HIGH_SEVERITY = ['scam', 'unsafe', 'fake', 'safety'];
    public const MEDIUM_SEVERITY = ['no_show', 'abusive', 'not_paid', 'payment'];

    public const STATUS_OPEN = 'open';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_INFO_REQUESTED = 'info_requested';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';
    public const OPEN_STATUSES = [self::STATUS_OPEN, self::STATUS_UNDER_REVIEW, self::STATUS_INFO_REQUESTED];

    public const OUTCOMES = ['no_violation', 'warned', 'provider_suspended', 'account_suspended', 'resolved'];

    public const MAX_ATTACHMENTS = 3;

    protected $guarded = ['*'];

    protected $casts = [
        'reporter_id' => 'integer',
        'provider_profile_id' => 'integer',
        'subject_user_id' => 'integer',
        'service_request_id' => 'integer',
        'conversation_id' => 'integer',
        'assigned_to' => 'integer',
        'resolved_by' => 'integer',
        'attachments' => 'array',
        'resolved_at' => 'datetime',
    ];

    // Storage paths stay on the server
    protected $hidden = ['attachments'];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id')->withTrashed();
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id')->withTrashed();
    }

    public function subjectUser()
    {
        return $this->belongsTo(User::class, 'subject_user_id')->withTrashed();
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class)->withTrashed();
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Attachment details without storage paths */
    public function publicAttachments(): array
    {
        return array_map(function ($file, $index) {
            return ['index' => $index, 'name' => $file['name'], 'type' => $file['mime'], 'size_kb' => max(1, (int) round($file['size'] / 1024))];
        }, $this->attachments ?: [], array_keys($this->attachments ?: []));
    }
}
