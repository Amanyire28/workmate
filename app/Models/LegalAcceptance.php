<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A person accepting one version of a legal document. Never changed or deleted. */
class LegalAcceptance extends Model
{
    public const METHOD_SIGN_UP = 'sign_up';

    public const METHOD_PROMPT = 'prompt';

    public const METHOD_PROVIDER_APPLICATION = 'provider_application';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'user_id' => 'integer',
        'legal_document_version_id' => 'integer',
        'accepted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function version()
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'legal_document_version_id');
    }
}
