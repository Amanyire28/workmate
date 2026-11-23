<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A SHADOW-mode commission rate rule (Phase B4): "this percentage would apply to payments in this scope between these
 * dates". It is used only to estimate what WorkMate could earn; it charges nobody and creates no obligation. A saved
 * rule is never edited (the model refuses): it is replaced by a new version or retired, so earlier calculations that
 * used it stay exactly as they were. Changes go through App\Services\CommissionPolicies.
 */
class CommissionShadowPolicy extends Model
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_PROVIDER = 'provider';
    public const SCOPE_PROFESSION = 'profession';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_AREA = 'area';
    public const SCOPE_DISTRICT = 'district';
    public const SCOPES = [self::SCOPE_GLOBAL, self::SCOPE_PROVIDER, self::SCOPE_PROFESSION, self::SCOPE_CATEGORY, self::SCOPE_AREA, self::SCOPE_DISTRICT];

    /**
     * Which rule wins when several could apply, most specific first. A tier is only used when the job has what it needs
     * (a profession it was safely derived from, an area), and two rules in one tier is ambiguous, so none is chosen.
     */
    public const PRECEDENCE = [self::SCOPE_PROVIDER, self::SCOPE_PROFESSION, self::SCOPE_CATEGORY, self::SCOPE_AREA, self::SCOPE_DISTRICT, self::SCOPE_GLOBAL];

    public const ACTIVE = 'active';
    public const RETIRED = 'retired';

    /** Fields that can never change after a rule is saved: what it says, and who said it */
    private const FROZEN = [
        'scope_type', 'scope_ref', 'scope_key', 'scope_hash', 'version', 'rate_bp', 'effective_from', 'effective_to',
        'reason', 'created_by_user_id', 'content_hash', 'replaces_id',
    ];

    protected $guarded = ['*'];

    protected $casts = [
        'scope_ref' => 'integer',
        'version' => 'integer',
        'rate_bp' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'retired_at' => 'datetime',
        'retired_by_user_id' => 'integer',
        'replaces_id' => 'integer',
        'replaced_by_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    protected static function booted()
    {
        static::updating(function (CommissionShadowPolicy $p) {
            foreach (self::FROZEN as $field) {
                if ($p->isDirty($field)) {
                    throw new LogicException("A commission rule cannot be changed after it is saved ({$field}). Replace it with a new version instead.");
                }
            }
        });
        static::deleting(function () {
            throw new LogicException('Commission rules are never deleted.');
        });
    }

    /** Where the rule stops applying: its own end or the moment it was retired or replaced, whichever comes first */
    public function endsAt(): ?Carbon
    {
        $ends = array_filter([$this->effective_to, $this->retired_at]);
        if (! $ends) {
            return null;
        }

        return collect($ends)->sortBy(function ($t) {
            return $t->getTimestamp();
        })->first();
    }

    /** Whether the rule is in force at a moment: started, and not yet ended, retired or replaced */
    public function inForceAt(Carbon $at): bool
    {
        if ($this->effective_from->gt($at)) {
            return false;
        }
        $end = $this->endsAt();

        return $end === null || $at->lt($end);
    }

    /** scheduled | active | ended, as of now */
    public function state(?Carbon $now = null): string
    {
        $now = $now ?: now();
        if ($this->effective_from->gt($now)) {
            return $this->retired_at && $this->retired_at->lte($this->effective_from) ? 'ended' : 'scheduled';
        }

        return $this->inForceAt($now) ? 'active' : 'ended';
    }
}
