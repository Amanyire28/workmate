<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ProfessionSuggestion;
use App\Models\ProviderApplication;
use App\Models\ProviderProfile;
use App\Models\User;

class ProfessionSuggestionTest extends ApiTestCase
{
    private function details(array $professions): array
    {
        return ['type' => 'individual', 'display_name' => 'Aluminium Works', 'professions' => $professions];
    }

    /** An applicant whose main skill is typed under "Other" */
    private function applicantWithTypedMain(string $name = 'Aluminium fabricator'): User
    {
        $user = $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/provider', $this->details([
            ['other' => $name, 'category' => 'construction', 'years_experience' => 4],
            ['profession' => 'welder', 'years_experience' => 2],
        ]))->assertOk();
        $this->postJson('/api/v1/me/provider/submit')->assertOk();

        return $user;
    }

    private function suggestionFor(User $user): ProfessionSuggestion
    {
        return ProfessionSuggestion::where('provider_profile_id', $user->providerProfile->id)->sole();
    }

    public function test_an_applicant_can_type_a_profession_that_isnt_listed()
    {
        $user = $this->signIn(User::factory()->create());

        $this->putJson('/api/v1/me/provider', $this->details([
            ['other' => '  Aluminium   fabricator ', 'category' => 'construction', 'years_experience' => 4],
            ['profession' => 'welder'],
        ]))
            ->assertOk()
            ->assertJsonPath('data.professions.0', [
                'profession' => null, 'other' => 'Aluminium fabricator', 'category' => 'construction', 'name' => 'Aluminium fabricator',
                'years_experience' => 4, 'is_primary' => true, 'awaiting_approval' => true,
            ])
            ->assertJsonPath('data.professions.1.profession', 'welder')
            ->assertJsonPath('data.professions.1.is_primary', false);

        // Saving again keeps one suggestion; removing it from the list removes it
        $this->putJson('/api/v1/me/provider', $this->details([['other' => 'aluminium fabricator'], ['profession' => 'welder']]))->assertOk();
        $this->assertSame(1, ProfessionSuggestion::count());
        $this->putJson('/api/v1/me/provider', $this->details([['profession' => 'welder']]))->assertOk();
        $this->assertSame(0, ProfessionSuggestion::count());
        $this->assertTrue((bool) $user->providerProfile->professions()->first()->pivot->is_primary);
    }

    public function test_typed_professions_are_checked()
    {
        $this->signIn(User::factory()->create());

        $this->putJson('/api/v1/me/provider', $this->details([
            ['other' => 'plumber'],
            ['other' => 'Kite maker'],
            ['other' => 'kite maker'],
            ['other' => 'ab'],
            [],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['professions.0.other', 'professions.2.other', 'professions.3.other', 'professions.4.profession']);

        $this->putJson('/api/v1/me/provider', $this->details([
            ['profession' => 'plumber'], ['profession' => 'mason'], ['profession' => 'tiler'], ['profession' => 'roofer'], ['profession' => 'welder'], ['other' => 'Kite maker'],
        ]))->assertStatus(422)->assertJsonValidationErrors('professions');
    }

    public function test_customers_cannot_find_anyone_by_a_typed_profession_and_approval_waits_for_it()
    {
        $user = $this->applicantWithTypedMain();
        $application = ProviderApplication::sole();

        $this->signIn($this->userWithRoles(['provider_review']));
        $this->postJson("/api/v1/admin/provider-applications/{$application->id}/approve")
            ->assertStatus(409)->assertJson(['code' => 'professions_unsettled']);

        $this->assertSame([], $this->getJson('/api/v1/providers')->json('data'));
        $this->assertStringNotContainsString('Aluminium', $this->getJson('/api/v1/categories')->getContent());
        $this->assertSame(ProviderProfile::STATUS_PENDING_REVIEW, $user->providerProfile->fresh()->status);
    }

    public function test_adding_a_typed_profession_gives_it_to_everyone_who_typed_it_and_keeps_it_as_their_main_one()
    {
        $first = $this->applicantWithTypedMain();
        $second = $this->applicantWithTypedMain('aluminium Fabricator');

        $admin = $this->signIn($this->userWithRoles(['provider_review']));
        $this->postJson('/api/v1/admin/profession-suggestions/'.$this->suggestionFor($first)->id.'/add', [
            'category' => 'construction', 'name' => 'Aluminium fabricator', 'plural_name' => 'aluminium fabricators',
        ])->assertOk()->assertJsonPath('data.applicants', 2);

        $this->assertSame('added', $this->suggestionFor($first)->status);
        $this->assertSame('matched', $this->suggestionFor($second)->status);
        foreach ([$first, $second] as $user) {
            $main = $user->providerProfile->professions()->wherePivot('is_primary', true)->sole();
            $this->assertSame('aluminium_fabricator', $main->slug);
        }

        $category = collect($this->getJson('/api/v1/categories')->json('data'))->firstWhere('slug', 'construction');
        $this->assertContains('aluminium_fabricator', array_column($category['professions'], 'slug'));

        // Now the application can be approved
        $this->postJson('/api/v1/admin/provider-applications/'.ProviderApplication::where('provider_profile_id', $first->providerProfile->id)->value('id').'/approve')->assertOk();

        $this->assertTrue(AuditLog::where('action', 'profession.suggestion_added')->where('actor_id', $admin->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'profession.created')->exists());

        // A name that's already listed can't be added twice
        $third = $this->applicantWithTypedMain('Alu fabricator');
        $this->signIn($admin);
        $this->postJson('/api/v1/admin/profession-suggestions/'.$this->suggestionFor($third)->id.'/add', [
            'category' => 'construction', 'name' => 'Aluminium Fabricator', 'plural_name' => 'aluminium fabricators',
        ])->assertStatus(409)->assertJson(['code' => 'profession_exists']);
    }

    public function test_matching_and_declining()
    {
        $matched = $this->applicantWithTypedMain('Pipe fitter');
        $declined = $this->applicantWithTypedMain('Fortune teller');

        $this->signIn($this->userWithRoles(['provider_review']));
        $this->postJson('/api/v1/admin/profession-suggestions/'.$this->suggestionFor($matched)->id.'/match', ['profession' => 'plumber'])->assertOk();
        $this->postJson('/api/v1/admin/profession-suggestions/'.$this->suggestionFor($declined)->id.'/decline', ['reason' => 'WorkMate doesn’t list this service.'])->assertOk();
        $this->postJson('/api/v1/admin/profession-suggestions/'.$this->suggestionFor($declined)->id.'/match', ['profession' => 'plumber'])
            ->assertStatus(409)->assertJson(['code' => 'suggestion_already_decided']);

        $this->assertSame('plumber', $matched->providerProfile->professions()->wherePivot('is_primary', true)->value('slug'));

        // The applicant sees the reason
        $this->signIn($declined);
        $this->getJson('/api/v1/me/provider')
            ->assertJsonPath('data.declined_professions.0', ['name' => 'Fortune teller', 'reason' => 'WorkMate doesn’t list this service.'])
            ->assertJsonPath('data.professions.0.profession', 'welder');
    }

    public function test_only_reviewers_decide_and_never_on_their_own_application()
    {
        $applicant = $this->applicantWithTypedMain();
        $suggestion = $this->suggestionFor($applicant);

        $this->signIn(User::factory()->create());
        $this->postJson("/api/v1/admin/profession-suggestions/{$suggestion->id}/decline", ['reason' => 'Not a service'])->assertForbidden();
        $this->signIn($this->userWithRoles(['support']));
        $this->postJson("/api/v1/admin/profession-suggestions/{$suggestion->id}/decline", ['reason' => 'Not a service'])->assertForbidden();

        // A reviewer who is also the applicant
        $applicant->roles()->attach(\App\Models\Role::where('key', 'provider_review')->value('id'));
        $this->signIn($applicant->fresh());
        $this->postJson("/api/v1/admin/profession-suggestions/{$suggestion->id}/decline", ['reason' => 'Not a service'])
            ->assertStatus(403)->assertJson(['code' => 'conflict_of_interest']);

        $this->assertSame('pending', $suggestion->fresh()->status);
    }

    public function test_reviewers_see_typed_professions_in_admin_data()
    {
        $this->applicantWithTypedMain();

        $this->signIn($this->userWithRoles(['provider_review']));
        $rows = $this->getJson('/api/v1/admin/data')->assertOk()->json('data.ops.professionSuggestions');
        $this->assertSame('Aluminium fabricator', $rows[0]['name']);
        $this->assertTrue($rows[0]['main']);

        $this->signIn($this->userWithRoles(['finance']));
        $this->assertSame([], $this->getJson('/api/v1/admin/data')->json('data.ops.professionSuggestions'));
    }
}
