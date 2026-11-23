<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Area;
use App\Models\Category;
use App\Models\CommissionShadowPolicy;
use App\Models\Profession;
use App\Models\ProviderProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase B4: the administrators' commission rate rules, for SHADOW mode only. A rule estimates what WorkMate could earn;
 * it charges nobody, creates no debt and can't be made enforceable by any setting. Creating, replacing or retiring a
 * rule needs commission.manage and is written to the audit log with who, when, why and before/after.
 *
 * Safeguards:
 *  - the rate is an integer number of basis points (500 = 5%), never a float, between 0 and a ceiling set in Settings
 *    (finance.commission_max_bp, 20% unless changed): a ceiling is a limit, never a default rate;
 *  - a rule can't start in the past, so it can never change how an earlier payment was judged;
 *  - two rules for the same scope can't overlap in time, so choosing one is never ambiguous;
 *  - activating one needs a PREVIEW first (a signed, short-lived token for exactly that rule), the rate typed again as
 *    confirmation, and a reason: a rate is never set by a stray click;
 *  - saving rules is serialised (one at a time), so two administrators can't create overlapping rules at once;
 *  - a saved rule is never edited or deleted: replacing saves version + 1 and ends the old one the moment the new one
 *    starts; retiring ends it from now. Calculations already made keep the rate they were made with.
 */
class CommissionPolicies
{
    /** Amounts shown in a preview, to make a rate concrete */
    public const EXAMPLES = [50000, 200000, 1000000];
    public const TOKEN_MINUTES = 30;
    private const LOCK = 'wm_commission_policies';

    /** @var PlatformSettings */
    private $settings;

    /** @var AuditLogger */
    private $audit;

    public function __construct(PlatformSettings $settings, AuditLogger $audit)
    {
        $this->settings = $settings;
        $this->audit = $audit;
    }

    public function ceiling(): int
    {
        return (int) $this->settings->get('finance.commission_max_bp');
    }

    /** What the screen offers to choose from */
    public function options(): array
    {
        return [
            'ceiling_bp' => $this->ceiling(),
            'precedence' => CommissionShadowPolicy::PRECEDENCE,
            'categories' => Category::orderBy('name')->get(['id', 'name', 'slug'])->all(),
            'professions' => Profession::orderBy('name')->get(['id', 'name', 'slug'])->all(),
            'areas' => Area::where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug', 'town', 'district'])->all(),
            'districts' => $this->districts(),
        ];
    }

    /** Every rule and every version of it, most specific scope first */
    public function list(): array
    {
        $rank = array_flip(CommissionShadowPolicy::PRECEDENCE);
        $names = User::whereIn('id', CommissionShadowPolicy::pluck('created_by_user_id')->unique())->pluck('name', 'id');

        return CommissionShadowPolicy::orderBy('scope_hash')->orderByDesc('version')->get()
            ->sortBy(function (CommissionShadowPolicy $p) use ($rank) {
                return sprintf('%02d-%s-%05d', $rank[$p->scope_type] ?? 99, $p->scope_hash, 99999 - $p->version);
            })->map(function (CommissionShadowPolicy $p) use ($names) {
                return $this->present($p) + ['created_by' => $names[$p->created_by_user_id] ?? null];
            })->values()->all();
    }

    public function present(CommissionShadowPolicy $p): array
    {
        return [
            'id' => $p->id,
            'scope_type' => $p->scope_type,
            'scope_ref' => $p->scope_ref,
            'scope_key' => $p->scope_key,
            'scope' => $this->label($p->scope_type, $p->scope_ref, $p->scope_key),
            'version' => $p->version,
            'rate_bp' => $p->rate_bp,
            'percent' => self::percent($p->rate_bp),
            'effective_from' => $p->effective_from->toIso8601String(),
            'effective_to' => optional($p->effective_to)->toIso8601String(),
            'state' => $p->state(),
            'status' => $p->status,
            'retired_at' => optional($p->retired_at)->toIso8601String(),
            'retired_reason' => $p->retired_reason,
            'reason' => $p->reason,
            'replaces_id' => $p->replaces_id,
            'replaced_by_id' => $p->replaced_by_id,
        ];
    }

    /** "5%" or "2.5%", from basis points, without any floating point */
    public static function percent(int $bp): string
    {
        $whole = intdiv($bp, 100);
        $rest = $bp % 100;

        return $rest === 0 ? $whole.'%' : $whole.'.'.rtrim(sprintf('%02d', $rest), '0').'%';
    }

    /* ---------------- Preview ---------------- */

    /**
     * Checks a proposed rule and shows what it would mean, without saving anything. A valid rule with no clash gets a
     * signed token (good for a few minutes, for this administrator and exactly this rule) that saving it requires.
     *
     * @param  array  $input  scope_type, scope_ref or scope_key, rate_bp, effective_from, effective_to
     */
    public function simulate(User $admin, array $input, ?int $replacesId = null): array
    {
        $replaces = $replacesId ? $this->replaceable($replacesId) : null;
        $content = $this->normalise($input, $replaces);
        $conflicts = $this->conflicts($content, $replaces);

        $out = [
            'valid' => $conflicts === [],
            'scope' => $this->label($content['scope_type'], $content['scope_ref'], $content['scope_key']),
            'scope_type' => $content['scope_type'],
            'rate_bp' => $content['rate_bp'],
            'percent' => self::percent($content['rate_bp']),
            'effective_from' => $content['effective_from']->toIso8601String(),
            'effective_to' => optional($content['effective_to'])->toIso8601String(),
            'replaces' => $replaces ? $this->present($replaces) : null,
            'conflicts' => $conflicts,
            'examples' => array_map(function ($amount) use ($content) {
                $commission = CommissionShadow::commission($amount, $content['rate_bp']);

                return ['amount_ugx' => $amount, 'commission_ugx' => $commission, 'provider_remainder_ugx' => $amount - $commission];
            }, self::EXAMPLES),
            'label' => CommissionShadow::LABEL,
            'note' => 'Shadow mode: this only estimates what WorkMate could earn. Nothing is charged and nobody owes anything.',
            'token' => null,
            'expires_at' => null,
        ];
        if ($out['valid']) {
            $expires = now()->addMinutes(self::TOKEN_MINUTES)->getTimestamp();
            $out['token'] = $this->sign($content, $admin, $replaces ? $replaces->id : null, $expires);
            $out['expires_at'] = Carbon::createFromTimestamp($expires)->toIso8601String();
        }

        return $out;
    }

    /* ---------------- Saving ---------------- */

    /** Activates a new rule: needs the preview's token, the rate typed again, and a reason */
    public function create(User $admin, array $input, ?string $token, $confirmBp, ?string $reason): CommissionShadowPolicy
    {
        $reason = $this->reason($reason);

        return $this->serialised(function () use ($admin, $input, $token, $confirmBp, $reason) {
            $content = $this->normalise($input, null);
            $this->guardSave($content, null, $admin, $token, $confirmBp);

            return DB::transaction(function () use ($admin, $content, $reason) {
                $policy = $this->insert($content, $admin, $reason, null);
                $this->audit->record('commission.shadow_policy_created', $policy, [], $this->audited($policy), $reason, $admin);

                return $policy;
            });
        });
    }

    /** A new version of a rule: it starts at the given moment and the old version ends at that moment */
    public function replace(User $admin, int $id, array $input, ?string $token, $confirmBp, ?string $reason): CommissionShadowPolicy
    {
        $reason = $this->reason($reason);

        return $this->serialised(function () use ($admin, $id, $input, $token, $confirmBp, $reason) {
            return DB::transaction(function () use ($admin, $id, $input, $token, $confirmBp, $reason) {
                $old = $this->replaceable($id, true);
                $content = $this->normalise($input, $old);
                $this->guardSave($content, $old, $admin, $token, $confirmBp);

                $before = $this->audited($old);
                $new = $this->insert($content, $admin, $reason, $old);
                $end = $old->endsAt();
                $old->forceFill([
                    'status' => CommissionShadowPolicy::RETIRED,
                    // It stops applying the moment the new version starts (never later than it already would have)
                    'retired_at' => $end && $end->lt($content['effective_from']) ? $end : $content['effective_from'],
                    'retired_reason' => 'Replaced by version '.$new->version.': '.$reason,
                    'retired_by_user_id' => $admin->getKey(),
                    'replaced_by_id' => $new->id,
                ])->save();

                $this->audit->record('commission.shadow_policy_replaced', $new, $before, $this->audited($new) + ['replaces' => $old->id], $reason, $admin);

                return $new;
            });
        });
    }

    /** Ends a rule from now on (it never applied before it started, and payments already judged keep their rate) */
    public function retire(User $admin, int $id, ?string $reason): CommissionShadowPolicy
    {
        $reason = $this->reason($reason);

        return $this->serialised(function () use ($admin, $id, $reason) {
            return DB::transaction(function () use ($admin, $id, $reason) {
                $policy = CommissionShadowPolicy::whereKey($id)->lockForUpdate()->first();
                if (! $policy) {
                    throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
                }
                if ($policy->status === CommissionShadowPolicy::RETIRED) {
                    throw new BusinessRuleException('already_retired', 'This rule has already ended.', 409);
                }

                $before = $this->audited($policy);
                $policy->forceFill(['status' => CommissionShadowPolicy::RETIRED, 'retired_at' => now(), 'retired_reason' => $reason, 'retired_by_user_id' => $admin->getKey()])->save();
                $this->audit->record('commission.shadow_policy_retired', $policy, $before, $this->audited($policy), $reason, $admin);

                return $policy;
            });
        });
    }

    /* ---------------- Internals ---------------- */

    /** A rule that can still be replaced: it exists and hasn't been retired or replaced already */
    private function replaceable(int $id, bool $lock = false): CommissionShadowPolicy
    {
        $query = CommissionShadowPolicy::whereKey($id);
        $policy = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $policy) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        if ($policy->status === CommissionShadowPolicy::RETIRED || $policy->replaced_by_id) {
            throw new BusinessRuleException('already_retired', 'This rule has already ended or been replaced.', 409);
        }

        return $policy;
    }

    /** The checks every save repeats, even though a preview was seen: nothing is trusted from the screen */
    private function guardSave(array $content, ?CommissionShadowPolicy $replaces, User $admin, ?string $token, $confirmBp): void
    {
        $conflicts = $this->conflicts($content, $replaces);
        if ($conflicts) {
            throw new BusinessRuleException('policy_overlap', 'Another rate rule already covers part of that period for the same scope (rule '.$conflicts[0]['id'].'). Two rules can’t overlap.', 409);
        }
        if (! $this->tokenValid($token, $content, $admin, $replaces ? $replaces->id : null)) {
            throw new BusinessRuleException('preview_required', 'Preview this rule first. The preview is only valid for a few minutes and for exactly this rule.', 422);
        }
        if (! is_scalar($confirmBp) || ! ctype_digit((string) $confirmBp) || (int) $confirmBp !== $content['rate_bp']) {
            throw new BusinessRuleException('confirmation_mismatch', 'Type the rate again to confirm it: it doesn’t match the rule.', 422);
        }
    }

    private function insert(array $content, User $admin, string $reason, ?CommissionShadowPolicy $replaces): CommissionShadowPolicy
    {
        $policy = new CommissionShadowPolicy();
        $policy->forceFill([
            'scope_type' => $content['scope_type'],
            'scope_ref' => $content['scope_ref'],
            'scope_key' => $content['scope_key'],
            'scope_hash' => $content['scope_hash'],
            'version' => (int) CommissionShadowPolicy::where('scope_hash', $content['scope_hash'])->max('version') + 1,
            'rate_bp' => $content['rate_bp'],
            'effective_from' => $content['effective_from'],
            'effective_to' => $content['effective_to'],
            'status' => CommissionShadowPolicy::ACTIVE,
            'replaces_id' => $replaces ? $replaces->id : null,
            'reason' => $reason,
            'created_by_user_id' => $admin->getKey(),
            'content_hash' => hash('sha256', json_encode($this->tokenContent($content, $replaces ? $replaces->id : null))),
        ])->save();

        return $policy;
    }

    /**
     * A proposed rule as clean values, or a reason it can't be: the scope must exist, the rate be whole basis points
     * from 0 up to the ceiling, and the dates make sense and not lie in the past.
     */
    private function normalise(array $input, ?CommissionShadowPolicy $replaces): array
    {
        $type = $replaces ? $replaces->scope_type : ($input['scope_type'] ?? null);
        if (! in_array($type, CommissionShadowPolicy::SCOPES, true)) {
            throw new BusinessRuleException('scope_invalid', 'Choose what the rate applies to.', 422);
        }
        $ref = null;
        $key = null;
        if ($replaces) {
            $ref = $replaces->scope_ref;
            $key = $replaces->scope_key;
        } else {
            $ref = $this->scopeRef($type, $input);
            $key = $type === CommissionShadowPolicy::SCOPE_DISTRICT ? $this->districtKey($input['scope_key'] ?? null) : null;
        }

        $rate = $input['rate_bp'] ?? null;
        if (is_bool($rate) || is_float($rate) || $rate === null || ! ctype_digit((string) $rate)) {
            throw new BusinessRuleException('rate_invalid', 'Enter the rate as a whole number of basis points (500 means 5%).', 422);
        }
        $rate = (int) $rate;
        if ($rate > $this->ceiling()) {
            throw new BusinessRuleException('rate_above_ceiling', 'That rate is above the safety ceiling of '.self::percent($this->ceiling()).'.', 422);
        }

        $from = $this->moment($input['effective_from'] ?? null, 'effective_from', 'Choose when the rate starts.');
        if ($from->lt(now()->subMinute())) {
            throw new BusinessRuleException('effective_in_past', 'A rate can’t start in the past: that would change how earlier payments are judged.', 422);
        }
        $to = ($input['effective_to'] ?? null) === null || $input['effective_to'] === '' ? null : $this->moment($input['effective_to'], 'effective_to', 'Choose when the rate ends.');
        if ($to && $to->lte($from)) {
            throw new BusinessRuleException('dates_invalid', 'The end must be after the start.', 422);
        }

        return [
            'scope_type' => $type, 'scope_ref' => $ref, 'scope_key' => $key,
            'scope_hash' => $type === CommissionShadowPolicy::SCOPE_GLOBAL ? 'global' : ($type === CommissionShadowPolicy::SCOPE_DISTRICT ? 'district:'.$key : $type.':'.$ref),
            'rate_bp' => $rate, 'effective_from' => $from, 'effective_to' => $to,
        ];
    }

    /** The id a scope points at, which has to exist */
    private function scopeRef(string $type, array $input): ?int
    {
        if ($type === CommissionShadowPolicy::SCOPE_GLOBAL || $type === CommissionShadowPolicy::SCOPE_DISTRICT) {
            return null;
        }
        $id = $input['scope_ref'] ?? null;
        $models = [
            CommissionShadowPolicy::SCOPE_PROVIDER => ProviderProfile::class,
            CommissionShadowPolicy::SCOPE_PROFESSION => Profession::class,
            CommissionShadowPolicy::SCOPE_CATEGORY => Category::class,
            CommissionShadowPolicy::SCOPE_AREA => Area::class,
        ];
        if ($id === null || is_bool($id) || ! ctype_digit((string) $id) || ! $models[$type]::whereKey((int) $id)->exists()) {
            throw new BusinessRuleException('scope_invalid', 'Choose an existing '.$type.' for the rate to apply to.', 422);
        }

        return (int) $id;
    }

    private function districtKey($value): string
    {
        $key = mb_strtolower(trim((string) $value));
        if ($key === '' || ! in_array($key, $this->districts(true), true)) {
            throw new BusinessRuleException('scope_invalid', 'Choose one of the districts WorkMate knows.', 422);
        }

        return $key;
    }

    /** The districts the places belong to (as names, or as the lower-case keys rules use) */
    private function districts(bool $keys = false): array
    {
        $names = Area::whereNotNull('district')->where('district', '!=', '')->distinct()->orderBy('district')->pluck('district');
        $map = [];
        foreach ($names as $name) {
            $map[mb_strtolower(trim($name))] = trim($name);
        }
        ksort($map);

        return $keys ? array_keys($map) : array_values($map);
    }

    private function moment($value, string $field, string $message): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            throw new BusinessRuleException($field.'_invalid', $message, 422);
        }
        try {
            // Kept in the application's own time zone, whatever offset the screen sent, so it is stored and compared correctly
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Exception $e) {
            throw new BusinessRuleException($field.'_invalid', $message, 422);
        }
    }

    /**
     * Rules for the same scope that would be in force at the same time as this one. Ending at the very moment another
     * starts is fine; a replaced rule is ignored (it ends when its replacement starts).
     */
    private function conflicts(array $content, ?CommissionShadowPolicy $replaces): array
    {
        $out = [];
        foreach (CommissionShadowPolicy::where('scope_hash', $content['scope_hash'])->get() as $other) {
            if ($replaces && $other->id === $replaces->id) {
                continue;
            }
            $otherEnd = $other->endsAt();
            $startsBeforeEnd = $content['effective_to'] === null || $other->effective_from->lt($content['effective_to']);
            $endsAfterStart = $otherEnd === null || $otherEnd->gt($content['effective_from']);
            if ($startsBeforeEnd && $endsAfterStart) {
                $out[] = ['id' => $other->id, 'version' => $other->version, 'rate_bp' => $other->rate_bp, 'effective_from' => $other->effective_from->toIso8601String(), 'ends_at' => optional($otherEnd)->toIso8601String()];
            }
        }

        return $out;
    }

    public function label(string $type, ?int $ref, ?string $key): string
    {
        switch ($type) {
            case CommissionShadowPolicy::SCOPE_PROVIDER:
                return 'Provider: '.(optional(ProviderProfile::withTrashed()->find($ref))->display_name ?: '#'.$ref);
            case CommissionShadowPolicy::SCOPE_PROFESSION:
                return 'Profession: '.(optional(Profession::find($ref))->name ?: '#'.$ref);
            case CommissionShadowPolicy::SCOPE_CATEGORY:
                return 'Category: '.(optional(Category::find($ref))->name ?: '#'.$ref);
            case CommissionShadowPolicy::SCOPE_AREA:
                return 'Area: '.(optional(Area::find($ref))->name ?: '#'.$ref);
            case CommissionShadowPolicy::SCOPE_DISTRICT:
                return 'District: '.($this->districtName($key) ?: $key);
            default:
                return 'Everything (default)';
        }
    }

    private function districtName(?string $key): ?string
    {
        foreach ($this->districts() as $name) {
            if (mb_strtolower($name) === $key) {
                return $name;
            }
        }

        return null;
    }

    private function reason(?string $reason): string
    {
        $reason = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $reason)), 0, 300);
        if (mb_strlen($reason) < 5) {
            throw new BusinessRuleException('reason_required', 'Say why (a few words): it is recorded in the audit log.', 422);
        }

        return $reason;
    }

    private function audited(CommissionShadowPolicy $p): array
    {
        return [
            'scope' => $p->scope_hash, 'version' => $p->version, 'rate_bp' => $p->rate_bp, 'effective_from' => $p->effective_from->toIso8601String(),
            'effective_to' => optional($p->effective_to)->toIso8601String(), 'status' => $p->status, 'retired_at' => optional($p->retired_at)->toIso8601String(),
        ];
    }

    /** What a preview token is signed over: exactly the rule being saved, and who may save it */
    private function tokenContent(array $c, ?int $replacesId): array
    {
        return [$c['scope_hash'], $c['rate_bp'], $c['effective_from']->toIso8601String(), $c['effective_to'] ? $c['effective_to']->toIso8601String() : null, $replacesId];
    }

    private function sign(array $content, User $admin, ?int $replacesId, int $expires): string
    {
        $payload = json_encode(array_merge($this->tokenContent($content, $replacesId), [$admin->getKey(), $expires]));

        return $expires.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function tokenValid(?string $token, array $content, User $admin, ?int $replacesId): bool
    {
        // Judged on the application's own clock, the same one that set the expiry
        if (! is_string($token) || ! preg_match('/^(\d{9,12})\.([0-9a-f]{64})$/', $token, $m) || (int) $m[1] < now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->sign($content, $admin, $replacesId, (int) $m[1]), $token);
    }

    /** One change at a time, so two administrators can't create overlapping rules at the same moment */
    private function serialised(callable $work)
    {
        $got = DB::selectOne('SELECT GET_LOCK(?, 10) AS got', [self::LOCK]);
        if (! $got || (int) $got->got !== 1) {
            throw new BusinessRuleException('busy', 'Another rate change is being saved. Try again in a moment.', 409);
        }
        try {
            return $work();
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }
}
