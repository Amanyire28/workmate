<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\ProviderApplications;

class ReceivedRequestTest extends ApiTestCase
{
    /** An approved plumber */
    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create(['name' => 'Okello Plumbing']);
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    /** A customer sends the provider a request and returns its id */
    private function sendRequest(ProviderProfile $provider, User $customer): int
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow morning', 'budget' => 40000,
        ])->assertCreated()->json('data.id');
    }

    public function test_the_provider_sees_requests_sent_to_them_without_the_customers_contact_details()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['name' => 'Aisha Nakato', 'phone' => '+256700000001']);
        $id = $this->sendRequest($provider, $customer);

        $this->signIn($provider->owner);
        $response = $this->getJson('/api/v1/me/received-requests')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.customer.name', 'Aisha Nakato')
            ->assertJsonPath('data.0.area_name', 'Kisaasi')
            ->assertJsonPath('data.0.status', 'open');

        $this->assertStringNotContainsString($customer->email, $response->getContent());
        $this->assertStringNotContainsString('700000001', $response->getContent());
    }

    public function test_accepting_declining_and_finishing_show_up_for_the_customer()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $accepted = $this->sendRequest($provider, $customer);
        $declined = $this->sendRequest($provider, $customer);

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$accepted}/accept")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/v1/me/received-requests/{$declined}/decline", ['reason' => 'I’m fully booked this week.'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/me/received-requests/{$declined}/accept")->assertStatus(409);
        $this->postJson("/api/v1/me/received-requests/{$accepted}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $this->signIn($customer);
        $mine = collect($this->getJson('/api/v1/me/requests')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('completed', $mine[$accepted]['status']);
        $this->assertSame('cancelled', $mine[$declined]['status']);
        $this->assertSame('I’m fully booked this week.', $mine[$declined]['provider_note']);

        $this->assertTrue(AuditLog::where('action', 'marketplace.request_declined')->where('actor_id', $provider->owner_id)->exists());
    }

    public function test_the_provider_can_open_a_chat_with_the_customer()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->sendRequest($provider, $customer);

        $this->signIn($provider->owner);
        $conversation = $this->postJson("/api/v1/me/received-requests/{$id}/message")->assertOk()->json('data.conversation_id');
        $this->getJson("/api/v1/me/conversations/{$conversation}")->assertOk();
        $this->assertSame($conversation, $this->getJson('/api/v1/me/received-requests')->json('data.0.conversation_id'));

        // The customer finds the same chat
        $this->signIn($customer);
        $this->getJson("/api/v1/me/conversations/{$conversation}")->assertOk();
    }

    public function test_nobody_else_can_see_or_answer_a_providers_requests()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->sendRequest($provider, $customer);

        // Another provider
        $other = $this->provider();
        $this->signIn($other->owner);
        $this->assertSame([], $this->getJson('/api/v1/me/received-requests')->json('data'));
        $this->postJson("/api/v1/me/received-requests/{$id}/accept")->assertNotFound();
        $this->postJson("/api/v1/me/received-requests/{$id}/message")->assertNotFound();

        // The customer who sent it can't answer it for the provider
        $this->signIn($customer);
        $this->assertSame([], $this->getJson('/api/v1/me/received-requests')->json('data'));
        $this->postJson("/api/v1/me/received-requests/{$id}/decline", ['reason' => 'Changed my mind'])->assertNotFound();

        $this->postJson('/api/v1/me/received-requests/1/accept')->assertNotFound();
        $this->assertSame('open', \App\Models\ServiceRequest::find($id)->status);
    }

    public function test_a_suspended_provider_cannot_answer()
    {
        $provider = $this->provider();
        $id = $this->sendRequest($provider, User::factory()->create());
        $provider->forceFill(['status' => ProviderProfile::STATUS_SUSPENDED])->save();

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/accept")->assertStatus(403)->assertJson(['code' => 'provider_not_active']);
    }

    public function test_a_provider_cannot_request_message_report_or_rate_themselves()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);

        // The account knows which provider is its own, so the app hides these
        $this->assertSame([$provider->id], $this->getJson('/api/v1/me')->json('data.provider_ids'));

        $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow',
        ])->assertStatus(422)->assertJson(['code' => 'own_provider']);
        $this->postJson("/api/v1/me/conversations/provider/{$provider->id}")->assertStatus(422)->assertJson(['code' => 'own_provider']);
        $this->postJson("/api/v1/providers/{$provider->id}/reports", ['reason' => \App\Models\ProviderReport::REASONS[0]])
            ->assertStatus(422)->assertJson(['code' => 'own_provider']);
        // Rating needs a finished request they sent, which they can't have
        $this->assertSame([], $this->getJson('/api/v1/me/reviews/waiting')->json('data'));

        $this->signIn(User::factory()->create());
        $this->assertSame([], $this->getJson('/api/v1/me')->json('data.provider_ids'));
    }
}
