<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Grants and removes administrator roles. Every change is audited.
 */
class RoleAssignment
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /**
     * @param  User|null  $actor  null when run from the command line
     */
    public function grant(User $user, Role $role, ?User $actor, ?string $reason = null): void
    {
        $this->guardSelfChange($user, $actor);

        DB::transaction(function () use ($user, $role, $actor, $reason) {
            if ($user->roles()->whereKey($role->getKey())->exists()) {
                return;
            }

            $before = $this->roleKeys($user);
            $user->roles()->attach($role->getKey(), ['assigned_by' => $actor ? $actor->getKey() : null]);
            $user->flushPermissionCache();

            $this->audit->record('roles.granted', $user, ['roles' => $before], ['roles' => $this->roleKeys($user)], $reason, $actor);
        });
    }

    /**
     * @param  User|null  $actor  null when run from the command line
     */
    public function revoke(User $user, Role $role, ?User $actor, ?string $reason = null): void
    {
        $this->guardSelfChange($user, $actor);

        DB::transaction(function () use ($user, $role, $actor, $reason) {
            if (! $user->roles()->whereKey($role->getKey())->exists()) {
                return;
            }

            // Lock the role's holders so two removals can't both pass the check
            if ($role->key === Role::SUPER_ADMIN) {
                $activeHolders = $role->users()
                    ->where('users.status', User::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->count();

                if ($user->isActive() && $activeHolders <= 1) {
                    throw new BusinessRuleException(
                        'last_super_admin',
                        'This is the only active Super Administrator. Grant the role to someone else first.',
                        409
                    );
                }
            }

            $before = $this->roleKeys($user);
            $user->roles()->detach($role->getKey());
            $user->flushPermissionCache();

            $this->audit->record('roles.revoked', $user, ['roles' => $before], ['roles' => $this->roleKeys($user)], $reason, $actor);
        });
    }

    private function guardSelfChange(User $user, ?User $actor): void
    {
        if ($actor && $actor->is($user)) {
            throw new BusinessRuleException(
                'cannot_change_own_roles',
                'You cannot change your own roles. Ask another administrator.',
                403
            );
        }
    }

    /** @return array<int, string> */
    private function roleKeys(User $user): array
    {
        return $user->roles()->orderBy('key')->pluck('key')->all();
    }
}
