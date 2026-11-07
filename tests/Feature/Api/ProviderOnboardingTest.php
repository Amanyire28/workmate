<?php

namespace Tests\Feature\Api;

use App\Models\DocumentVerification;
use App\Models\LegalAcceptance;
use App\Models\ProviderApplication;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Start earning: one Send for the details and the documents, and corrections */
class ProviderOnboardingTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function applicant(array $attributes = []): User
    {
        $user = $this->signIn(User::factory()->create($attributes));
        $this->putJson('/api/v1/me/provider', [
            'type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda',
        ])->assertOk();

        return $user;
    }

    private function uploadId()
    {
        return $this->post('/api/v1/me/verification/documents', [
            'kind' => 'national_id',
            'front' => UploadedFile::fake()->image('front.jpg', 1200, 800),
            'back' => UploadedFile::fake()->image('back.png', 1200, 800),
        ], ['Accept' => 'application/json']);
    }

    private function send()
    {
        return $this->postJson('/api/v1/me/provider/send', ['accept_provider_terms' => true]);
    }

    private function verification(User $user): DocumentVerification
    {
        return DocumentVerification::where('provider_profile_id', $user->providerProfile()->first()->id)->sole();
    }

    /** A provider who sent everything once */
    private function sent(): User
    {
        $user = $this->applicant();
        $this->uploadId()->assertCreated();
        $this->send()->assertOk();

        return $user;
    }

    public function test_one_send_submits_the_details_and_the_documents_together()
    {
        $user = $this->applicant();
        $this->uploadId()->assertCreated();

        $this->send()->assertOk()->assertJsonPath('data.sent', ['details' => true, 'documents' => true]);

        $this->assertSame(ProviderProfile::STATUS_PENDING_REVIEW, $user->providerProfile()->first()->status);
        $this->assertSame(DocumentVerification::STATUS_PENDING_REVIEW, $this->verification($user)->status);
        $this->assertTrue(LegalAcceptance::where('user_id', $user->id)->where('method', 'provider_application')->exists());
    }

    public function test_nothing_is_sent_without_the_id()
    {
        $user = $this->applicant();

        $this->send()->assertStatus(422)->assertJson(['code' => 'national_id_missing']);
        // The details weren't sent on their own either
        $this->assertSame(ProviderProfile::STATUS_DRAFT, $user->providerProfile()->first()->status);
        $this->assertSame(0, ProviderApplication::count());
    }

    public function test_the_agreement_and_a_confirmed_email_are_needed()
    {
        $user = $this->applicant(['email_verified_at' => null]);
        $this->uploadId()->assertCreated();

        $this->postJson('/api/v1/me/provider/send')->assertStatus(422)->assertJsonValidationErrors('accept_provider_terms');
        $this->send()->assertStatus(403)->assertJson(['code' => 'email_not_verified']);
        $this->assertSame(ProviderProfile::STATUS_DRAFT, $user->providerProfile()->first()->status);
    }

    public function test_details_workmate_asked_to_change_are_corrected_and_sent_back()
    {
        $user = $this->sent();
        $application = ProviderApplication::latest('id')->first();

        $this->signIn($this->userWithRoles(['provider_review']));
        $this->postJson("/api/v1/admin/provider-applications/{$application->id}/request-information", ['reason' => 'Add your second skill and a short description.'])->assertOk();

        // The applicant sees the request, corrects the details and sends again
        $this->signIn($user);
        $this->getJson('/api/v1/me/provider')->assertJsonPath('data.status', 'more_info_required')
            ->assertJsonPath('data.application.decision_reason', 'Add your second skill and a short description.');
        $this->putJson('/api/v1/me/provider', [
            'type' => 'individual', 'display_name' => 'Okello Plumbing', 'bio' => 'Ten years fixing pipes in Kampala.',
            'professions' => [['profession' => 'plumber'], ['profession' => 'electrician']], 'base_area' => 'ntinda',
        ])->assertOk();
        $this->send()->assertOk()->assertJsonPath('data.sent', ['details' => true, 'documents' => false]);

        $this->assertSame(ProviderProfile::STATUS_PENDING_REVIEW, $user->providerProfile()->first()->status);
        $this->assertSame(ProviderApplication::STATUS_PENDING_REVIEW, $application->fresh()->status);
    }

    public function test_documents_sent_back_are_replaced_and_sent_again()
    {
        $user = $this->sent();
        $verification = $this->verification($user);
        $id = $verification->currentDocuments()->where('kind', VerificationDocument::KIND_NATIONAL_ID)->first();

        $this->signIn($this->userWithRoles(['provider_review']));
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/documents/{$id->uuid}/send-back", ['reason' => 'The back photo is blurred.'])->assertOk();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/request-information", ['reason' => 'Please send a clearer ID.'])->assertOk();

        $this->signIn($user);
        $this->getJson('/api/v1/me/verification')->assertJsonPath('data.status', 'info_requested');
        // Can't resend before replacing what was sent back
        $this->send()->assertStatus(422)->assertJson(['code' => 'replacements_missing']);

        $this->post("/api/v1/me/verification/documents/{$id->uuid}/replace", [
            'front' => UploadedFile::fake()->image('front.jpg', 1200, 800),
            'back' => UploadedFile::fake()->image('back.jpg', 1200, 800),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->send()->assertOk()->assertJsonPath('data.sent', ['details' => false, 'documents' => true]);
        $this->assertSame(DocumentVerification::STATUS_PENDING_REVIEW, $this->verification($user)->status);
    }

    public function test_nothing_to_send_while_it_is_being_checked()
    {
        $this->sent();
        $this->send()->assertStatus(409)->assertJson(['code' => 'nothing_to_send']);
    }

    public function test_an_approved_provider_can_send_their_documents_alone()
    {
        $user = $this->applicant();
        $application = app(\App\Services\ProviderApplications::class)->submit($user);
        app(\App\Services\ProviderApplications::class)->approve($application, $this->userWithRoles(['provider_review']));

        $this->signIn($user);
        $this->uploadId()->assertCreated();
        $this->send()->assertOk()->assertJsonPath('data.sent', ['details' => false, 'documents' => true]);
    }

    public function test_signed_out_people_cannot_send()
    {
        $this->postJson('/api/v1/me/provider/send', ['accept_provider_terms' => true])->assertUnauthorized();
    }

    /* ---------------- One decision on details and documents ---------------- */

    private function nationalId(User $user): VerificationDocument
    {
        return $this->verification($user)->currentDocuments()->where('kind', VerificationDocument::KIND_NATIONAL_ID)->first();
    }

    private function reviewer(): User
    {
        return $this->signIn($this->userWithRoles(['provider_review']));
    }

    private function listedIds(): array
    {
        $this->app['auth']->forgetGuards();

        return collect($this->getJson('/api/v1/providers')->json('data'))->pluck('id')->all();
    }

    public function test_approving_lists_the_provider_with_the_tick()
    {
        $user = $this->sent();
        $verification = $this->verification($user);
        $this->reviewer();

        // Not before the ID is accepted: nothing changes
        $this->postJson("/api/v1/admin/onboarding/{$verification->id}/approve")->assertStatus(409)->assertJson(['code' => 'national_id_not_accepted']);
        $this->assertSame(ProviderProfile::STATUS_PENDING_REVIEW, $user->providerProfile()->first()->status);

        $this->postJson("/api/v1/admin/verifications/{$verification->id}/documents/{$this->nationalId($user)->uuid}/accept")->assertOk();
        $this->postJson("/api/v1/admin/onboarding/{$verification->id}/approve")->assertOk()
            ->assertJsonPath('data.verification', 'approved')->assertJsonPath('data.provider', 'approved');

        $profile = $user->providerProfile()->first();
        $this->assertNotNull($profile->document_verified_at);
        $this->assertContains($profile->id, $this->listedIds());
    }

    public function test_corrections_go_back_by_part_and_return_when_fixed()
    {
        $user = $this->sent();
        $verification = $this->verification($user);
        $this->reviewer();

        $this->postJson("/api/v1/admin/onboarding/{$verification->id}/request-changes", [])->assertStatus(422)->assertJson(['code' => 'nothing_to_correct']);
        $this->postJson("/api/v1/admin/onboarding/{$verification->id}/request-changes", [
            'details' => 'Use your business name, not your nickname.',
            'documents' => 'The ID photo is cut off at the bottom.',
        ])->assertOk()->assertJsonPath('data.provider', 'more_info_required')->assertJsonPath('data.verification', 'info_requested');

        // Not approvable while it's with the provider
        $this->postJson("/api/v1/admin/onboarding/{$verification->id}/approve")->assertStatus(409)->assertJson(['code' => 'details_not_sent']);

        $this->signIn($user);
        $this->getJson('/api/v1/me/provider')->assertJsonPath('data.application.decision_reason', 'Use your business name, not your nickname.');
        $this->getJson('/api/v1/me/verification')->assertJsonPath('data.decision_reason', 'The ID photo is cut off at the bottom.');
        $this->putJson('/api/v1/me/provider', ['type' => 'individual', 'display_name' => 'Okello Plumbing Services', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda'])->assertOk();
        $this->send()->assertOk()->assertJsonPath('data.sent', ['details' => true, 'documents' => true]);
        $this->assertSame(ProviderProfile::STATUS_PENDING_REVIEW, $user->providerProfile()->first()->status);
        $this->assertSame(DocumentVerification::STATUS_PENDING_REVIEW, $this->verification($user)->status);
    }

    public function test_a_document_sent_back_alone_is_enough_to_send_corrections()
    {
        $user = $this->sent();
        $verification = $this->verification($user);
        $this->reviewer();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/documents/{$this->nationalId($user)->uuid}/send-back", ['reason' => 'Blurred.'])->assertOk();

        $this->postJson("/api/v1/admin/onboarding/{$verification->id}/request-changes", [])->assertOk()
            ->assertJsonPath('data.verification', 'info_requested')
            // The details weren't questioned, so they keep waiting
            ->assertJsonPath('data.provider', 'pending_review');
    }

    public function test_rejecting_closes_both()
    {
        $user = $this->sent();
        $verification = $this->verification($user);
        $this->reviewer();

        $this->postJson("/api/v1/admin/onboarding/{$verification->id}/reject", ['reason' => 'The ID belongs to someone else.'])->assertOk()
            ->assertJsonPath('data.provider', 'rejected')->assertJsonPath('data.verification', 'rejected');
        $this->assertNotContains($user->providerProfile()->first()->id, $this->listedIds());
    }

    public function test_nobody_is_listed_by_the_details_alone()
    {
        $user = $this->applicant();
        $application = app(\App\Services\ProviderApplications::class)->submit($user);
        $this->reviewer();

        $this->postJson("/api/v1/admin/provider-applications/{$application->id}/approve")->assertStatus(409)->assertJson(['code' => 'id_not_verified']);
        $this->assertSame(ProviderProfile::STATUS_PENDING_REVIEW, $user->providerProfile()->first()->status);
    }

    public function test_only_reviewers_of_both_can_decide()
    {
        $user = $this->sent();
        $verification = $this->verification($user);

        foreach ([[], ['trust_safety'], ['support']] as $roles) {
            $this->signIn($this->userWithRoles($roles));
            $this->postJson("/api/v1/admin/onboarding/{$verification->id}/approve")->assertForbidden();
            $this->postJson("/api/v1/admin/onboarding/{$verification->id}/reject", ['reason' => 'Not allowed here'])->assertForbidden();
        }
        // An applicant can't decide their own application, whatever their role
        $user->roles()->attach(\App\Models\Role::where('key', 'provider_review')->value('id'));
        $this->signIn($user->fresh());
        $this->postJson("/api/v1/admin/onboarding/{$verification->id}/reject", ['reason' => 'Deciding my own'])->assertForbidden();
    }
}
