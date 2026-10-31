<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        return array_key_exists($key, $overrides) ? $overrides[$key] : $this->definition($key)['default'];
    }

    public function canView(User $user, string $key): bool
    {
        return $user->hasPermission($this->group($key)['view_permission']) || $this->canChange($user, $key);
    }

    public function canChange(User $user, string $key): bool
    {
        return $user->hasPermission($this->group($key)['change_permission']);
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
        $before = $this->get($key);

        if ($before === $value) {
            return;
        }

        DB::transaction(function () use ($key, $value, $before, $actor, $reason) {
            $setting = PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'updated_by' => $actor->getKey()]
            );

            $this->audit->record('settings.updated', $setting, [$key => $before], [$key => $value], $reason, $actor);
        });

        Cache::forget(self::CACHE_KEY);
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
        return $this->definition($key)['type'] === 'integer' ? (int) $value : (bool) $value;
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
