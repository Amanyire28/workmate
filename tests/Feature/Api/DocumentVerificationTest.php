<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\DocumentVerification;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentVerificationTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** A signed-in account that has started its provider details */
    private function provider(): User
    {
        $user = $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/provider', [
            'type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']],
        ])->assertOk();

        return $user;
    }

    private function pdf(string $name = 'certificate.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function uploadId()
    {
        return $this->post('/api/v1/me/verification/documents', [
            'kind' => 'national_id',
            'front' => UploadedFile::fake()->image('front.jpg', 1200, 800),
            'back' => UploadedFile::fake()->image('back.png', 1200, 800),
        ], ['Accept' => 'application/json']);
    }

    private function uploadSupporting(string $label = 'Trade certificate')
    {
        return $this->post('/api/v1/me/verification/documents', ['kind' => 'supporting', 'label' => $label, 'files' => [$this->pdf()]], ['Accept' => 'application/json']);
    }

    /** A provider who has sent their ID and one supporting document for review */
    private function submitted(): User
    {
        $user = $this->provider();
        $this->uploadId()->assertCreated();
        $this->uploadSupporting()->assertCreated();
        $this->postJson('/api/v1/me/verification/submit', ['confirm' => true])->assertOk();

        return $user;
    }

    private function verification(User $user): DocumentVerification
    {
        return DocumentVerification::where('provider_profile_id', $user->providerProfile->id)->sole();
    }

    private function doc(User $user, string $kind): VerificationDocument
    {
        return $this->verification($user)->currentDocuments()->where('kind', $kind)->latest('id')->first();
    }

    private function reviewer(): User
    {
        return $this->signIn($this->userWithRoles(['provider_review']));
    }

    public function test_a_provider_uploads_their_id_and_supporting_documents_privately()
    {
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/me/verification')->assertOk()->assertExactJson(['data' => null]);

        $user = $this->provider();
        $this->uploadId()->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.documents.0.kind', 'national_id')
            ->assertJsonPath('data.documents.0.files.0.side', 'front')
            ->assertJsonPath('data.documents.0.files.1.side', 'back')
            ->assertJsonPath('data.documents.0.files.1.type', 'image/jpeg');
        $response = $this->uploadSupporting()->assertCreated();

        $this->assertStringNotContainsString('verification/', $response->getContent());
        $id = $this->doc($user, 'national_id');
        foreach ($id->files as $file) {
            Storage::disk('local')->assertExists($file['path']);
            $this->assertStringStartsWith('verification/', $file['path']);
        }
        $this->assertSame('application/pdf', $this->doc($user, 'supporting')->files[0]['mime']);

        // The owner can open their own file
        $this->get("/api/v1/me/verification/documents/{$id->uuid}/files/0")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertTrue(AuditLog::where('action', 'verification.document_added')->where('actor_id', $user->id)->exists());
    }

    public function test_uploads_are_checked()
    {
        $this->provider();

        $this->post('/api/v1/me/verification/documents', ['kind' => 'national_id', 'front' => UploadedFile::fake()->image('front.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('back');
        $this->post('/api/v1/me/verification/documents', ['kind' => 'supporting', 'files' => [UploadedFile::fake()->create('virus.exe', 10)]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['label', 'files.0']);
        $this->post('/api/v1/me/verification/documents', ['kind' => 'supporting', 'label' => 'Big', 'files' => [UploadedFile::fake()->create('big.pdf', 9000, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('files.0');
        // An image that isn't really one
        $this->post('/api/v1/me/verification/documents', ['kind' => 'supporting', 'label' => 'Fake', 'files' => [UploadedFile::fake()->createWithContent('fake.png', 'not an image')]], ['Accept' => 'application/json'])
            ->assertStatus(422);

        for ($i = 1; $i <= 5; $i++) {
            $this->uploadSupporting("Document {$i}")->assertCreated();
        }
        $this->uploadSupporting('One too many')->assertStatus(422)->assertJson(['code' => 'too_many_documents']);
    }

    public function test_a_new_id_replaces_the_old_one_and_supporting_documents_can_be_removed()
    {
        $user = $this->provider();
        $this->uploadId()->assertCreated();
        $old = $this->doc($user, 'national_id');
        $this->uploadId()->assertCreated()->assertJsonCount(1, 'data.documents');

        $this->assertSame('replaced', $old->fresh()->status);
        // Replaced files are kept as a record
        Storage::disk('local')->assertExists($old->files[0]['path']);

        $this->uploadSupporting()->assertCreated();
        $this->deleteJson('/api/v1/me/verification/documents/'.$this->doc($user, 'supporting')->uuid)->assertOk()->assertJsonCount(1, 'data.documents');
    }

    public function test_sending_needs_a_confirmed_email_and_the_id()
    {
        $user = $this->provider();
        $this->uploadSupporting()->assertCreated();

        $this->postJson('/api/v1/me/verification/submit')->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->postJson('/api/v1/me/verification/submit', ['confirm' => true])->assertStatus(422)->assertJson(['code' => 'national_id_missing']);

        $user->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/api/v1/me/verification/submit', ['confirm' => true])->assertStatus(403)->assertJson(['code' => 'email_not_verified']);
    }

    public function test_documents_are_locked_while_being_reviewed()
    {
        $this->submitted();

        $this->uploadSupporting('Late addition')->assertStatus(409)->assertJson(['code' => 'verification_locked']);
        $this->getJson('/api/v1/me/verification')->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.can_edit', false);
    }

    public function test_a_reviewer_approves_and_the_provider_gets_the_tick()
    {
        $user = $this->submitted();
        $verification = $this->verification($user);
        $id = $this->doc($user, 'national_id');
        $supporting = $this->doc($user, 'supporting');

        $admin = $this->reviewer();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/approve")->assertStatus(409)->assertJson(['code' => 'national_id_not_accepted']);
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/documents/{$id->uuid}/accept")->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/approve")->assertStatus(409)->assertJson(['code' => 'documents_not_reviewed']);
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/documents/{$supporting->uuid}/accept")->assertOk();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/approve", ['note' => 'All clear'])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertNotNull($user->providerProfile->fresh()->document_verified_at);
        $this->assertTrue(AuditLog::where('action', 'verification.approved')->where('actor_id', $admin->id)->exists());
    }

    public function test_a_reviewer_sends_back_a_document_and_asks_for_another_one()
    {
        $user = $this->submitted();
        $verification = $this->verification($user);
        $id = $this->doc($user, 'national_id');

        $this->reviewer();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/documents/{$id->uuid}/send-back", ['reason' => 'The back is blurry.'])->assertOk();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/request-document", ['label' => 'Driving permit', 'instructions' => 'Both sides, showing your classes.'])
            ->assertOk()->assertJsonPath('data.status', 'info_requested');

        // The provider sees both and can't resend until they're done
        $this->signIn($user);
        $state = $this->getJson('/api/v1/me/verification')->assertOk()
            ->assertJsonPath('data.documents.0.status', 'replacement_required')
            ->assertJsonPath('data.documents.0.note', 'The back is blurry.')
            ->assertJsonPath('data.requests.0.label', 'Driving permit')
            ->json('data');
        $this->postJson('/api/v1/me/verification/submit', ['confirm' => true])->assertStatus(422)->assertJson(['code' => 'replacements_missing']);

        $this->post("/api/v1/me/verification/documents/{$id->uuid}/replace", [
            'front' => UploadedFile::fake()->image('front2.jpg'), 'back' => UploadedFile::fake()->image('back2.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/v1/me/verification/submit', ['confirm' => true])->assertStatus(422)->assertJson(['code' => 'requests_open']);

        $this->post('/api/v1/me/verification/documents', ['kind' => 'requested', 'request' => $state['requests'][0]['id'], 'files' => [$this->pdf('permit.pdf')]], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(0, 'data.requests');
        $this->postJson('/api/v1/me/verification/submit', ['confirm' => true])->assertOk()->assertJsonPath('data.status', 'pending_review');

        $this->assertSame('replaced', $id->fresh()->status);
        $this->assertSame('Driving permit', $this->doc($user, 'requested')->label);
    }

    public function test_a_rejected_provider_can_change_documents_and_try_again()
    {
        $user = $this->submitted();
        $verification = $this->verification($user);

        $this->reviewer();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/reject", ['reason' => 'The ID is not yours.'])->assertOk();

        $this->signIn($user);
        $this->getJson('/api/v1/me/verification')->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_reason', 'The ID is not yours.');
        $this->uploadId()->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->postJson('/api/v1/me/verification/submit', ['confirm' => true])->assertOk();
    }

    public function test_opening_files_needs_permission_and_is_recorded()
    {
        $user = $this->submitted();
        $verification = $this->verification($user);
        $id = $this->doc($user, 'national_id');
        $url = "/api/v1/admin/verifications/{$verification->id}/documents/{$id->uuid}/files/0";

        // Operations staff review documents but can't open them
        $this->signIn($this->userWithRoles(['operations']));
        $this->get($url, ['Accept' => 'application/json'])->assertForbidden();

        $admin = $this->reviewer();
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get("/api/v1/admin/verifications/{$verification->id}/documents/{$id->uuid}/files/7", ['Accept' => 'application/json'])->assertNotFound();

        $this->assertSame(1, AuditLog::where('action', 'document.viewed')->where('actor_id', $admin->id)->count());
    }

    public function test_nobody_else_can_see_or_change_a_providers_documents()
    {
        $owner = $this->submitted();
        $id = $this->doc($owner, 'national_id');
        $verification = $this->verification($owner);

        // Another provider
        $this->provider();
        $this->get("/api/v1/me/verification/documents/{$id->uuid}/files/0", ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson("/api/v1/me/verification/documents/{$id->uuid}")->assertNotFound();
        $this->getJson('/api/v1/me/verification')->assertJsonCount(0, 'data.documents');

        // Customers and admins without the permission
        $this->signIn(User::factory()->create());
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/approve")->assertForbidden();
        $this->signIn($this->userWithRoles(['support']));
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/documents/{$id->uuid}/accept")->assertForbidden();
        $this->assertSame([], $this->getJson('/api/v1/admin/data')->json('data.ops.verifications'));

        // A reviewer can't review their own provider business
        $owner->roles()->attach(\App\Models\Role::where('key', 'provider_review')->value('id'));
        $this->signIn($owner->fresh());
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/documents/{$id->uuid}/accept")->assertStatus(403)->assertJson(['code' => 'conflict_of_interest']);
        $this->assertSame('awaiting_review', $id->fresh()->status);
    }

    public function test_reviewers_see_submitted_applications_without_file_paths()
    {
        $user = $this->submitted();

        $this->reviewer();
        $rows = $this->getJson('/api/v1/admin/data')->assertOk()->json('data.ops.verifications');
        $this->assertCount(1, $rows);
        $this->assertSame('VA-'.$this->verification($user)->id, $rows[0]['id']);
        $this->assertSame('National ID', $rows[0]['documents'][0]['name']);
        $this->assertSame('plumbing', $rows[0]['profession']);
        $this->assertStringNotContainsString('verification/', json_encode($rows));
    }

    public function test_reopening_needs_the_configuration_permission()
    {
        $user = $this->submitted();
        $verification = $this->verification($user);

        $this->reviewer();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/reject", ['reason' => 'Unreadable documents'])->assertOk();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/reopen", ['reason' => 'Second look'])->assertForbidden();

        $this->signIn($this->userWithRoles(['operations']));
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/reopen", ['reason' => 'Second look'])->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->assertSame(ProviderProfile::STATUS_DRAFT, $user->providerProfile->fresh()->status);
    }
}
