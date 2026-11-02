<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One published version of the documents a profession (category) needs.
 * Never changed once published; see App\Services\DocumentRequirements.
 */
class DocumentRequirementVersion extends Model
{
    protected $guarded = ['*'];

    protected $casts = [
        'category_id' => 'integer',
        'version' => 'integer',
        'documents' => 'array',
        'published_by' => 'integer',
        'published_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by')->withTrashed();
    }
}
