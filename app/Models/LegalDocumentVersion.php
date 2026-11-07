<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One version of a legal document. Drafts can be edited; a published version
 * never changes, because people accepted exactly that text.
 */
class LegalDocumentVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $guarded = ['*'];

    protected $casts = [
        'legal_document_id' => 'integer',
        'is_material' => 'boolean',
        'created_by' => 'integer',
        'published_by' => 'integer',
        'published_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by')->withTrashed();
    }

    public function acceptances()
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
