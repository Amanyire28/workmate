<?php

namespace App\Services;

use App\Support\EmailRules;
use App\Exceptions\BusinessRuleException;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Platform settings defined in config/workmate.php, with administrator
 * changes stored in platform_settings. Unknown keys are always rejected.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings.overrides';

    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, config('workmate.settings', []));
    }

    public function definition(string $key): array
    {
        if (! $this->exists($key)) {
            throw new InvalidArgumentException("Unknown platform setting [{$key}].");
        }

        // Keys contain dots, so index the array rather than using config('a.b.c')
        return config('workmate.settings')[$key];
    }

    public function group(string $key): array
    {
        return config('workmate.setting_groups.'.$this->definition($key)['group']);
    }

    /** @return mixed */
    public function get(string $key)
    {
        $overrides = $this->overrides();
        if (! array_key_exists($key, $overrides)) {
            return $this->definition($key)['default'];
        }

        // A text setting cleared by an administrator is stored as "" (the column can't be empty)
        return $overrides[$key] === '' ? null : $overrides[$key];
    }

    public function canView(User $user, string $key): bool
    {
        return $user->hasPermission($this->group($key)['view_permission']) || $this->canChange($user, $key);
    }

    public function canChange(User $user, string $key): bool
    {
        return $user->hasPermission($this->group($key)['change_permission']);
    }

    /**
     * The settings the app itself applies, for everyone (no permission
     * needed): nearby search, the default place, support contact, provider
     * sign-ups, maintenance mode and presence timing.
     *
     * @return array<string, mixed>
     */
    public function publicValues(): array
    {
        $values = [];
        foreach (config('workmate.settings', []) as $key => $definition) {
            if (! empty($definition['public'])) {
                $values[$key] = $this->get($key);
            }
        }

        return $values;
    }

    /** Settings the user may see, with whether they may change each one. */
    public function visibleTo(User $user): array
    {
        $visible = [];

        foreach (array_keys(config('workmate.settings', [])) as $key) {
            if ($this->canView($user, $key)) {
                $visible[] = $this->present($key, $user);
            }
        }

        return $visible;
    }

    public function present(string $key, User $user): array
    {
        $definition = $this->definition($key);

        return [
            'key' => $key,
            'group' => $definition['group'],
            'label' => $definition['label'],
            'description' => $definition['description'],
            'type' => $definition['type'],
            'min' => $definition['min'] ?? null,
            'max' => $definition['max'] ?? null,
            'value' => $this->get($key),
            'default' => $definition['default'],
            'can_change' => $this->canChange($user, $key),
        ];
    }

    /** Validation rules for a new value of this setting. */
    public function rules(string $key): array
    {
        $definition = $this->definition($key);

        switch ($definition['type']) {
            case 'integer':
                return ['required', 'integer', 'min:'.$definition['min'], 'max:'.$definition['max']];
            case 'boolean':
                return ['required', 'boolean'];
            case 'string':
                return ['nullable', 'string', 'max:'.($definition['max'] ?? 255)];
            case 'email':
                return array_merge(['nullable'], EmailRules::new(120));
            case 'area':
                return ['required', 'string', Rule::exists('areas', 'slug')->where('is_active', true)];
            default:
                throw new InvalidArgumentException("Unsupported setting type [{$definition['type']}].");
        }
    }

    /**
     * Save a validated value and record the change in the audit log.
     *
     * @param  mixed  $value
     */
    public function set(string $key, $value, User $actor, ?string $reason = null): void
    {
        $value = $this->cast($key, $value);
        $this->assertOrdering($key, $value);
        $this->assertFinanceRules($key, $value);
        $before = $this->get($key);

        if ($before === $value) {
            return;
        }

        DB::transaction(function () use ($key, $value, $before, $actor, $reason) {
            $setting = PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value ?? '', 'updated_by' => $actor->getKey()]
            );

            $this->audit->record('settings.updated', $setting, [$key => $before], [$key => $value], $reason, $actor);
        });

        Cache::forget(self::CACHE_KEY);
    }

    /** The start moment as one unambiguous Kampala time (a time typed without an offset is Kampala time); anything unreadable is refused */
    private function kampalaMoment(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            $at = \Carbon\Carbon::parse($value, Scheduling::TIMEZONE);
        } catch (\Throwable $e) {
            throw new BusinessRuleException('setting_invalid', 'Choose a valid date and time.', 422);
        }
        if ($at->year < 2020 || $at->year > 2100) {
            throw new BusinessRuleException('setting_invalid', 'Choose a valid date and time.', 422);
        }

        return $at->setTimezone(Scheduling::TIMEZONE)->format('Y-m-d\TH:i:sP');
    }

    /**
     * The real dependencies between the financial switches, enforced here and never only in the screen. Recording obligations needs
     * flexible pricing (final prices), commission estimates (the rate applied) and a valid start moment; so those three cannot be
     * removed while obligations are on. Settlement does not depend on any of them: providers must still be able to pay what was
     * already recorded after new obligations are switched off. Everything else is independent.
     *
     * @param  mixed  $value
     */
    private function assertFinanceRules(string $key, $value): void
    {
        $assessing = (bool) $this->get('finance.commission_assessment_enabled');
        switch ($key) {
            case 'finance.commission_assessment_enabled':
                if ($value === true) {
                    if (! (bool) $this->get('finance.pricing_enabled')) {
                        throw new BusinessRuleException('setting_dependency', 'Turn on flexible pricing first.', 422);
                    }
                    if (! (bool) $this->get('finance.commission_shadow_enabled')) {
                        throw new BusinessRuleException('setting_dependency', 'Turn on commission estimates first.', 422);
                    }
                    if (! $this->get('finance.commission_assessment_from')) {
                        throw new BusinessRuleException('setting_dependency', 'Choose the start date and time first.', 422);
                    }
                }
                break;
            case 'finance.commission_assessment_from':
                if ($value === null && $assessing) {
                    throw new BusinessRuleException('setting_dependency', 'Turn off commission obligations before clearing the start date.', 422);
                }
                break;
            case 'finance.commission_shadow_enabled':
                if ($value === false && $assessing) {
                    throw new BusinessRuleException('setting_dependency', 'Turn off commission obligations first.', 422);
                }
                break;
            case 'finance.pricing_enabled':
                if ($value === false && $assessing) {
                    throw new BusinessRuleException('setting_dependency', 'Turn off commission obligations first.', 422);
                }
                break;
        }
    }

    /** @return array<string, mixed> */
    private function overrides(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return PlatformSetting::query()->pluck('value', 'key')->all();
        });
    }

    /** @param mixed $value */
    private function cast(string $key, $value)
    {
        switch ($this->definition($key)['type']) {
            case 'integer':
                return (int) $value;
            case 'boolean':
                return (bool) $value;
            default:
                // Text: trimmed, with an empty value meaning "not set"
                $value = trim((string) $value);
                $value = $value === '' ? null : $value;

                return $key === 'finance.commission_assessment_from' ? $this->kampalaMoment($value) : $value;
        }
    }

    /**
     * Paired settings must stay in order, for example the nearby radius
     * below the wider search radius.
     *
     * @param  mixed  $value
     */
    private function assertOrdering(string $key, $value): void
    {
        foreach (config('workmate.settings') as $lowerKey => $definition) {
            $upperKey = $definition['below'] ?? null;

            if (! $upperKey || ! in_array($key, [$lowerKey, $upperKey], true)) {
                continue;
            }

            $lower = $lowerKey === $key ? $value : $this->get($lowerKey);
            $upper = $upperKey === $key ? $value : $this->get($upperKey);

            if ($lower >= $upper) {
                throw new BusinessRuleException(
                    'setting_out_of_order',
                    sprintf('%s must be less than %s.', $definition['label'], $this->definition($upperKey)['label'])
                );
            }
        }
    }
}
