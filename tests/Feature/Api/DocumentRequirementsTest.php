<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DocumentRequirementVersion;
use App\Models\DocumentVerification;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentRequirementsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** A signed-in provider whose main skill is $profession */
    private function provider(string $profession, string $type = 'individual'): User
    {
        $user = $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/provider', [
            'type' => $type, 'display_name' => 'Okello Services', 'professions' => [['profession' => $profession], ['profession' => $profession === 'plumber' ? 'cleaner' : 'plumber']],
        ])->assertOk();

        return $user;
    }

    private function pdf(string $name = 'document.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function uploadId()
    {
        return $this->post('/api/v1/me/verification/documents', [
            'kind' => 'national_id', 'front' => UploadedFile::fake()->image('front.jpg'), 'back' => UploadedFile::fake()->image('back.jpg'),
        ], ['Accept' => 'application/json']);
    }

    private function uploadFor(string $key, array $fields)
    {
        return $this->post('/api/v1/me/verification/documents', ['kind' => 'profession', 'requirement' => $key] + $fields, ['Accept' => 'application/json']);
    }

    private function permit(string $expires = null)
    {
        return $this->uploadFor('driving_permit', [
            'front' => UploadedFile::fake()->image('permit-front.jpg'), 'back' => UploadedFile::fake()->image('permit-back.jpg'),
            'expires_on' => $expires ?: now()->addYear()->toDateString(),
        ]);
    }

    private function submit()
    {
        return $this->postJson('/api/v1/me/verification/submit', ['confirm' => true]);
    }

    private function verification(User $user): DocumentVerification
    {
        return DocumentVerification::where('provider_profile_id', $user->providerProfile->id)->sole();
    }

    /** A requirements list as the admin screen sends it */
    private function document(string $id, string $level, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'type' => $id, 'name' => ucfirst(str_replace('_', ' ', $id)), 'instructions' => 'Clear and readable.',
            'requirement' => $level, 'condition' => null, 'enabled' => true, 'count' => 1, 'maxSizeMb' => 5,
            'formats' => ['jpg', 'png', 'pdf'], 'frontBack' => false, 'multiPage' => false, 'expiry' => ['required' => false, 'minMonthsValid' => 0],
        ], $extra);
    }

    public function test_the_first_professions_start_with_requirements_to_review()
    {
        $driving = DocumentRequirementVersion::whereHas('category', function ($q) {
            $q->where('slug', 'driving');
        })->sole();

        $this->assertSame(1, $driving->version);
        $this->assertNull($driving->published_by);
        $this->assertSame(['driving_permit', 'vehicle_registration', 'vehicle_insurance'], array_column($driving->documents, 'id'));
        // Everyone's National ID is handled separately
        $this->assertSame(10, DocumentRequirementVersion::count());
        $this->assertSame(0, DocumentRequirementVersion::where('documents', 'like', '%national_id%')->count());
    }

    public function test_a_provider_must_add_what_their_main_profession_needs()
    {
        $user = $this->provider('driver');

        $this->getJson('/api/v1/me/verification')->assertOk()
            ->assertJsonPath('data.requirements.profession', 'Driving')
            ->assertJsonPath('data.requirements.version', 1)
            ->assertJsonPath('data.requirements.documents.0.key', 'driving_permit')
            ->assertJsonPath('data.requirements.documents.0.required', true)
            ->assertJsonPath('data.requirements.documents.0.front_back', true)
            ->assertJsonPath('data.requirements.questions.0.key', 'own_vehicle')
            ->assertJsonPath('data.requirements.questions.0.answer', null)
            ->assertJsonPath('data.requirements.missing', ['Driving permit']);

        $this->uploadId()->assertCreated();
        $this->submit()->assertStatus(422)->assertJson(['code' => 'questions_unanswered']);
        $this->putJson('/api/v1/me/verification/answers', ['own_vehicle' => false])->assertOk();
        $this->submit()->assertStatus(422)->assertJson(['code' => 'documents_missing']);

        $this->permit()->assertCreated()->assertJsonPath('data.requirements.missing', [])
            ->assertJsonPath('data.documents.1.kind', 'profession')
            ->assertJsonPath('data.documents.1.requirement', 'driving_permit')
            ->assertJsonPath('data.documents.1.label', 'Driving permit')
            ->assertJsonPath('data.documents.1.files.1.side', 'back');
        $this->submit()->assertOk()->assertJsonPath('data.status', 'pending_review');

        $this->assertSame(DocumentRequirementVersion::where('category_id', Category::where('slug', 'driving')->value('id'))->value('id'), $this->verification($user)->requirement_version_id);
    }

    public function test_profession_documents_follow_their_rules()
    {
        $this->provider('driver');

        // Only one permit, both sides, still valid for 3 months
        $this->uploadFor('driving_permit', ['files' => [$this->pdf()], 'expires_on' => now()->addYear()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['front', 'back']);
        $this->permit(now()->addMonth()->toDateString())->assertStatus(422)->assertJsonValidationErrors('expires_on');
        $this->uploadFor('driving_permit', ['front' => UploadedFile::fake()->image('f.jpg'), 'back' => UploadedFile::fake()->image('b.jpg')])
            ->assertStatus(422)->assertJsonValidationErrors('expires_on');
        $this->permit()->assertCreated();
        $this->permit()->assertStatus(422)->assertJson(['code' => 'too_many_documents']);

        // Vehicle documents only once the provider says they use their own vehicle
        $this->uploadFor('vehicle_registration', ['files' => [$this->pdf()]])->assertStatus(422)->assertJsonValidationErrors('requirement');
        $this->putJson('/api/v1/me/verification/answers', ['own_vehicle' => true])->assertOk()
            ->assertJsonPath('data.requirements.missing', ['Vehicle registration', 'Vehicle insurance']);
        $this->uploadFor('vehicle_registration', ['files' => [$this->pdf('p1.pdf'), $this->pdf('p2.pdf')]])->assertCreated();
        // Insurance is one file, not several pages
        $this->uploadFor('vehicle_insurance', ['files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')], 'expires_on' => now()->addYear()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('files');
        $this->uploadFor('national_id_copy', ['files' => [$this->pdf()]])->assertStatus(422)->assertJsonValidationErrors('requirement');
    }

    public function test_file_types_and_sizes_come_from_the_requirement()
    {
        $admin = $this->signIn($this->userWithRoles(['operations']));
        $this->postJson('/api/v1/admin/document-requirements/cleaning', ['reason' => 'Photos of past work only', 'documents' => [
            $this->document('past_work', 'mandatory', ['formats' => ['jpg', 'png'], 'maxSizeMb' => 1, 'count' => 2]),
        ]])->assertCreated();

        $this->provider('cleaner');
        $this->uploadFor('past_work', ['files' => [$this->pdf()]])->assertStatus(422)->assertJsonValidationErrors('files.0');
        $this->uploadFor('past_work', ['files' => [UploadedFile::fake()->image('big.jpg')->size(1500)]])->assertStatus(422)->assertJsonValidationErrors('files.0');
        $this->uploadFor('past_work', ['files' => [UploadedFile::fake()->image('one.jpg')]])->assertCreated()
            ->assertJsonPath('data.requirements.documents.0.added', 1)
            ->assertJsonPath('data.requirements.missing', ['Past work']);
        $this->uploadFor('past_work', ['files' => [UploadedFile::fake()->image('two.png')]])->assertCreated()
            ->assertJsonPath('data.requirements.missing', []);
        $this->assertNotNull($admin);
    }

    public function test_business_documents_apply_to_groups_and_companies()
    {
        $this->provider('cleaner', 'company');
        $this->getJson('/api/v1/me/verification')->assertJsonPath('data.requirements.missing', ['Business registration'])
            ->assertJsonPath('data.requirements.questions', []);

        $this->provider('cleaner');
        $this->getJson('/api/v1/me/verification')->assertJsonPath('data.requirements.missing', []);
    }

    public function test_sent_documents_keep_the_rules_they_were_sent_under()
    {
        $user = $this->provider('driver');
        $this->uploadId()->assertCreated();
        $this->putJson('/api/v1/me/verification/answers', ['own_vehicle' => false])->assertOk();
        $this->permit()->assertCreated();
        $this->submit()->assertOk();

        // A stricter version is published afterwards
        $this->signIn($this->userWithRoles(['operations']));
        $this->postJson('/api/v1/admin/document-requirements/driving', ['reason' => 'Drivers need a police letter', 'documents' => [
            $this->document('driving_permit', 'mandatory', ['frontBack' => true, 'expiry' => ['required' => true, 'minMonthsValid' => 3]]),
            $this->document('police_letter', 'mandatory'),
        ]])->assertCreated()->assertJsonPath('data.version', 2);

        $this->signIn($user);
        $this->getJson('/api/v1/me/verification')->assertJsonPath('data.requirements.version', 1)->assertJsonPath('data.requirements.missing', []);

        // The reviewer can approve under version 1
        $verification = $this->verification($user);
        $this->signIn($this->userWithRoles(['provider_review']));
        foreach ($verification->currentDocuments()->get() as $doc) {
            $this->postJson("/api/v1/admin/verifications/{$verification->id}/documents/{$doc->uuid}/accept")->assertOk();
        }
        $row = collect($this->getJson('/api/v1/admin/data')->json('data.ops.verifications'))->firstWhere('verificationId', $verification->id);
        $this->assertSame(['profession' => 'Driving', 'version' => 1, 'missing' => []], $row['rules']);
        $this->assertSame('required', collect($row['documents'])->firstWhere('kind', 'profession')['requirement']);
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/approve")->assertOk();

        // A new provider gets version 2
        $this->provider('driver');
        $this->getJson('/api/v1/me/verification')->assertJsonPath('data.requirements.version', 2)
            ->assertJsonPath('data.requirements.missing', ['Driving permit', 'Police letter']);
    }

    public function test_a_rejected_provider_starts_again_under_the_current_rules()
    {
        $user = $this->provider('plumber');
        $this->uploadId()->assertCreated();
        $this->submit()->assertOk();
        $verification = $this->verification($user);

        $this->signIn($this->userWithRoles(['operations']));
        $this->postJson('/api/v1/admin/document-requirements/plumbing', ['reason' => 'Plumbers need a certificate', 'documents' => [
            $this->document('trade_certificate', 'mandatory'),
        ]])->assertCreated();
        $this->postJson("/api/v1/admin/verifications/{$verification->id}/reject", ['reason' => 'The photos are unreadable.'])->assertOk();

        $this->signIn($user);
        $this->uploadId()->assertCreated()->assertJsonPath('data.requirements.version', 2);
        $this->submit()->assertStatus(422)->assertJson(['code' => 'documents_missing']);
        $this->assertNull($verification->fresh()->requirement_version_id);
    }

    public function test_publishing_needs_the_permission_and_valid_requirements()
    {
        $body = ['reason' => 'Electricians must show a certificate', 'documents' => [$this->document('trade_certificate', 'mandatory')]];

        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/admin/document-requirements')->assertForbidden();
        $this->postJson('/api/v1/admin/document-requirements/electrical', $body)->assertForbidden();

        // Reviewers can see the requirements but not change them
        $this->signIn($this->userWithRoles(['provider_review']));
        $this->getJson('/api/v1/admin/document-requirements')->assertOk()
            ->assertJsonPath('data.versions.electrical.0.publishedBy', 'WorkMate')
            ->assertJsonPath('data.formats', ['jpg', 'png', 'pdf']);
        $this->postJson('/api/v1/admin/document-requirements/electrical', $body)->assertForbidden();

        $admin = $this->signIn($this->userWithRoles(['operations']));
        $this->postJson('/api/v1/admin/document-requirements/electrical', ['reason' => 'x', 'documents' => [
            $this->document('national_id', 'mandatory'),
            $this->document('permit', 'conditional'),
            $this->document('BAD KEY', 'optional', ['maxSizeMb' => 40, 'formats' => ['exe']]),
        ]])->assertStatus(422)->assertJsonValidationErrors([
            'reason', 'documents.0.id', 'documents.1.condition', 'documents.2.id', 'documents.2.maxSizeMb', 'documents.2.formats.0',
        ]);
        $this->postJson('/api/v1/admin/document-requirements/nothing-here', $body)->assertNotFound();

        $this->postJson('/api/v1/admin/document-requirements/electrical', $body)->assertCreated()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.publishedBy', $admin->name);
        $this->postJson('/api/v1/admin/document-requirements/electrical', $body)->assertStatus(422)->assertJson(['code' => 'requirements_unchanged']);

        // Version 1 is kept as it was
        $versions = $this->getJson('/api/v1/admin/document-requirements')->json('data.versions.electrical');
        $this->assertCount(2, $versions);
        $this->assertSame('Electrical trade or wiring certificate', $versions[0]['documents'][0]['name']);
        $this->assertTrue(AuditLog::where('action', 'verification.requirements_published')->where('actor_id', $admin->id)->where('reason', $body['reason'])->exists());
    }

    public function test_answers_and_profession_documents_belong_to_the_provider()
    {
        $owner = $this->provider('driver');
        $this->permit()->assertCreated();
        $permit = DocumentVerification::where('provider_profile_id', $owner->providerProfile->id)->sole()
            ->currentDocuments()->where('kind', VerificationDocument::KIND_PROFESSION)->sole();

        $this->provider('driver');
        $this->deleteJson("/api/v1/me/verification/documents/{$permit->uuid}")->assertNotFound();
        $this->getJson('/api/v1/me/verification')->assertJsonCount(0, 'data.documents')->assertJsonPath('data.requirements.questions.0.answer', null);

        // Customers have no documents to answer for
        $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/verification/answers', ['own_vehicle' => true])->assertNotFound();

        // The owner can remove their own before it's reviewed
        $this->signIn($owner);
        $this->deleteJson("/api/v1/me/verification/documents/{$permit->uuid}")->assertOk()->assertJsonPath('data.requirements.missing', ['Driving permit']);
    }
}
