<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ProviderProfile;
use App\Models\ProviderService;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ProviderApplications;

class ProviderServicesTest extends ApiTestCase
{
    /** A provider offering plumbing (and electrical work), approved unless asked otherwise */
    private function provider(bool $approve = true): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, [
            'type' => 'individual', 'display_name' => 'Okello Services '.$owner->id,
            'professions' => [['profession' => 'plumber'], ['profession' => 'electrician']], 'base_area' => 'ntinda',
        ]);
        if ($approve) {
            $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));
        }

        return $owner->providerProfile()->first();
    }

    private function add(array $data = [])
    {
        return $this->postJson('/api/v1/me/provider/services', $data + [
            'title' => 'Socket installation', 'pricing_type' => 'from', 'price' => 30000, 'category' => 'electrical',
        ]);
    }

    public function test_a_provider_lists_services_with_their_usual_prices()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);

        $socket = $this->add(['description' => 'Supply and fit, per socket', 'price_unit' => 'item'])->assertCreated()
            ->assertJsonPath('data.title', 'Socket installation')
            ->assertJsonPath('data.category', 'electrical')
            ->assertJsonPath('data.pricing_type', 'from')
            ->assertJsonPath('data.price', 30000)
            ->assertJsonPath('data.price_unit', 'item')
            ->assertJsonPath('data.is_active', true)
            ->json('data');
        $this->add(['title' => 'House wiring', 'pricing_type' => 'quote', 'price' => 99999, 'price_unit' => 'job'])->assertCreated()
            // A quote has no price or unit, whatever was sent
            ->assertJsonPath('data.price', null)->assertJsonPath('data.price_unit', null);
        // No category: their main profession's
        $this->add(['title' => 'Electrical inspection', 'pricing_type' => 'fixed', 'price' => 50000, 'price_unit' => 'visit', 'category' => null])
            ->assertCreated()->assertJsonPath('data.category', 'plumbing');

        // Customers (signed out too) see them in order
        $this->app['auth']->forgetGuards();
        $public = $this->getJson("/api/v1/providers/{$provider->id}/services")->assertOk()->json('data');
        $this->assertSame(['Socket installation', 'House wiring', 'Electrical inspection'], array_column($public, 'title'));
        $this->assertArrayNotHasKey('is_active', $public[0]);
        $this->assertSame(3, AuditLog::where('action', 'provider.service_added')->count());
        $this->assertSame($socket['id'], $public[0]['id']);
    }

    public function test_the_owner_edits_deactivates_reorders_and_removes_services()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $a = $this->add()->json('data.id');
        $b = $this->add(['title' => 'Fix a leaking tap', 'category' => 'plumbing', 'pricing_type' => 'fixed', 'price' => 20000])->json('data.id');

        $this->patchJson("/api/v1/me/provider/services/{$a}", ['price' => 35000])->assertOk()->assertJsonPath('data.price', 35000);
        // Switching to a quote clears the price
        $this->patchJson("/api/v1/me/provider/services/{$a}", ['pricing_type' => 'quote'])->assertOk()->assertJsonPath('data.price', null);

        // Deactivated: hidden from customers, still in the owner's list
        $this->patchJson("/api/v1/me/provider/services/{$b}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame([$a], array_column($this->getJson("/api/v1/providers/{$provider->id}/services")->json('data'), 'id'));
        $this->assertCount(2, $this->getJson('/api/v1/me/provider/services')->json('data'));

        $this->postJson('/api/v1/me/provider/services/order', ['ids' => [$b, $a]])->assertOk()->assertJsonPath('data.0.id', $b);
        // The whole list, and only theirs
        $this->postJson('/api/v1/me/provider/services/order', ['ids' => [$a]])->assertStatus(409);

        $this->deleteJson("/api/v1/me/provider/services/{$b}")->assertOk();
        $this->assertSame(1, ProviderService::count());
        $this->assertSame(1, AuditLog::where('action', 'provider.service_removed')->count());
    }

    public function test_prices_and_details_are_checked()
    {
        $this->signIn($this->provider()->owner);

        $this->add(['title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->add(['pricing_type' => 'free'])->assertStatus(422)->assertJsonValidationErrors('pricing_type');
        $this->add(['price' => 100])->assertStatus(422)->assertJsonPath('errors.price.0', 'Enter a price of at least UGX 500.');
        $this->add(['price' => 25000.5])->assertStatus(422)->assertJsonValidationErrors('price');
        $this->add(['price' => null])->assertStatus(422)->assertJsonPath('code', 'service_price');
        $this->add(['price_unit' => 'week'])->assertStatus(422)->assertJsonValidationErrors('price_unit');
        // Only a service they offer
        $this->add(['category' => 'cleaning'])->assertStatus(422)->assertJsonPath('code', 'service_category');
        $this->assertSame(0, ProviderService::count());
    }

    public function test_nobody_else_can_change_a_providers_services()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $id = $this->add()->json('data.id');

        // Another provider, and a customer
        foreach ([$this->provider()->owner, User::factory()->create()] as $other) {
            $this->signIn($other);
            $this->patchJson("/api/v1/me/provider/services/{$id}", ['price' => 1000])->assertStatus($other->providerProfile ? 404 : 409);
            $this->deleteJson("/api/v1/me/provider/services/{$id}")->assertStatus($other->providerProfile ? 404 : 409);
            $this->postJson('/api/v1/me/provider/services/order', ['ids' => [$id]])->assertStatus(409);
        }
        $this->assertSame(30000, ProviderService::first()->price);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/me/provider/services', ['title' => 'X', 'pricing_type' => 'quote'])->assertUnauthorized();
    }

    public function test_services_of_providers_customers_cant_find_stay_hidden()
    {
        $draft = $this->provider(false);
        $this->signIn($draft->owner);
        // They can prepare their list while applying
        $this->add()->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/providers/{$draft->id}/services")->assertNotFound();
    }

    public function test_a_customer_requests_a_listed_service()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $socket = $this->add()->json('data.id');
        $hidden = $this->add(['title' => 'Old service'])->json('data.id');
        $this->patchJson("/api/v1/me/provider/services/{$hidden}", ['is_active' => false]);

        $this->signIn(User::factory()->create());
        $send = function ($service) use ($provider) {
            return $this->postJson("/api/v1/providers/{$provider->id}/requests", [
                // The customer's category is replaced by the service's own
                'category' => 'plumbing', 'description' => 'Two sockets in the sitting room.', 'area' => 'ntinda',
                'preferred_time' => 'Saturday morning', 'service' => $service,
            ]);
        };

        $send($hidden)->assertStatus(422)->assertJsonPath('code', 'service_unavailable');
        $send(999999)->assertStatus(422)->assertJsonPath('code', 'service_unavailable');
        $send($socket)->assertCreated()->assertJsonPath('data.title', 'Socket installation')->assertJsonPath('data.service_id', $socket);

        $request = ServiceRequest::latest('id')->first();
        $this->assertSame($socket, $request->provider_service_id);
        $this->assertSame('electrical', $request->category->slug);
        // The customer's own words are kept
        $this->assertSame('Two sockets in the sitting room.', $request->description);
    }
}
