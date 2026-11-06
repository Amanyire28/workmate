<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Profession;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\ProviderApplications;

class CatalogueAndSavedTest extends ApiTestCase
{
    private function provider(?User $owner = null): ProviderProfile
    {
        $owner = $owner ?: User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function catalogueSlugs(): array
    {
        $this->app['auth']->forgetGuards();

        return collect($this->getJson('/api/v1/categories')->json('data'))->pluck('slug')->all();
    }

    /* ---------------- Categories and professions ---------------- */

    public function test_a_new_category_starts_hidden_and_needs_a_profession_to_show()
    {
        $admin = $this->signIn($this->userWithRoles(['operations']));
        $this->postJson('/api/v1/admin/categories', ['name' => 'Solar & Energy', 'description' => 'Solar panels and inverters', 'icon' => 'bi-sun'])
            ->assertCreated()->assertJsonPath('data.id', 'solar-energy')->assertJsonPath('data.visible', false);
        $this->assertNotContains('solar-energy', $this->catalogueSlugs());

        $this->signIn($admin);
        $this->postJson('/api/v1/admin/categories/solar-energy/show')->assertStatus(422)->assertJson(['code' => 'category_empty']);
        $this->postJson('/api/v1/admin/professions', ['category' => 'solar-energy', 'name' => 'Biogas technician', 'plural_name' => 'biogas technicians'])
            ->assertCreated()->assertJsonPath('data.id', 'biogas_technician');
        $this->postJson('/api/v1/admin/categories/solar-energy/show')->assertOk()->assertJsonPath('data.visible', true);
        $this->assertContains('solar-energy', $this->catalogueSlugs());

        // Applicants can choose it now
        $applicant = $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/provider', ['type' => 'individual', 'display_name' => 'Sunny Solar', 'professions' => [['profession' => 'biogas_technician']]])->assertOk();

        // Hiding needs a reason, and takes it away from new choices
        $this->signIn($admin);
        $this->postJson('/api/v1/admin/professions/biogas_technician/hide')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/admin/professions/biogas_technician/hide', ['reason' => 'Merged into electrical work'])->assertOk()->assertJsonPath('data.visible', false);
        $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/provider', ['type' => 'individual', 'display_name' => 'Late Solar', 'professions' => [['profession' => 'biogas_technician']]])->assertStatus(422);
        $this->assertNotNull($applicant->providerProfile);

        $this->assertTrue(AuditLog::where('action', 'catalogue.category_created')->where('actor_id', $admin->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'catalogue.profession_hidden')->where('reason', 'Merged into electrical work')->exists());
    }

    public function test_renaming_keeps_the_slug_and_names_stay_unique()
    {
        $this->signIn($this->userWithRoles(['operations']));
        $this->patchJson('/api/v1/admin/categories/plumbing', ['name' => 'Plumbing & Water', 'icon' => 'bi-droplet'])->assertOk()
            ->assertJsonPath('data.id', 'plumbing')->assertJsonPath('data.name', 'Plumbing & Water');
        $this->patchJson('/api/v1/admin/categories/plumbing', ['icon' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('icon');
        $this->postJson('/api/v1/admin/categories', ['name' => 'cleaning'])->assertStatus(409)->assertJson(['code' => 'name_taken']);
        $this->postJson('/api/v1/admin/professions', ['category' => 'plumbing', 'name' => 'PLUMBER', 'plural_name' => 'plumbers'])->assertStatus(409);

        // A profession can move to another category and be renamed
        $this->patchJson('/api/v1/admin/professions/plumber', ['category' => 'construction', 'plural_name' => 'plumbers and fitters'])->assertOk()
            ->assertJsonPath('data.category', 'construction')->assertJsonPath('data.id', 'plumber');
        $this->assertSame('Plumbing & Water', Category::where('slug', 'plumbing')->value('name'));
    }

    public function test_categories_move_up_and_down()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $order = function () {
            return Category::orderBy('sort_order')->orderBy('name')->pluck('slug')->all();
        };
        $before = $order();
        $second = $before[1];

        $this->postJson("/api/v1/admin/categories/{$second}/move", ['direction' => 'up'])->assertOk();
        $this->assertSame($second, $order()[0]);
        $this->postJson("/api/v1/admin/categories/{$second}/move", ['direction' => 'up'])->assertOk();
        $this->assertSame($second, $order()[0], 'The first stays first');
        $this->postJson("/api/v1/admin/categories/{$second}/move", ['direction' => 'down'])->assertOk();
        $this->assertSame($before, $order());
        $this->postJson("/api/v1/admin/categories/{$second}/move", ['direction' => 'sideways'])->assertStatus(422);
    }

    public function test_only_catalogue_managers_change_it()
    {
        foreach (['provider_review', 'support', 'content'] as $role) {
            $this->signIn($this->userWithRoles([$role]));
            $this->postJson('/api/v1/admin/categories', ['name' => 'Not Allowed'])->assertForbidden();
            $this->postJson('/api/v1/admin/categories/plumbing/hide', ['reason' => 'Not allowed to'])->assertForbidden();
            $this->patchJson('/api/v1/admin/professions/plumber', ['name' => 'Nope'])->assertForbidden();
        }
        $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/admin/professions', ['category' => 'plumbing', 'name' => 'Nope', 'plural_name' => 'nopes'])->assertForbidden();
        $this->assertSame(0, Category::where('name', 'Not Allowed')->count());

        // Admins who can see the marketplace get every profession with its provider count
        $this->provider();
        $this->signIn($this->userWithRoles(['operations']));
        $rows = collect($this->getJson('/api/v1/admin/data')->json('data.catalogueProfessions'));
        $this->assertSame(1, $rows->firstWhere('id', 'plumber')['providers']);
        $this->assertSame(Profession::count(), $rows->count());
    }

    /* ---------------- Saved providers ---------------- */

    public function test_customers_save_providers_once_and_remove_them()
    {
        $provider = $this->provider();
        $this->signIn(User::factory()->create());

        $this->postJson("/api/v1/providers/{$provider->id}/save")->assertOk()->assertJsonPath('data.saved', true);
        $this->postJson("/api/v1/providers/{$provider->id}/save")->assertOk();
        $this->getJson('/api/v1/me/saved-providers')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider_id', $provider->id)->assertJsonPath('data.0.available', true);

        // A provider who stops being listed stays in the list, marked, so it can be removed
        $provider->forceFill(['status' => ProviderProfile::STATUS_SUSPENDED])->save();
        $this->getJson('/api/v1/me/saved-providers')->assertJsonPath('data.0.available', false);
        $this->postJson("/api/v1/providers/{$provider->id}/save")->assertNotFound();
        $this->deleteJson("/api/v1/providers/{$provider->id}/save")->assertOk();
        $this->getJson('/api/v1/me/saved-providers')->assertJsonCount(0, 'data');
    }

    public function test_saved_lists_are_private_and_own_profiles_cannot_be_saved()
    {
        $provider = $this->provider();
        $this->postJson("/api/v1/providers/{$provider->id}/save")->assertUnauthorized();
        $this->getJson('/api/v1/me/saved-providers')->assertUnauthorized();

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/providers/{$provider->id}/save")->assertStatus(422)->assertJson(['code' => 'own_provider']);

        $this->signIn(User::factory()->create());
        $this->postJson("/api/v1/providers/{$provider->id}/save")->assertOk();
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/me/saved-providers')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/providers/999999/save')->assertNotFound();
    }
}
