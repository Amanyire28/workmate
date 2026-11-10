<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\RecognitionMilestone;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserBadge;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Invite Friends: recognition for bringing genuine new members to WorkMate.
 * There is no money, credit or other value in any of it.
 *
 * - Every account has a stable, random code (WM-XXXXXX).
 * - Opening an invitation link sets a cookie for a set number of days. The
 *   first valid link wins and isn't replaced. Accounts that already exist
 *   can't be claimed.
 * - At sign-up the new account is linked to its inviter (one inviter each).
 * - When the new account confirms its email it qualifies, unless something
 *   looks unusual (the same network as the inviter, or a burst of sign-ups),
 *   in which case a person decides. Shared networks are common, so nothing
 *   is rejected automatically.
 * - Qualified referrals earn the inviter badges at milestones. Revoking a
 *   referral takes back badges the count no longer reaches.
 */
class Referrals
{
    public const COOKIE = 'wm_ref';

    // Letters and digits that can't be mistaken for each other (no I, O, 0 or 1)
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    /** @var PlatformSettings */
    private $settings;

    public function __construct(AuditLogger $audit, EventNotifications $notify, PlatformSettings $settings)
    {
        $this->audit = $audit;
        $this->notify = $notify;
        $this->settings = $settings;
    }

    /* ---------------- Codes and links ---------------- */

    /** The account's code, created the first time it's needed */
    public function codeFor(User $user): string
    {
        while (! $user->referral_code) {
            $code = $this->newCode();
            // Only if nobody has it and the account still has none (two tabs at once)
            if (! User::where('referral_code', $code)->exists()) {
                User::whereKey($user->getKey())->whereNull('referral_code')->update(['referral_code' => $code]);
            }
            $user->referral_code = User::whereKey($user->getKey())->value('referral_code');
        }

        return $user->referral_code;
    }

    public function newCode(): string
    {
        $code = 'WM-';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public function linkFor(User $user): string
    {
        return url('/register?ref='.$this->codeFor($user));
    }

    /** The inviter behind a code: an active account with a confirmed email */
    public function inviterFor(?string $code): ?User
    {
        $code = strtoupper(trim((string) $code));
        if (! preg_match('/^WM-['.self::ALPHABET.']{6}$/', $code)) {
            return null;
        }
        $user = User::where('referral_code', $code)->first();

        return $user && $user->isActive() && $user->hasVerifiedEmail() ? $user : null;
    }

    /** "Robert S.": enough for someone to recognise who invited them */
    public static function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        if (! $parts || $parts[0] === '') {
            return 'A WorkMate member';
        }

        return $parts[0].(count($parts) > 1 ? ' '.mb_substr(end($parts), 0, 1).'.' : '');
    }

    /* ---------------- Opening a link ---------------- */

    /**
     * Someone opened an invitation link. Counted per inviter and day, never per person.
     *
     * @return User|null the inviter, when the code is valid
     */
    public function recordVisit(?string $code): ?User
    {
        $inviter = $this->inviterFor($code);
        if ($inviter) {
            $day = now()->toDateString();
            DB::table('referral_link_visits')->insertOrIgnore(['user_id' => $inviter->id, 'day' => $day, 'visits' => 0]);
            DB::table('referral_link_visits')->where('user_id', $inviter->id)->where('day', $day)->increment('visits');
        }

        return $inviter;
    }

    /** What the invitation cookie holds, if it's still within the attribution window */
    public function fromCookie(?string $value): ?array
    {
        $data = $value ? json_decode($value, true) : null;
        if (! is_array($data) || empty($data['code']) || empty($data['at'])) {
            return null;
        }
        $opened = Carbon::createFromTimestamp((int) $data['at']);
        if ($opened->lt(now()->subDays($this->attributionDays())) || $opened->gt(now()->addMinute())) {
            return null;
        }

        return ['code' => (string) $data['code'], 'opened_at' => $opened];
    }

    public function cookieValue(string $code): string
    {
        return json_encode(['code' => $code, 'at' => now()->timestamp]);
    }

    public function attributionDays(): int
    {
        return (int) $this->settings->get('referrals.attribution_days');
    }

    /* ---------------- Signing up ---------------- */

    /** Links a brand-new account to the person whose link brought them, if any */
    public function attachAtSignUp(User $user, ?array $invitation, Request $request): ?Referral
    {
        if (! $invitation) {
            return null;
        }
        $inviter = $this->inviterFor($invitation['code']);
        if (! $inviter || $inviter->is($user)) {
            return null;
        }

        return DB::transaction(function () use ($user, $inviter, $invitation, $request) {
            if (Referral::where('referred_id', $user->getKey())->exists()) {
                return null;
            }
            $referral = new Referral();
            $referral->forceFill([
                'referrer_id' => $inviter->getKey(),
                'referred_id' => $user->getKey(),
                'code' => $inviter->referral_code,
                'status' => Referral::STATUS_REGISTERED,
                'risk_flags' => $this->riskFlags($inviter, $request) ?: null,
                'link_opened_at' => $invitation['opened_at'],
            ])->save();
            $this->audit->record('referral.registered', $referral, [], ['inviter' => $inviter->getKey(), 'flags' => $referral->risk_flags], null, $user);

            return $referral;
        });
    }

    /** Signs worth a person's look. None of them proves anything on its own. */
    private function riskFlags(User $inviter, Request $request): array
    {
        $flags = [];
        $ip = $request->ip();
        // Only the inviter's own sign-ups and sign-ins say where they connect from
        if ($ip && AuditLog::where('actor_id', $inviter->getKey())->whereIn('action', ['account.registered', 'auth.signed_in'])
            ->where('ip_address', $ip)->where('created_at', '>=', now()->subDays(30))->exists()) {
            $flags[] = 'same_network_as_inviter';
        }
        $limit = (int) $this->settings->get('referrals.daily_review_limit');
        if (Referral::where('referrer_id', $inviter->getKey())->where('created_at', '>=', now()->subDay())->count() >= $limit) {
            $flags[] = 'many_sign_ups_today';
        }
        if ($inviter->created_at && $inviter->created_at->gt(now()->subDay())) {
            $flags[] = 'inviter_joined_today';
        }

        return $flags;
    }

    /* ---------------- Qualifying ---------------- */

    /** The invited person confirmed their email. Safe to call any number of times. */
    public function emailConfirmed(User $user): void
    {
        $qualified = DB::transaction(function () use ($user) {
            $referral = Referral::where('referred_id', $user->getKey())->lockForUpdate()->first();
            if (! $referral || $referral->status !== Referral::STATUS_REGISTERED) {
                return null;
            }
            if (! $user->isActive()) {
                $this->decide($referral, Referral::STATUS_REJECTED, 'The new account isn’t active.', null);

                return null;
            }
            if ($referral->risk_flags) {
                $referral->forceFill(['status' => Referral::STATUS_REVIEW])->save();
                $this->audit->record('referral.held_for_review', $referral, ['status' => Referral::STATUS_REGISTERED], ['status' => Referral::STATUS_REVIEW, 'flags' => $referral->risk_flags], null, $user);

                return null;
            }
            $this->qualify($referral, null);

            return $referral;
        });

        if ($qualified) {
            $this->afterQualified($qualified);
        }
    }

    private function qualify(Referral $referral, ?User $admin, ?string $reason = null): void
    {
        $before = $referral->status;
        $referral->forceFill(['status' => Referral::STATUS_QUALIFIED, 'qualified_at' => now()]
            + ($admin ? ['decided_at' => now(), 'decided_by' => $admin->getKey(), 'decision_reason' => $reason] : []))->save();
        $this->audit->record('referral.qualified', $referral, ['status' => $before], ['status' => Referral::STATUS_QUALIFIED], $reason, $admin ?: $referral->referred);
    }

    private function afterQualified(Referral $referral): void
    {
        $this->notify->referralQualified($referral->referrer_id, self::shortName(optional($referral->referred)->name));
        $this->syncBadges($referral->referrer, true);
    }

    /* ---------------- Decisions by WorkMate ---------------- */

    public function approve(Referral $referral, User $admin, string $reason): Referral
    {
        $referral = DB::transaction(function () use ($referral, $admin, $reason) {
            $referral = Referral::whereKey($referral->id)->lockForUpdate()->firstOrFail();
            $this->guardDecider($referral, $admin);
            if ($referral->status !== Referral::STATUS_REVIEW) {
                throw new BusinessRuleException('referral_not_in_review', 'Only referrals waiting for a review can be approved.', 409);
            }
            if (! optional($referral->referred)->hasVerifiedEmail()) {
                throw new BusinessRuleException('referral_email_unconfirmed', 'The new member hasn’t confirmed their email yet.', 409);
            }
            $this->qualify($referral, $admin, $reason);

            return $referral;
        });
        $this->afterQualified($referral);

        return $referral;
    }

    public function reject(Referral $referral, User $admin, string $reason): Referral
    {
        return DB::transaction(function () use ($referral, $admin, $reason) {
            $referral = Referral::whereKey($referral->id)->lockForUpdate()->firstOrFail();
            $this->guardDecider($referral, $admin);
            if (! in_array($referral->status, [Referral::STATUS_REGISTERED, Referral::STATUS_REVIEW], true)) {
                throw new BusinessRuleException('referral_decided', 'This referral was already decided. A qualified one can be revoked instead.', 409);
            }
            $this->decide($referral, Referral::STATUS_REJECTED, $reason, $admin);

            return $referral;
        });
    }

    public function revoke(Referral $referral, User $admin, string $reason): Referral
    {
        $referral = DB::transaction(function () use ($referral, $admin, $reason) {
            $referral = Referral::whereKey($referral->id)->lockForUpdate()->firstOrFail();
            $this->guardDecider($referral, $admin);
            if ($referral->status !== Referral::STATUS_QUALIFIED) {
                throw new BusinessRuleException('referral_not_qualified', 'Only a qualified referral can be revoked.', 409);
            }
            $this->decide($referral, Referral::STATUS_REVOKED, $reason, $admin);

            return $referral;
        });
        $this->notify->referralRevoked($referral->referrer_id);
        $this->syncBadges($referral->referrer, true, $reason);

        return $referral;
    }

    private function decide(Referral $referral, string $status, string $reason, ?User $admin): void
    {
        $before = $referral->status;
        $referral->forceFill(['status' => $status, 'decided_at' => now(), 'decided_by' => $admin ? $admin->getKey() : null, 'decision_reason' => $reason])->save();
        $this->audit->record('referral.'.$status, $referral, ['status' => $before], ['status' => $status], $reason, $admin);
    }

    /** Nobody decides on their own invitations */
    private function guardDecider(Referral $referral, User $admin): void
    {
        if (in_array($admin->getKey(), [$referral->referrer_id, $referral->referred_id], true)) {
            throw new BusinessRuleException('conflict_of_interest', 'You can’t decide on a referral you’re part of.', 403);
        }
    }

    /* ---------------- Badges ---------------- */

    /**
     * Brings someone's badges in line with their qualified referrals: awards
     * missing ones (once each) and takes back any the count no longer reaches.
     */
    public function syncBadges(User $user, bool $tell = false, ?string $reason = null): void
    {
        $awarded = [];
        $revoked = [];
        DB::transaction(function () use ($user, &$awarded, &$revoked, $reason) {
            // One update at a time per person
            User::whereKey($user->getKey())->lockForUpdate()->first();
            $count = $this->qualifiedCount($user);
            $held = UserBadge::where('user_id', $user->getKey())->get()->keyBy('recognition_milestone_id');

            foreach (RecognitionMilestone::orderBy('threshold')->get() as $milestone) {
                $badge = $held->get($milestone->id);
                $earns = $milestone->is_active && $count >= $milestone->threshold;
                if ($earns && (! $badge || $badge->revoked_at)) {
                    $badge = $badge ?: (new UserBadge())->forceFill(['user_id' => $user->getKey(), 'recognition_milestone_id' => $milestone->id]);
                    $badge->forceFill(['awarded_at' => now(), 'revoked_at' => null, 'revoked_reason' => null])->save();
                    $this->audit->record('badge.awarded', $badge, [], ['badge' => $milestone->key, 'qualified' => $count], null, $user);
                    $awarded[] = $milestone;
                } elseif (! $earns && $badge && ! $badge->revoked_at && $count < $milestone->threshold) {
                    $badge->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason ?: 'Fewer qualified invitations than this badge needs.'])->save();
                    $this->audit->record('badge.revoked', $badge, [], ['badge' => $milestone->key, 'qualified' => $count], $reason, null);
                    $revoked[] = $milestone;
                }
            }
        });

        if ($tell && $awarded) {
            // One message for the highest new badge
            $this->notify->badgeEarned($user->getKey(), end($awarded)->name);
        }
        if ($tell && $revoked) {
            $this->notify->badgeRevoked($user->getKey(), end($revoked)->name);
        }
    }

    public function qualifiedCount(User $user): int
    {
        return Referral::where('referrer_id', $user->getKey())->where('status', Referral::STATUS_QUALIFIED)->count();
    }

    /** Badges someone holds now, highest first */
    public function heldBadges(User $user)
    {
        return UserBadge::with('milestone')->held()->where('user_id', $user->getKey())->get()
            ->filter(function (UserBadge $b) {
                return $b->milestone && $b->milestone->is_active;
            })
            ->sortByDesc(function (UserBadge $b) {
                return $b->milestone->threshold;
            })->values();
    }

    /** A badge for showing on a profile: never a sign of skill or verification */
    public static function presentBadge(UserBadge $badge): array
    {
        return [
            'key' => $badge->milestone->key,
            'name' => $badge->milestone->name,
            'description' => $badge->milestone->description,
            'icon' => $badge->milestone->icon,
            'earned_at' => $badge->awarded_at->toIso8601String(),
        ];
    }

    /* ---------------- The Invite Friends page ---------------- */

    public function summary(User $user): array
    {
        $referrals = Referral::with('referred:id,name')->where('referrer_id', $user->getKey())->latest()->get();
        $counts = $referrals->countBy('status');
        $qualified = (int) ($counts[Referral::STATUS_QUALIFIED] ?? 0);
        $held = UserBadge::held()->where('user_id', $user->getKey())->pluck('awarded_at', 'recognition_milestone_id');
        $milestones = RecognitionMilestone::active()->orderBy('threshold')->get();
        $next = $milestones->first(function ($m) use ($qualified) {
            return $m->threshold > $qualified;
        });
        $current = $milestones->filter(function ($m) use ($held) {
            return $held->has($m->id);
        })->last();

        return [
            'can_invite' => $user->isActive() && $user->hasVerifiedEmail(),
            'code' => $this->codeFor($user),
            'link' => $this->linkFor($user),
            'attribution_days' => $this->attributionDays(),
            'counts' => [
                'joined' => $referrals->count(),
                'waiting' => (int) (($counts[Referral::STATUS_REGISTERED] ?? 0) + ($counts[Referral::STATUS_REVIEW] ?? 0)),
                'qualified' => $qualified,
                'not_counted' => (int) (($counts[Referral::STATUS_REJECTED] ?? 0) + ($counts[Referral::STATUS_REVOKED] ?? 0)),
            ],
            'current_badge' => $current ? ['key' => $current->key, 'name' => $current->name, 'icon' => $current->icon] : null,
            'next_badge' => $next ? ['key' => $next->key, 'name' => $next->name, 'icon' => $next->icon, 'threshold' => $next->threshold, 'remaining' => $next->threshold - $qualified] : null,
            'badges' => $milestones->map(function ($m) use ($held) {
                return ['key' => $m->key, 'name' => $m->name, 'description' => $m->description, 'icon' => $m->icon, 'threshold' => $m->threshold,
                    'earned_at' => $held->has($m->id) ? Carbon::parse($held[$m->id])->toIso8601String() : null];
            })->values(),
            // First name and initial only: never their contact details
            'history' => $referrals->take(50)->map(function (Referral $r) {
                return ['name' => self::shortName(optional($r->referred)->name), 'status' => $r->status, 'joined_at' => $r->created_at->toIso8601String()];
            })->values(),
        ];
    }
}
