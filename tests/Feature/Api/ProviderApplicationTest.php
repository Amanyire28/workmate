<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ProviderApplication;
use App\Models\User;

class ProviderApplicationTest extends ApiTestCase
{
    private function details(array $overrides = []): array
    {
        return array_merge([
            'type' => 'individual',
            'display_name' => 'Okello Plumbing',
            'bio' => 'Ten years fixing pipes in Ntinda.',
            'base_area' => 'ntinda',
            'professions' => [
                ['profession' => 'plumber', 'years_experience' => 10],
                ['profession' => 'electrician', 'years_experience' => 2],
            ],
        ], $overrides);
    }

    /** A verified customer who has sent an application for review. */
    private function applicant(): User
    {
        $user = $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/provider', $this->details())->assertOk();
        $this->postJson('/api/v1/me/provider/submit')->assertOk();

        return $user;
    }

    private function asReviewer(): User
    {
        return $this->signIn($this->userWithRoles(['provider_review']));
    }

    private function pendingApplication(): ProviderApplication
    {
        return ProviderApplication::where('status', 'pending_review')->sole();
    }

    public function test_categories_come_from_the_database()
    {
        Category::where('slug', 'driving')->update(['is_active' => false]);

        $categories = $this->getJson('/api/v1/categories')->assertOk()->json('data');

        $this->assertCount(Category::where('is_active', true)->count(), $categories);
        $this->assertGreaterThan(20, count($categories));
        $this->assertSame('construction', $categories[0]['slug']);
        $this->assertSame(['slug' => 'builder', 'name' => 'Builder', 'plural_name' => 'builders'], $categories[0]['professions'][0]);
        $this->assertContains('mason', array_column($categories[0]['professions'], 'slug'));
        $this->assertNotContains('driving', array_column($categories, 'slug'));
    }

    public function test_a_customer_has_not_applied_until_they_add_details()
    {
        $this->signIn(User::factory()->create());

        $this->getJson('/api/v1/me/provider')->assertOk()->assertExactJson(['data' => null]);
        $this->getJson('/api/v1/me')->assertJsonPath('data.provider_status', 'not_applied');
    }

    public function test_saving_details_creates_a_draft_with_several_professions()
    {
        $user = $this->signIn(User::factory()->create());

        $this->putJson('/api/v1/me/provider', $this->details(['status' => 'approved']))
            ->assertOk()
            // A status sent by the client is ignored
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.professions.0', ['profession' => 'plumber', 'name' => 'Plumber', 'years_experience' => 10, 'is_primary' => true])
            ->assertJsonPath('data.professions.1.profession', 'electrician')
            ->assertJsonPath('data.can_submit', true);

        $profile = $user->fresh()->providerProfile;
        $this->assertSame('owner', $profile->members()->sole()->role);
        $this->assertFalse($user->fresh()->isProvider());
    }

    public function test_professions_must_exist_be_active_and_not_repeat()
    {
        $this->signIn(User::factory()->create());

        $this->putJson('/api/v1/me/provider', $this->details(['professions' => [
            ['profession' => 'astronaut'],
            ['profession' => 'plumber'],
            ['profession' => 'plumber'],
        ]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['professions.0.profession', 'professions.1.profession']);

        $this->putJson('/api/v1/me/provider', $this->details(['professions' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('professions');
    }

    public function test_the_email_must_be_confirmed_before_submitting()
    {
        $this->signIn(User::factory()->unverified()->create());
        $this->putJson('/api/v1/me/provider', $this->details())->assertOk();

        $this->postJson('/api/v1/me/provider/submit')
            ->assertForbidden()
            ->assertJson(['code' => 'email_not_verified']);
    }

    public function test_submitting_locks_the_details_until_a_decision()
    {
        $this->applicant();

        $this->getJson('/api/v1/me/provider')
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.application.status', 'pending_review')
            ->assertJsonPath('data.can_edit', false);

        $this->putJson('/api/v1/me/provider', $this->details())->assertStatus(409)->assertJson(['code' => 'provider_locked']);
        $this->postJson('/api/v1/me/provider/submit')->assertStatus(409);
        $this->assertSame(1, ProviderApplication::count());
    }

    public function test_approval_adds_provider_capabilities_and_keeps_customer_ones()
    {
        $applicant = $this->applicant();
        $application = $this->pendingApplication();
        $this->assertFalse($applicant->fresh()->can('act-as-provider'));

        $reviewer = $this->asReviewer();
        $this->postJson("/api/v1/admin/provider-applications/{$application->id}/approve", ['internal_note' => 'Checked by phone'])
            ->assertOk()
            ->assertJsonPath('status', null)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.id', $reviewer->id);

        $applicant = $applicant->fresh();
        $this->assertTrue($applicant->can('act-as-provider'));

        $this->signIn($applicant);
        $this->getJson('/api/v1/me')
            ->assertJsonPath('data.capabilities', ['customer' => true, 'provider' => true])
            ->assertJsonPath('data.provider_status', 'approved');

        $log = AuditLog::where('action', 'provider.application_approved')->sole();
        $this->assertSame($reviewer->id, $log->actor_id);
    }

    public function test_requesting_more_information_lets_the_applicant_fix_and_resubmit()
    {
        $this->applicant();
        $application = $this->pendingApplication();

        $this->asReviewer();
        $this->postJson("/api/v1/admin/provider-applications/{$application->id}/request-information", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/provider-applications/{$application->id}/request-information", [
            'reason' => 'Please describe the plumbing work you do.',
            'internal_note' => 'Bio too short',
        ])->assertOk();

        $this->signIn($application->profile->owner);
        $this->getJson('/api/v1/me/provider')
            ->assertJsonPath('data.status', 'more_info_required')
            ->assertJsonPath('data.application.decision_reason', 'Please describe the plumbing work you do.')
            ->assertJsonMissing(['internal_note' => 'Bio too short']);

        $this->putJson('/api/v1/me/provider', $this->details(['bio' => 'Leaks, blocked drains, water tanks and new installations.']))->assertOk();
        $this->postJson('/api/v1/me/provider/submit', ['note' => 'Added more detail'])->assertOk();

        // The same application goes back to the queue with the new details
        $application = $application->fresh();
        $this->assertSame(1, ProviderApplication::count());
        $this->assertSame('pending_review', $application->status);
        $this->assertNull($application->decided_by);
        $this->assertSame('Leaks, blocked drains, water tanks and new installations.', $application->snapshot['bio']);
    }

    public function test_a_rejected_applicant_sees_the_reason_and_can_start_again()
    {
        $this->applicant();
        $application = $this->pendingApplication();

        $this->asReviewer();
        $this->postJson("/api/v1/admin/provider-applications/{$application->id}/reject", ['reason' => 'We could not confirm your experience.'])->assertOk();
        $this->postJson("/api/v1/admin/provider-applications/{$application->id}/approve")
            ->assertStatus(409)
            ->assertJson(['code' => 'application_already_decided']);

        $this->signIn($application->profile->owner);
        $this->getJson('/api/v1/me/provider')
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.application.decision_reason', 'We could not confirm your experience.');

        $this->putJson('/api/v1/me/provider', $this->details())->assertStatus(409);
        $this->postJson('/api/v1/me/provider/reopen')->assertOk()->assertJsonPath('data.status', 'draft');
        $this->putJson('/api/v1/me/provider', $this->details())->assertOk();
    }

    public function test_reviewers_cannot_decide_their_own_application()
    {
        $reviewer = $this->signIn($this->userWithRoles(['provider_review']));
        $this->putJson('/api/v1/me/provider', $this->details())->assertOk();
        $this->postJson('/api/v1/me/provider/submit')->assertOk();

        $this->postJson('/api/v1/admin/provider-applications/'.$this->pendingApplication()->id.'/approve')
            ->assertForbidden()
            ->assertJson(['code' => 'conflict_of_interest']);

        $this->assertFalse($reviewer->fresh()->isProvider());
    }

    public function test_only_reviewers_can_see_and_decide_applications()
    {
        $this->applicant();
        $application = $this->pendingApplication();

        foreach ([[], ['support'], ['finance']] as $roles) {
            $this->signIn($this->userWithRoles($roles));
            $this->getJson('/api/v1/admin/provider-applications')->assertForbidden();
            $this->postJson("/api/v1/admin/provider-applications/{$application->id}/approve")->assertForbidden();
        }

        $this->asReviewer();
        $this->getJson('/api/v1/admin/provider-applications?status=pending_review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.submitted.professions.0.profession', 'plumber')
            ->assertJsonPath('data.0.applicant.email_verified', true);
    }

    public function test_suspending_and_reinstating_a_provider()
    {
        $applicant = $this->applicant();
        $application = $this->pendingApplication();
        $profileId = $application->provider_profile_id;

        $this->signIn($this->userWithRoles(['trust_safety']));
        $this->postJson("/api/v1/admin/providers/{$profileId}/suspend", ['reason' => 'Not approved yet'])
            ->assertStatus(409)
            ->assertJson(['code' => 'provider_status_conflict']);

        $this->asReviewer();
        $this->postJson("/api/v1/admin/provider-applications/{$application->id}/approve")->assertOk();

        $this->signIn($this->userWithRoles(['trust_safety']));
        $this->postJson("/api/v1/admin/providers/{$profileId}/suspend")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/providers/{$profileId}/suspend", ['reason' => 'Confirmed complaint #18'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertFalse($applicant->fresh()->isProvider());
        $this->assertTrue($applicant->fresh()->isActive(), 'The customer account stays usable');

        $this->postJson("/api/v1/admin/providers/{$profileId}/reinstate", ['reason' => 'Complaint resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertTrue($applicant->fresh()->isProvider());

        $this->assertSame(1, AuditLog::where('action', 'provider.suspended')->count());
        $this->assertSame(1, AuditLog::where('action', 'provider.reinstated')->count());
    }

    public function test_a_suspended_account_loses_provider_capability_even_when_approved()
    {
        $applicant = $this->applicant();

        $this->asReviewer();
        $this->postJson('/api/v1/admin/provider-applications/'.$this->pendingApplication()->id.'/approve')->assertOk();

        $applicant = $applicant->fresh();
        $applicant->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertFalse($applicant->fresh()->can('act-as-provider'));
    }
}
