<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A legal document (Terms, Privacy Policy…) with its versions. Audience is
 * "everyone" or "providers"; see App\Services\LegalDocuments.
 */
class LegalDocument extends Model
{
    public const AUDIENCE_EVERYONE = 'everyone';

    public const AUDIENCE_PROVIDERS = 'providers';

    protected $guarded = ['*'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function versions()
    {
        return $this->hasMany(LegalDocumentVersion::class);
    }

    public function publishedVersions()
    {
        return $this->versions()->where('status', LegalDocumentVersion::STATUS_PUBLISHED);
    }
}
