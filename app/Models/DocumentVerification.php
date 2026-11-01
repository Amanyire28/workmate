<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A provider's document check. Status changes go through
 * App\Services\DocumentVerifications.
 */
class DocumentVerification extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_INFO_REQUESTED = 'info_requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** The provider may add or change documents */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_INFO_REQUESTED, self::STATUS_REJECTED];

    /** Waiting on an administrator or the provider */
    public const OPEN_STATUSES = [self::STATUS_PENDING_REVIEW, self::STATUS_UNDER_REVIEW, self::STATUS_INFO_REQUESTED];

    public const MAX_SUPPORTING = 5;

    protected $guarded = ['*'];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $casts = [
        'provider_profile_id' => 'integer',
        'reviewer_id' => 'integer',
        'decided_by' => 'integer',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function profile()
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id')->withTrashed();
    }

    public function documents()
    {
        return $this->hasMany(VerificationDocument::class);
    }

    /** Documents that count: not replaced or removed */
    public function currentDocuments()
    {
        return $this->documents()->whereNotIn('status', [VerificationDocument::STATUS_REPLACED, VerificationDocument::STATUS_REMOVED]);
    }

    public function requests()
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id')->withTrashed();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }
}
