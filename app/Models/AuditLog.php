<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An append-only record of an important action. Write entries through
 * App\Services\AuditLogger so sensitive values are redacted.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'subject_id' => 'integer',
        'before' => 'array',
        'after' => 'array',
    ];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('Audit log entries cannot be changed.');
        });

        static::deleting(function () {
            throw new LogicException('Audit log entries cannot be deleted.');
        });
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
