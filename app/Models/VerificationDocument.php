<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One document a provider uploaded for verification. Its files are private. */
class VerificationDocument extends Model
{
    public const KIND_NATIONAL_ID = 'national_id';
    public const KIND_SUPPORTING = 'supporting';
    public const KIND_REQUESTED = 'requested';
    // One of the documents the provider's profession needs (requirement_key says which)
    public const KIND_PROFESSION = 'profession';
    public const KINDS = [self::KIND_NATIONAL_ID, self::KIND_SUPPORTING, self::KIND_REQUESTED, self::KIND_PROFESSION];

    public const STATUS_AWAITING_REVIEW = 'awaiting_review';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REPLACEMENT_REQUIRED = 'replacement_required';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_REMOVED = 'removed';

    protected $guarded = ['*'];

    protected $casts = [
        'document_verification_id' => 'integer',
        'document_request_id' => 'integer',
        'reviewed_by' => 'integer',
        'files' => 'array',
        'expires_on' => 'date',
        'reviewed_at' => 'datetime',
    ];

    // File paths stay on the server
    protected $hidden = ['files'];

    public function verification()
    {
        return $this->belongsTo(DocumentVerification::class, 'document_verification_id');
    }

    public function request()
    {
        return $this->belongsTo(DocumentRequest::class, 'document_request_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    /** File details without their storage paths */
    public function publicFiles(): array
    {
        return array_map(function ($file, $index) {
            return [
                'index' => $index,
                'side' => $file['side'] ?? null,
                'name' => $file['name'],
                'type' => $file['mime'],
                'size_kb' => max(1, (int) round($file['size'] / 1024)),
            ];
        }, $this->files ?: [], array_keys($this->files ?: []));
    }
}
