<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Carbon;

class ApiConventionsTest extends ApiTestCase
{
    public function test_health_check_is_public_and_reveals_nothing_internal()
    {
        Carbon::setTestNow(now());

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['data' => [
                'status' => 'ok',
                'api_version' => 'v1',
                'time' => now()->toIso8601String(),
            ]]);
    }

    public function test_guests_get_a_json_401_even_without_an_accept_header()
    {
        $this->get('/api/v1/me')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Please sign in to continue.', 'code' => 'unauthenticated']);
    }

    public function test_unknown_api_urls_get_a_json_404_not_the_vue_page()
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJson(['code' => 'not_found']);
    }

    public function test_the_vue_app_still_serves_site_and_admin_pages()
    {
        $this->get('/')->assertOk()->assertViewIs('app');
        $this->get('/admin/finance/overview')->assertOk()->assertViewIs('app');
    }

    public function test_validation_errors_list_the_fields()
    {
        $this->signIn($this->userWithRoles(['super_admin']));

        $this->patchJson('/api/v1/admin/settings/search.nearby_km', ['value' => 'far'])
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_failed'])
            ->assertJsonValidationErrors('value');
    }

    public function test_server_errors_never_leak_internal_details()
    {
        config(['app.debug' => true]);
        \Illuminate\Support\Facades\Route::get('api/v1/_boom', function () {
            throw new \RuntimeException('SQLSTATE secret-table-name');
        });

        $response = $this->getJson('/api/v1/_boom')->assertStatus(500);

        $response->assertExactJson([
            'message' => 'Something went wrong on our side. Please try again.',
            'code' => 'server_error',
        ]);
        $this->assertStringNotContainsString('secret-table-name', $response->getContent());
    }

    public function test_me_returns_the_account_without_secrets()
    {
        $user = $this->signIn($this->userWithRoles(['finance']));

        $response = $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles', ['finance'])
            ->assertJsonPath('data.permissions', ['activity.view', 'analytics.view', 'dashboard.view', 'finance.summary', 'finance.view']);

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data'));
    }
}
