<?php

namespace Tests\Feature\Api;

use App\Models\AccountWarning;
use App\Models\AuditLog;
use App\Models\ProviderProfile;
use App\Models\Report;
use App\Models\User;
use App\Services\ProviderApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ReportTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function provider(string $name = 'Okello Plumbing'): ProviderProfile
    {
        $owner = User::factory()->create(['name' => $name]);
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => $name, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function request(ProviderProfile $provider, User $customer): int
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow',
        ])->assertCreated()->json('data.id');
    }

    private function report(array $fields)
    {
        return $this->post('/api/v1/reports', $fields + ['details' => 'He asked me to send money before coming.'], ['Accept' => 'application/json']);
    }

    private function safetyTeam(): User
    {
        return $this->signIn($this->userWithRoles(['trust_safety']));
    }

    public function test_a_customer_reports_a_provider_with_photos_and_follows_it()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['name' => 'Aisha Nakato']);
        $request = $this->request($provider, $customer);

        $this->signIn($customer);
        $id = $this->report([
            'about' => 'provider', 'provider' => $provider->id, 'request' => $request, 'reason' => 'scam',
            'photos' => [UploadedFile::fake()->image('chat.png', 600, 900)],
        ])->assertCreated()
            ->assertJsonPath('data.about_name', 'Okello Plumbing')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.photos.0.type', 'image/jpeg')
            ->json('data.id');

        $report = Report::find($id);
        $this->assertSame('high', $report->severity);
        $this->assertSame($provider->owner_id, $report->subject_user_id);
        Storage::disk('local')->assertExists($report->attachments[0]['path']);
        $this->get("/api/v1/me/reports/{$id}/files/0")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($id, $this->getJson('/api/v1/me/reports')->json('data.0.id'));
        // A report alone changes nothing
        $this->assertSame('approved', $provider->fresh()->status);
    }

    public function test_a_provider_reports_a_customer_only_through_a_request_or_chat_with_them()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['name' => 'Aisha Nakato']);
        $request = $this->request($provider, $customer);

        $this->signIn($provider->owner);
        $this->report(['about' => 'customer', 'reason' => 'abusive'])->assertStatus(422)->assertJson(['code' => 'customer_unknown']);
        $id = $this->report(['about' => 'customer', 'request' => $request, 'reason' => 'abusive'])->assertCreated()
            ->assertJsonPath('data.about_name', 'Aisha')->json('data.id');

        $report = Report::find($id);
        $this->assertSame('provider', $report->reporter_role);
        $this->assertSame($customer->id, $report->subject_user_id);

        // Someone else's request can't be used
        $stranger = $this->provider('Other Plumbing');
        $this->signIn($stranger->owner);
        $this->report(['about' => 'customer', 'request' => $request, 'reason' => 'abusive'])->assertNotFound();
    }

    public function test_reports_are_checked()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $this->report(['about' => 'provider', 'provider' => $provider->id, 'reason' => 'scam'])->assertStatus(422)->assertJson(['code' => 'own_provider']);

        $this->signIn(User::factory()->create());
        $this->report(['about' => 'general', 'reason' => 'scam'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->post('/api/v1/reports', ['about' => 'general', 'reason' => 'app_problem', 'details' => 'Bad'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('details');
        $this->report(['about' => 'general', 'reason' => 'app_problem', 'photos' => [
            UploadedFile::fake()->image('1.png'), UploadedFile::fake()->image('2.png'), UploadedFile::fake()->image('3.png'), UploadedFile::fake()->image('4.png'),
        ]])->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->report(['about' => 'general', 'reason' => 'app_problem', 'photos' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]])
            ->assertStatus(422)->assertJsonValidationErrors('photos.0');
        $this->report(['about' => 'general', 'reason' => 'app_problem'])->assertCreated()->assertJsonPath('data.about_name', null);
    }

    public function test_the_safety_team_asks_a_question_and_the_reporter_answers()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $this->signIn($customer);
        $id = $this->report(['about' => 'provider', 'provider' => $provider->id, 'reason' => 'no_show'])->json('data.id');

        $this->safetyTeam();
        $this->postJson("/api/v1/admin/reports/{$id}/assign")->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->postJson("/api/v1/admin/reports/{$id}/request-information", ['question' => 'Which day was he meant to come?'])->assertOk()
            ->assertJsonPath('data.status', 'info_requested');

        $this->signIn($customer);
        $this->getJson('/api/v1/me/reports')->assertJsonPath('data.0.question', 'Which day was he meant to come?');
        $this->post("/api/v1/me/reports/{$id}/reply", ['reply' => 'Last Tuesday at 9am.', 'photos' => [UploadedFile::fake()->image('sms.png')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'under_review')->assertJsonCount(1, 'data.photos');
        $this->post("/api/v1/me/reports/{$id}/reply", ['reply' => 'Again'], ['Accept' => 'application/json'])->assertStatus(409);

        $this->safetyTeam();
        $this->postJson("/api/v1/admin/reports/{$id}/note", ['note' => 'Checked the chat: he cancelled twice'])->assertOk();
        $row = collect($this->getJson('/api/v1/admin/data')->json('data.ops.reports'))->firstWhere('reportId', $id);
        $this->assertSame('Last Tuesday at 9am.', $row['reporterReply']);
        $this->assertSame(1, $row['evidence']);
        $this->get("/api/v1/admin/reports/{$id}/files/0")->assertOk();
        $this->assertTrue(AuditLog::where('action', 'trust.report_photo_viewed')->exists());
    }

    public function test_resolving_with_a_warning_tells_the_reported_person_but_not_who_reported()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['name' => 'Aisha Nakato']);
        $this->signIn($customer);
        $id = $this->report(['about' => 'provider', 'provider' => $provider->id, 'reason' => 'abusive'])->json('data.id');

        $this->safetyTeam();
        $this->postJson("/api/v1/admin/reports/{$id}/resolve", ['outcome' => 'warned', 'message' => 'We have warned the provider.', 'reason' => 'Rude messages confirmed'])
            ->assertStatus(422)->assertJsonValidationErrors('warning');
        $this->postJson("/api/v1/admin/reports/{$id}/resolve", [
            'outcome' => 'warned', 'message' => 'We have warned the provider.', 'reason' => 'Rude messages confirmed',
            'warning' => 'Please keep messages to customers polite. Repeated abuse will lead to suspension.',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');

        $this->signIn($provider->owner);
        $warnings = $this->getJson('/api/v1/me/warnings')->assertOk()->json('data');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('keep messages to customers polite', $warnings[0]['message']);
        $this->assertStringNotContainsString('Aisha', json_encode($warnings));
        $this->postJson("/api/v1/me/warnings/{$warnings[0]['id']}/read")->assertOk();
        $this->assertNotNull(AccountWarning::sole()->read_at);

        $this->signIn($customer);
        $this->getJson('/api/v1/me/reports')->assertJsonPath('data.0.status', 'resolved')->assertJsonPath('data.0.outcome_message', 'We have warned the provider.');
        $this->assertSame('approved', $provider->fresh()->status);
    }

    public function test_resolving_can_suspend_the_provider_or_the_account()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $this->signIn($customer);
        $first = $this->report(['about' => 'provider', 'provider' => $provider->id, 'reason' => 'scam'])->json('data.id');
        $second = $this->report(['about' => 'provider', 'provider' => $provider->id, 'reason' => 'unsafe'])->json('data.id');

        $this->safetyTeam();
        $this->postJson("/api/v1/admin/reports/{$first}/resolve", ['outcome' => 'provider_suspended', 'message' => 'The provider has been removed.', 'reason' => 'Confirmed fraud'])->assertOk();
        $this->assertSame('suspended', $provider->fresh()->status);
        $this->postJson("/api/v1/admin/reports/{$first}/resolve", ['outcome' => 'no_violation', 'message' => 'x x x x x', 'reason' => 'Again'])->assertStatus(409);

        $this->postJson("/api/v1/admin/reports/{$second}/resolve", ['outcome' => 'account_suspended', 'message' => 'The account has been suspended.', 'reason' => 'Threats confirmed'])->assertOk();
        $this->assertSame('suspended', $provider->owner->fresh()->status);

        // A report about something else has nobody to warn
        $this->signIn($customer);
        $general = $this->report(['about' => 'general', 'reason' => 'payment'])->json('data.id');
        $this->safetyTeam();
        $this->postJson("/api/v1/admin/reports/{$general}/resolve", ['outcome' => 'account_suspended', 'message' => 'Done done', 'reason' => 'Testing'])
            ->assertStatus(422)->assertJson(['code' => 'no_reported_person']);
    }

    public function test_reports_stay_private()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $this->signIn($customer);
        $id = $this->report(['about' => 'provider', 'provider' => $provider->id, 'reason' => 'scam', 'photos' => [UploadedFile::fake()->image('a.png')]])->json('data.id');

        // The reported provider and other people see nothing
        foreach ([$provider->owner, User::factory()->create()] as $other) {
            $this->signIn($other);
            $this->assertSame([], $this->getJson('/api/v1/me/reports')->json('data'));
            $this->get("/api/v1/me/reports/{$id}/files/0", ['Accept' => 'application/json'])->assertNotFound();
            $this->post("/api/v1/me/reports/{$id}/reply", ['reply' => 'Not me'], ['Accept' => 'application/json'])->assertNotFound();
        }

        // Admins without trust permissions can't work it
        $this->signIn($this->userWithRoles(['finance']));
        $this->postJson("/api/v1/admin/reports/{$id}/assign")->assertForbidden();
        $this->get("/api/v1/admin/reports/{$id}/files/0", ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame([], $this->getJson('/api/v1/admin/data')->json('data.ops.reports'));

        // A safety-team member who is the reported provider can't handle it
        $provider->owner->roles()->attach(\App\Models\Role::where('key', 'trust_safety')->value('id'));
        $this->signIn($provider->owner->fresh());
        $this->postJson("/api/v1/admin/reports/{$id}/assign")->assertStatus(403)->assertJson(['code' => 'conflict_of_interest']);
    }

    public function test_reports_made_before_the_reports_table_were_copied()
    {
        // Run by the migration: provider_reports rows appear in reports once
        $provider = $this->provider();
        \Illuminate\Support\Facades\DB::table('provider_reports')->insert([
            'provider_profile_id' => $provider->id, 'reason' => 'scam', 'details' => 'Old report', 'status' => 'dismissed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! class_exists('CopyProviderReportsIntoReports', false)) {
            require database_path('migrations/2026_11_02_000022_copy_provider_reports_into_reports.php');
        }
        $migration = new \CopyProviderReportsIntoReports();
        $migration->up();
        $migration->up();

        $copy = Report::where('details', 'Old report')->sole();
        $this->assertSame('closed', $copy->status);
        $this->assertSame('no_violation', $copy->outcome);
    }
}
