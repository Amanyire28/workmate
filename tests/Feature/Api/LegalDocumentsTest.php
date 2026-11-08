<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\LegalDocuments;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\Notification;

class LegalDocumentsTest extends ApiTestCase
{
    private const EVERYONE = ['terms', 'privacy', 'customer', 'community', 'disputes', 'verification', 'safety'];

    private function pendingKeys(): array
    {
        return collect($this->getJson('/api/v1/me')->assertOk()->json('data.legal_pending'))->pluck('key')->all();
    }

    private function currentIds(array $keys): array
    {
        $legal = app(LegalDocuments::class);

        return collect($keys)->map(function ($key) use ($legal) {
            return $legal->current($legal->find($key))->id;
        })->all();
    }

    /** Publishes a new version of a document as a super administrator */
    private function publishNewVersion(string $key, bool $material, string $body = "## 1. Changed\n\nNew text."): LegalDocumentVersion
    {
        $admin = $this->signIn($this->userWithRoles(['super_admin']));
        $this->travel(1)->minutes();
        $id = $this->postJson("/api/v1/admin/legal/{$key}/drafts")->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/admin/legal/versions/{$id}", ['body' => $body, 'change_note' => 'Clearer wording', 'is_material' => $material])->assertOk();
        $this->postJson("/api/v1/admin/legal/versions/{$id}/publish", ['reason' => 'Reviewed by our advocate'])->assertOk();

        return LegalDocumentVersion::findOrFail($id);
    }

    /* ---------------- Reading ---------------- */

    public function test_anyone_can_read_the_published_documents()
    {
        $list = $this->getJson('/api/v1/legal')->assertOk()->json('data');
        $this->assertSame(array_merge(['terms', 'privacy', 'provider'], array_slice(self::EVERYONE, 2)), collect($list)->pluck('key')->all());
        $this->assertSame('1.0', $list[0]['version']);

        $terms = $this->getJson('/api/v1/legal/terms')->assertOk()->json('data');
        $this->assertTrue($terms['is_current']);
        $this->assertStringContainsString('<h2>1. Introduction</h2>', $terms['html']);
        // Facts not set yet show as "to be confirmed"; the published text has no drafting notes
        $this->assertStringContainsString('[Registered office: to be confirmed]', $terms['html']);
        $this->assertStringContainsString('Email: resnetsystems@gmail.com', $terms['html']);
        // The company number and PDPO certificate number aren't published
        $this->assertStringNotContainsString('Registration number', $terms['html']);
        $this->assertStringNotContainsString('Pdpo certificate', $terms['html']);
        // Version 1.0 is final: everyone accepts it, with no notes or features the app lacks
        foreach (collect($list)->pluck('key') as $key) {
            $doc = $this->getJson("/api/v1/legal/{$key}")->json('data');
            $this->assertTrue($doc['is_material'], $key);
            foreach (['wm-legal-note', '[[', 'proposed', 'Download my data', 'Delete my account', 'Account → Privacy'] as $text) {
                $this->assertStringNotContainsString($text, $doc['html'], $key);
            }
            $this->assertArrayNotHasKey('change_note', $doc['versions'][0], $key);
        }
        // Proposed figures stay as plain text
        $this->assertStringContainsString('30 days', $this->getJson('/api/v1/legal/privacy')->json('data.html'));

        $this->getJson('/api/v1/legal/nothing-here')->assertNotFound();
    }

    public function test_drafting_notes_in_a_new_version_are_highlighted()
    {
        $version = $this->publishNewVersion('terms', false, "## 1. Changed\n\nWithin [[14]] days.");
        $this->app['auth']->forgetGuards();

        $html = $this->getJson('/api/v1/legal/terms?version_id='.$version->id)->assertOk()->json('data.html');
        $this->assertStringContainsString('<mark class="wm-legal-note" title="Drafting note">14</mark>', $html);
    }

    public function test_company_facts_fill_the_documents_and_html_in_the_text_is_escaped()
    {
        $admin = $this->userWithRoles(['super_admin']);
        app(PlatformSettings::class)->set('legal.registered_office', 'Plot 12 Kampala Road', $admin);
        app(PlatformSettings::class)->set('legal.directors', '<b>A. Director</b>', $admin);

        $html = $this->getJson('/api/v1/legal/terms')->json('data.html');
        $this->assertStringContainsString('Registered office: Plot 12 Kampala Road', $html);
        $this->assertStringContainsString('&lt;b&gt;A. Director&lt;/b&gt;', $html);

        $this->publishNewVersion('terms', false, "## 1. Test\n\n<script>alert(1)</script> {{registered_office}}");
        $html = $this->getJson('/api/v1/legal/terms')->json('data.html');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Plot 12 Kampala Road', $html);
    }

    public function test_drafts_stay_private_and_earlier_versions_stay_readable()
    {
        $first = app(LegalDocuments::class)->current(LegalDocument::where('key', 'terms')->first());
        $admin = $this->signIn($this->userWithRoles(['super_admin']));
        $draftId = $this->postJson('/api/v1/admin/legal/terms/drafts')->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/legal/terms?version_id={$draftId}")->assertNotFound();
        $this->getJson('/api/v1/legal/terms')->assertJsonPath('data.version', '1.0');

        $second = $this->publishNewVersion('privacy', true);
        $this->app['auth']->forgetGuards();
        $old = $this->getJson('/api/v1/legal/privacy?version_id='.$second->id)->assertOk();
        $this->assertTrue($old->json('data.is_current'));
        $this->assertCount(3, $old->json('data.versions'));
        $this->getJson('/api/v1/legal/terms?version_id='.$first->id)->assertOk()->assertJsonPath('data.version', '1.0');
    }

    /* ---------------- Signing up ---------------- */

    public function test_signing_up_needs_the_terms_ticked_and_records_what_was_accepted()
    {
        Notification::fake();
        $form = ['name' => 'Nakato Sarah', 'email' => 'sarah@example.com', 'password' => 'kampala2026', 'password_confirmation' => 'kampala2026'];

        $this->fromWebsite()->postJson('/api/v1/auth/register', $form)
            ->assertStatus(422)->assertJsonValidationErrors('accept_terms');
        $this->assertDatabaseMissing('users', ['email' => 'sarah@example.com']);

        $res = $this->fromWebsite()->postJson('/api/v1/auth/register', $form + ['accept_terms' => true, 'marketing' => true])->assertCreated();
        // Nothing left to accept: providers' agreement only applies once they apply
        $this->assertSame([], $res->json('data.legal_pending'));

        $user = User::where('email', 'sarah@example.com')->first();
        $this->assertNotNull($user->adult_confirmed_at);
        $this->assertSame(7, LegalAcceptance::where('user_id', $user->id)->where('method', 'sign_up')->count());
        $this->assertTrue(UserConsent::where('user_id', $user->id)->where('purpose', 'marketing')->value('granted'));
    }

    public function test_marketing_is_off_unless_ticked()
    {
        Notification::fake();
        $this->fromWebsite()->postJson('/api/v1/auth/register', [
            'name' => 'Okello John', 'email' => 'john@example.com', 'password' => 'kampala2026', 'password_confirmation' => 'kampala2026', 'accept_terms' => true,
        ])->assertCreated();

        $user = User::where('email', 'john@example.com')->first();
        $this->assertSame(0, UserConsent::where('user_id', $user->id)->count());
        $this->signIn($user);
        $this->getJson('/api/v1/me/legal')->assertOk()->assertJsonPath('data.consents.marketing', false);
    }

    /* ---------------- Being asked to accept ---------------- */

    public function test_existing_accounts_are_asked_once_and_not_again_for_unchanged_documents()
    {
        $this->signIn(User::factory()->create());
        $this->assertSame(self::EVERYONE, $this->pendingKeys());

        $this->postJson('/api/v1/me/legal/accept', ['version_ids' => $this->currentIds(self::EVERYONE)])
            ->assertOk()->assertJsonPath('data.legal_pending', []);
        $this->assertSame([], $this->pendingKeys());

        // Accepting the same versions again changes nothing
        $this->postJson('/api/v1/me/legal/accept', ['version_ids' => $this->currentIds(['terms'])])->assertOk();
        $this->assertSame(7, LegalAcceptance::count());
        $this->assertSame(7, AuditLog::where('action', 'legal.accepted')->count());
    }

    public function test_a_material_change_asks_again_but_a_minor_one_does_not()
    {
        $user = User::factory()->create();
        app(LegalDocuments::class)->accept($user, $this->currentIds(self::EVERYONE), 'prompt');

        $this->publishNewVersion('privacy', false);
        $this->signIn($user);
        $this->assertSame([], $this->pendingKeys());

        $new = $this->publishNewVersion('terms', true);
        $this->signIn($user);
        $pending = $this->getJson('/api/v1/me')->json('data.legal_pending');
        $this->assertSame(['terms'], array_column($pending, 'key'));
        $this->assertTrue($pending[0]['is_update']);
        $this->assertSame('Clearer wording', $pending[0]['change_note']);

        $this->postJson('/api/v1/me/legal/accept', ['version_ids' => [$new->id]])->assertOk();
        $this->assertSame([], $this->pendingKeys());
    }

    public function test_only_current_versions_can_be_accepted()
    {
        $old = $this->currentIds(['terms'])[0];
        $this->publishNewVersion('terms', true);
        $admin = $this->signIn($this->userWithRoles(['super_admin']));
        $draftId = $this->postJson('/api/v1/admin/legal/terms/drafts')->assertCreated()->json('data.id');

        $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/me/legal/accept', ['version_ids' => [$old]])->assertStatus(422)->assertJson(['code' => 'legal_version_not_current']);
        $this->postJson('/api/v1/me/legal/accept', ['version_ids' => [$draftId]])->assertStatus(422);
        // Customers aren't bound by the providers' agreement until they apply
        $this->postJson('/api/v1/me/legal/accept', ['version_ids' => $this->currentIds(['provider'])])->assertStatus(422);
        $this->assertSame(0, LegalAcceptance::count());
    }

    public function test_providers_are_asked_for_the_provider_agreement_when_they_apply()
    {
        $user = $this->signIn(User::factory()->create(['email_verified_at' => now()]));
        $this->putJson('/api/v1/me/provider', ['type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda'])->assertOk();
        // A draft isn't interrupted: they agree when sending
        $this->assertNotContains('provider', $this->pendingKeys());

        $this->postJson('/api/v1/me/provider/submit')->assertStatus(422)->assertJsonValidationErrors('accept_provider_terms');
        $this->postJson('/api/v1/me/provider/submit', ['accept_provider_terms' => true])->assertOk();
        $this->assertTrue(LegalAcceptance::where('user_id', $user->id)->where('method', 'provider_application')->exists());
        $this->assertNotContains('provider', $this->pendingKeys());
    }

    public function test_nobody_is_asked_while_acceptance_is_switched_off()
    {
        app(PlatformSettings::class)->set('legal.ask_acceptance', false, $this->userWithRoles(['super_admin']));
        $this->signIn(User::factory()->create());
        $this->assertSame([], $this->pendingKeys());
        $this->assertFalse($this->getJson('/api/v1/settings')->json('data')['legal.ask_acceptance']);
    }

    /* ---------------- My record and choices ---------------- */

    public function test_people_see_only_their_own_acceptances_and_can_change_marketing()
    {
        $other = User::factory()->create();
        app(LegalDocuments::class)->accept($other, $this->currentIds(['terms']), 'prompt');

        $me = $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/me/legal/accept', ['version_ids' => $this->currentIds(['privacy'])])->assertOk();
        $mine = $this->getJson('/api/v1/me/legal')->assertOk()->json('data');
        $this->assertSame(['privacy'], array_column($mine['accepted'], 'key'));

        $this->putJson('/api/v1/me/consents', ['purpose' => 'marketing', 'granted' => true])->assertOk()->assertJsonPath('data.marketing', true);
        $this->putJson('/api/v1/me/consents', ['purpose' => 'marketing', 'granted' => false])->assertOk()->assertJsonPath('data.marketing', false);
        // Each change is kept as a record
        $this->assertSame([true, false], UserConsent::where('user_id', $me->id)->orderBy('id')->pluck('granted')->all());
        $this->assertSame(1, AuditLog::where('action', 'consent.withdrawn')->count());

        $this->putJson('/api/v1/me/consents', ['purpose' => 'selling', 'granted' => true])->assertStatus(422);
    }

    public function test_signed_out_people_cannot_accept_or_set_choices()
    {
        $this->getJson('/api/v1/me/legal')->assertUnauthorized();
        $this->postJson('/api/v1/me/legal/accept', ['version_ids' => $this->currentIds(['terms'])])->assertUnauthorized();
        $this->putJson('/api/v1/me/consents', ['purpose' => 'marketing', 'granted' => true])->assertUnauthorized();
    }

    /* ---------------- Administration ---------------- */

    public function test_only_legal_managers_can_manage_documents()
    {
        foreach ([[], ['operations'], ['support']] as $roles) {
            $this->signIn($this->userWithRoles($roles));
            $this->getJson('/api/v1/admin/legal')->assertForbidden();
            $this->postJson('/api/v1/admin/legal/terms/drafts')->assertForbidden();
        }

        $this->signIn($this->userWithRoles(['super_admin']));
        $data = $this->getJson('/api/v1/admin/legal')->assertOk()->json('data');
        $this->assertCount(8, $data['documents']);
        $this->assertContains('registration_number', $data['placeholders']);
    }

    public function test_publishing_needs_a_change_note_and_published_versions_never_change()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $id = $this->postJson('/api/v1/admin/legal/terms/drafts')->assertCreated()->assertJsonPath('data.version', '1.1')->json('data.id');
        // One draft at a time
        $this->postJson('/api/v1/admin/legal/terms/drafts')->assertStatus(409)->assertJson(['code' => 'legal_draft_exists']);

        $this->postJson("/api/v1/admin/legal/versions/{$id}/publish", ['reason' => 'Ready to go'])->assertStatus(422)->assertJson(['code' => 'legal_change_note']);
        $this->patchJson("/api/v1/admin/legal/versions/{$id}", ['version' => '0.1'])->assertStatus(422)->assertJson(['code' => 'legal_version_taken']);
        $this->patchJson("/api/v1/admin/legal/versions/{$id}", ['change_note' => 'Added the company number'])->assertOk();
        $this->postJson("/api/v1/admin/legal/versions/{$id}/publish", ['reason' => 'Ready to go'])->assertOk();

        $this->patchJson("/api/v1/admin/legal/versions/{$id}", ['body' => 'Rewritten'])->assertStatus(409)->assertJson(['code' => 'legal_published']);
        $this->deleteJson("/api/v1/admin/legal/versions/{$id}")->assertStatus(409);
        $this->assertSame(['legal.draft_created', 'legal.draft_updated', 'legal.published'], AuditLog::where('action', 'like', 'legal.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_drafts_can_be_discarded_and_previewed()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $id = $this->postJson('/api/v1/admin/legal/safety/drafts')->json('data.id');
        $this->patchJson("/api/v1/admin/legal/versions/{$id}", ['body' => "## 1. New rule\n\nNo spam."])->assertOk();
        $this->getJson("/api/v1/admin/legal/versions/{$id}/preview")->assertOk()->assertJsonPath('data.status', 'draft');

        $this->deleteJson("/api/v1/admin/legal/versions/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('legal_document_versions', ['id' => $id]);
        $this->assertSame(1, AuditLog::where('action', 'legal.draft_discarded')->count());
    }

    public function test_acceptances_can_be_exported_and_the_export_is_logged()
    {
        $user = User::factory()->create(['name' => '=HYPERLINK("x")']);
        app(LegalDocuments::class)->accept($user, $this->currentIds(['terms']), 'prompt');
        $version = $this->currentIds(['terms'])[0];

        $this->signIn($this->userWithRoles(['operations']));
        $this->get("/api/v1/admin/legal/versions/{$version}/acceptances.csv")->assertForbidden();

        $this->signIn($this->userWithRoles(['super_admin']));
        $csv = $this->get("/api/v1/admin/legal/versions/{$version}/acceptances.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString("user_id,name,accepted_at,method", $csv);
        // Names that look like formulas can't run in a spreadsheet
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertSame(1, AuditLog::where('action', 'legal.acceptances_exported')->count());
    }

    public function test_company_facts_are_settings_only_legal_managers_change()
    {
        $this->signIn($this->userWithRoles(['operations']));
        $this->patchJson('/api/v1/admin/settings/legal.registered_office', ['value' => '123'])->assertNotFound();

        $this->signIn($this->userWithRoles(['super_admin']));
        $this->patchJson('/api/v1/admin/settings/legal.registered_office', ['value' => 'Plot 12 Kampala Road'])->assertOk();
        $this->patchJson('/api/v1/admin/settings/legal.privacy_email', ['value' => 'not-an-email'])->assertStatus(422);
        $this->assertStringContainsString('Plot 12 Kampala Road', $this->getJson('/api/v1/legal/terms')->json('data.html'));
    }
}
