<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase B9: who may see a provider business's money (earnings, commission, statements). One rule, used by every finance endpoint:
 *
 *   - the business OWNER always can;
 *   - any other member (a manager or an ordinary team member) can only when the owner switched `can_view_finances` on for them;
 *   - everyone else, customers and other businesses included, cannot, and a record of another business is "not found".
 *
 * Historical read access does NOT depend on any financial feature switch; the switches only stop new financial activity.
 */
class ProviderFinanceAccess
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /** The provider businesses whose finances this user may see (owner of it, or given access by its owner) */
    public function providerIds(User $user): array
    {
        return ProviderMember::where('user_id', $user->getKey())->where(function ($q) {
            $q->where('role', ProviderMember::ROLE_OWNER)->orWhere('can_view_finances', true);
        })->orderByRaw("role = 'owner' desc")->orderBy('provider_profile_id')->pluck('provider_profile_id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    /** The one business a request is about: the first this user may see (owned ones first); null when none */
    public function providerId(User $user): ?int
    {
        $ids = $this->providerIds($user);

        return $ids ? $ids[0] : null;
    }

    /** As providerId(), or the same refusal every finance endpoint gives */
    public function requireProviderId(User $user): int
    {
        $id = $this->providerId($user);
        if (! $id) {
            throw new BusinessRuleException('finance_access_denied', 'You don’t have access to this business’s finances. Ask the business owner to give you access.', 403);
        }

        return $id;
    }

    public function roleIn(User $user, int $providerId): ?string
    {
        return ProviderMember::where('user_id', $user->getKey())->where('provider_profile_id', $providerId)->value('role');
    }

    public function isOwner(User $user, int $providerId): bool
    {
        return $this->roleIn($user, $providerId) === ProviderMember::ROLE_OWNER;
    }

    /** What the My Finances screen needs to know about the person asking */
    public function describe(User $user): array
    {
        $id = $this->providerId($user);
        $role = $id ? $this->roleIn($user, $id) : null;
        $out = ['allowed' => (bool) $id, 'provider_id' => $id, 'role' => $role, 'can_manage_access' => $role === ProviderMember::ROLE_OWNER, 'members' => []];
        if ($id) {
            $profile = ProviderProfile::withTrashed()->find($id);
            $out['business'] = $profile ? $profile->display_name : null;
        }
        if ($out['can_manage_access']) {
            $out['members'] = ProviderMember::where('provider_profile_id', $id)->where('role', '!=', ProviderMember::ROLE_OWNER)->with('user:id,name')->orderBy('id')->get()->map(function ($m) {
                return ['user_id' => (int) $m->user_id, 'name' => optional($m->user)->name, 'role' => $m->role, 'can_view_finances' => (bool) $m->can_view_finances];
            })->all();
        }

        return $out;
    }

    /** The owner gives or takes back a team member's access to the business's finances. Audited. */
    public function setAccess(User $owner, int $memberUserId, bool $allowed): array
    {
        $providerId = $this->providerId($owner);
        if (! $providerId || ! $this->isOwner($owner, $providerId)) {
            throw new BusinessRuleException('not_authorised', 'Only the business owner can change who sees its finances.', 403);
        }
        DB::transaction(function () use ($providerId, $memberUserId, $allowed, $owner) {
            $member = ProviderMember::where('provider_profile_id', $providerId)->where('user_id', $memberUserId)->where('role', '!=', ProviderMember::ROLE_OWNER)->lockForUpdate()->first();
            if (! $member) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            if ((bool) $member->can_view_finances === $allowed) {
                return;
            }
            $member->forceFill(['can_view_finances' => $allowed])->save();
            $this->audit->record('finance.provider_access_changed', $member, ['can_view_finances' => ! $allowed], [
                'can_view_finances' => $allowed, 'provider_profile_id' => $providerId, 'member_user_id' => $memberUserId,
            ], null, $owner);
        });

        return $this->describe($owner);
    }
}
