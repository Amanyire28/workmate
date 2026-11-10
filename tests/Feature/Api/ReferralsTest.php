<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\RecognitionMilestone;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserNotification;
use App\Services\Referrals;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

class ReferralsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function inviter(array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['created_at' => now()->subMonth()]);
        app(Referrals::class)->codeFor($user);

        return $user->fresh();
    }

    /** Opens the inviter's link and signs up, as a visitor's browser would */
    private function joinThrough(User $inviter, string $email, ?int $openedDaysAgo = null)
    {
        $this->app['auth']->forgetGuards();
        $cookie = $openedDaysAgo === null
            ? json_encode(['code' => $inviter->referral_code, 'at' => now()->timestamp])
            : json_encode(['code' => $inviter->referral_code, 'at' => now()->subDays($openedDaysAgo)->timestamp]);

        $this->fromWebsite()->withCredentials()->withCookie(Referrals::COOKIE, $cookie)
            ->postJson('/api/v1/auth/register', [
                'name' => 'New Member', 'email' => $email, 'password' => 'kampala2026', 'password_confirmation' => 'kampala2026', 'accept_terms' => true,
            ])->assertCreated();
        $this->app['auth']->forgetGuards();

        return User::where('email', $email)->first();
    }

    private function confirmEmail(User $user): void
    {
        $this->get(URL::temporarySignedRoute('api.v1.auth.verify-email', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]))->assertRedirect();
    }

    /* ---------------- Codes and links ---------------- */

    public function test_every_account_gets_a_stable_unique_code()
    {
        $service = app(Referrals::class);
        $a = User::factory()->create(['referral_code' => null]);
        $b = User::factory()->create(['referral_code' => null]);

        $code = $service->codeFor($a);
        $this->assertMatchesRegularExpression('/^WM-[A-HJ-NP-Z2-9]{6}$/', $code);
        $this->assertSame($code, $service->codeFor($a->fresh()));
        $this->assertNotSame($code, $service->codeFor($b));
        // Nothing about the account is in the code
        $this->assertStringNotContainsString((string) $a->id, substr($code, 3));
        $this->assertStringEndsWith('/register?ref='.$code, $service->linkFor($a));
    }

    public function test_opening_a_valid_link_remembers_the_inviter_and_counts_the_visit()
    {
        $inviter = $this->inviter(['name' => 'Ssemakula Robert']);

        // Unknown, malformed or unconfirmed inviters: the same plain answer, no cookie
        $unconfirmed = $this->inviter(['email_verified_at' => null]);
        foreach (['WM-ZZZZZZ', 'not-a-code', $unconfirmed->referral_code] as $code) {
            $this->fromWebsite()->withCredentials()->postJson('/api/v1/referrals/visit', ['code' => $code])
                ->assertOk()->assertJson(['data' => ['valid' => false, 'inviter' => null]])->assertCookieMissing(Referrals::COOKIE);
        }

        $this->fromWebsite()->withCredentials()->postJson('/api/v1/referrals/visit', ['code' => strtolower($inviter->referral_code)])
            ->assertOk()->assertJson(['data' => ['valid' => true, 'inviter' => 'Ssemakula R.']])
            ->assertCookie(Referrals::COOKIE);
        $this->assertSame(1, (int) \DB::table('referral_link_visits')->where('user_id', $inviter->id)->value('visits'));
    }

    public function test_the_first_valid_link_wins()
    {
        $first = $this->inviter();
        $second = $this->inviter();
        $cookie = json_encode(['code' => $first->referral_code, 'at' => now()->timestamp]);

        $this->fromWebsite()->withCredentials()->withCookie(Referrals::COOKIE, $cookie)
            ->postJson('/api/v1/referrals/visit', ['code' => $second->referral_code])
            ->assertOk()->assertCookieMissing(Referrals::COOKIE);
        $this->assertSame(0, \DB::table('referral_link_visits')->where('user_id', $second->id)->count());
    }

    public function test_signed_in_accounts_cant_be_claimed()
    {
        $inviter = $this->inviter();
        $this->signIn(User::factory()->create());

        $this->postJson('/api/v1/referrals/visit', ['code' => $inviter->referral_code])
            ->assertOk()->assertJsonPath('data.inviter', null)->assertCookieMissing(Referrals::COOKIE);
    }

    /* ---------------- Signing up and qualifying ---------------- */

    public function test_signing_up_through_a_link_links_the_accounts_and_confirming_qualifies_it()
    {
        $inviter = $this->inviter();
        $member = $this->joinThrough($inviter, 'new.member@example.com');

        $referral = Referral::where('referred_id', $member->id)->firstOrFail();
        $this->assertSame([$inviter->id, Referral::STATUS_REGISTERED], [$referral->referrer_id, $referral->status]);
        $this->assertNull($referral->risk_flags);

        $this->confirmEmail($member);
        $referral->refresh();
        $this->assertSame(Referral::STATUS_QUALIFIED, $referral->status);
        $this->assertNotNull($referral->qualified_at);

        // The first badge, and the inviter is told
        $badge = UserBadge::where('user_id', $inviter->id)->with('milestone')->firstOrFail();
        $this->assertSame('first_connection', $badge->milestone->key);
        $this->assertSame(2, UserNotification::where('user_id', $inviter->id)->where('kind', 'referrals')->count());
        $this->assertSame(1, AuditLog::where('action', 'referral.qualified')->count());
    }

    public function test_confirming_twice_counts_once()
    {
        $inviter = $this->inviter();
        $member = $this->joinThrough($inviter, 'twice@example.com');
        $service = app(Referrals::class);

        $this->confirmEmail($member);
        $service->emailConfirmed($member->fresh());
        $service->emailConfirmed($member->fresh());

        $this->assertSame(1, $service->qualifiedCount($inviter));
        $this->assertSame(1, UserBadge::where('user_id', $inviter->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'badge.awarded')->count());
    }

    public function test_an_expired_link_or_no_link_credits_nobody()
    {
        $inviter = $this->inviter();
        $this->joinThrough($inviter, 'late@example.com', 31);
        $this->assertSame(0, Referral::count());

        $this->fromWebsite()->postJson('/api/v1/auth/register', [
            'name' => 'Walk In', 'email' => 'walkin@example.com', 'password' => 'kampala2026', 'password_confirmation' => 'kampala2026', 'accept_terms' => true,
        ])->assertCreated();
        $this->assertSame(0, Referral::count());
    }

    public function test_an_unusual_sign_up_waits_for_a_person_instead_of_counting()
    {
        $inviter = $this->inviter();
        // The inviter signed in from the same network the new account signs up from
        AuditLog::create(['actor_id' => $inviter->id, 'action' => 'auth.signed_in', 'ip_address' => '127.0.0.1']);

        $member = $this->joinThrough($inviter, 'same.network@example.com');
        $this->confirmEmail($member);

        $referral = Referral::where('referred_id', $member->id)->first();
        $this->assertSame(Referral::STATUS_REVIEW, $referral->status);
        $this->assertSame(['same_network_as_inviter'], $referral->risk_flags);
        $this->assertSame(0, UserBadge::count());
    }

    public function test_a_suspended_new_account_never_counts()
    {
        $inviter = $this->inviter();
        $member = $this->joinThrough($inviter, 'gone@example.com');
        $member->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        app(Referrals::class)->emailConfirmed($member->fresh());
        $this->assertSame(Referral::STATUS_REJECTED, Referral::first()->status);
    }

    /* ---------------- Badges ---------------- */

    public function test_milestones_are_awarded_once_and_progress_is_shown()
    {
        $inviter = $this->inviter();
        foreach (range(1, 5) as $i) {
            $this->confirmEmail($this->joinThrough($inviter, "member{$i}@example.com"));
        }

        $held = app(Referrals::class)->heldBadges($inviter)->pluck('milestone.key')->all();
        $this->assertSame(['community_builder', 'first_connection'], $held);

        $this->signIn($inviter);
        $this->getJson('/api/v1/me/referrals')->assertOk()
            ->assertJsonPath('data.counts.qualified', 5)
            ->assertJsonPath('data.current_badge.key', 'community_builder')
            ->assertJsonPath('data.next_badge.key', 'workmate_connector')
            ->assertJsonPath('data.next_badge.remaining', 10)
            ->assertJsonPath('data.history.0.name', 'New M.');
    }

    public function test_revoking_a_referral_takes_back_badges_the_count_no_longer_reaches()
    {
        $inviter = $this->inviter();
        $member = $this->joinThrough($inviter, 'fraud@example.com');
        $this->confirmEmail($member);

        $admin = $this->signIn($this->userWithRoles(['trust_safety']));
        $referral = Referral::first();
        $this->postJson("/api/v1/admin/referrals/{$referral->id}/revoke", ['reason' => 'Duplicate account of the inviter'])
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        $badge = UserBadge::where('user_id', $inviter->id)->first();
        $this->assertNotNull($badge->revoked_at);
        $this->assertSame(0, app(Referrals::class)->qualifiedCount($inviter));
        $this->assertSame(1, AuditLog::where('action', 'referral.revoked')->where('actor_id', $admin->id)->count());
        // Decided once: can't be revoked again or approved
        $this->postJson("/api/v1/admin/referrals/{$referral->id}/revoke", ['reason' => 'Again please'])->assertStatus(409);
    }

    public function test_changing_a_milestone_applies_to_everyone()
    {
        $inviter = $this->inviter();
        $this->confirmEmail($this->joinThrough($inviter, 'one@example.com'));
        $first = RecognitionMilestone::where('key', 'first_connection')->first();

        $this->signIn($this->userWithRoles(['super_admin']));
        $this->patchJson("/api/v1/admin/milestones/{$first->id}", ['threshold' => 2, 'reason' => 'Make it a little harder'])->assertOk();
        $this->assertNotNull(UserBadge::where('user_id', $inviter->id)->first()->revoked_at);

        $this->patchJson("/api/v1/admin/milestones/{$first->id}", ['threshold' => 1, 'reason' => 'Back to one'])->assertOk();
        $this->assertNull(UserBadge::where('user_id', $inviter->id)->first()->revoked_at);
        $this->assertSame(1, UserBadge::where('user_id', $inviter->id)->count());
    }

    /* ---------------- Who can do what ---------------- */

    public function test_people_only_see_their_own_invitations()
    {
        $this->getJson('/api/v1/me/referrals')->assertUnauthorized();

        $inviter = $this->inviter();
        $this->joinThrough($inviter, 'mine@example.com');

        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/me/referrals')->assertOk()->assertJsonPath('data.counts.joined', 0)->assertJsonCount(0, 'data.history');

        $this->signIn($inviter);
        $data = $this->getJson('/api/v1/me/referrals')->assertOk()->json('data');
        $this->assertSame(1, $data['counts']['waiting']);
        // Never anyone's contact details
        $this->assertStringNotContainsString('mine@example.com', json_encode($data));
    }

    public function test_only_permitted_admins_decide_or_change_badges()
    {
        $inviter = $this->inviter();
        $this->joinThrough($inviter, 'review@example.com');
        $referral = Referral::first();
        $milestone = RecognitionMilestone::first();

        foreach ([[], ['support'], ['content']] as $roles) {
            $this->signIn($this->userWithRoles($roles));
            $this->postJson("/api/v1/admin/referrals/{$referral->id}/reject", ['reason' => 'Looks fake to me'])->assertForbidden();
            $this->patchJson("/api/v1/admin/milestones/{$milestone->id}", ['name' => 'Changed', 'reason' => 'Renaming it'])->assertForbidden();
        }
        // Trust and safety decide on referrals but don't change badges
        $this->signIn($this->userWithRoles(['trust_safety']));
        $this->patchJson("/api/v1/admin/milestones/{$milestone->id}", ['name' => 'Changed', 'reason' => 'Renaming it'])->assertForbidden();
        $this->postJson("/api/v1/admin/referrals/{$referral->id}/reject", ['reason' => 'Looks fake to me'])->assertOk()->assertJsonPath('data.status', 'rejected');
        // Nobody decides on their own invitation
        $this->signIn($inviter->fresh());
        $inviter->roles()->attach(\App\Models\Role::where('key', 'trust_safety')->value('id'));
        $this->postJson("/api/v1/admin/referrals/{$referral->id}/reject", ['reason' => 'My own one'])->assertStatus(403);
    }

    public function test_only_permitted_admins_see_who_invited_whom()
    {
        $inviter = $this->inviter(['name' => 'Robert Inviter']);
        $this->confirmEmail($this->joinThrough($inviter, 'seen@example.com'));

        // Admins without referrals.view get empty lists, not someone else's invitations
        $this->signIn($this->userWithRoles(['content']));
        $data = $this->getJson('/api/v1/admin/data')->assertOk()->json('data.ops');
        $this->assertSame([], $data['referrals']);
        $this->assertSame([], $data['milestones']);
        $this->assertSame([], $data['badgeAwards']);

        $this->signIn($this->userWithRoles(['trust_safety']));
        $ops = $this->getJson('/api/v1/admin/data')->assertOk()->json('data.ops');
        $this->assertSame('Robert Inviter', $ops['referrals'][0]['inviterName']);
        $this->assertSame('New Member', $ops['referrals'][0]['memberName']);
        $this->assertSame('qualified', $ops['referrals'][0]['status']);
        $this->assertSame('First Connection', $ops['badgeAwards'][0]['badge']);
        $this->assertCount(5, $ops['milestones']);
        // Names only: the new member's email address isn't part of the record
        $this->assertStringNotContainsString('seen@example.com', json_encode($ops['referrals']));
    }

    public function test_held_referrals_are_approved_by_a_person()
    {
        $inviter = $this->inviter();
        AuditLog::create(['actor_id' => $inviter->id, 'action' => 'auth.signed_in', 'ip_address' => '127.0.0.1']);
        $member = $this->joinThrough($inviter, 'family@example.com');
        $this->confirmEmail($member);

        $this->signIn($this->userWithRoles(['trust_safety']));
        $this->postJson('/api/v1/admin/referrals/'.Referral::first()->id.'/approve', ['reason' => 'Family member, checked'])->assertOk()->assertJsonPath('data.status', 'qualified');
        $this->assertSame(1, UserBadge::where('user_id', $inviter->id)->whereNull('revoked_at')->count());
    }

    public function test_badges_show_on_the_community_profile()
    {
        $inviter = $this->inviter();
        $this->confirmEmail($this->joinThrough($inviter, 'shown@example.com'));

        $this->signIn($inviter);
        $this->getJson("/api/v1/community/people/{$inviter->id}")->assertOk()
            ->assertJsonPath('data.badges.0.name', 'First Connection');
    }
}
