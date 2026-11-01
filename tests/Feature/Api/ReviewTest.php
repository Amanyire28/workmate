<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ProviderProfile;
use App\Models\Review;
use App\Models\User;
use App\Services\ProviderApplications;

class ReviewTest extends ApiTestCase
{
    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    /** A request the provider accepted and, if $finish, marked as done */
    private function job(ProviderProfile $provider, User $customer, bool $finish = true): int
    {
        $this->signIn($customer);
        $id = $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow',
        ])->assertCreated()->json('data.id');

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/accept")->assertOk();
        if ($finish) {
            $this->postJson("/api/v1/me/received-requests/{$id}/complete")->assertOk();
        }

        return $id;
    }

    public function test_the_customer_is_asked_to_rate_once_the_provider_finishes()
    {
        $provider = $this->provider();
        $customer = User::factory()->create(['name' => 'Aisha Nakato']);
        $unfinished = $this->job($provider, $customer, false);
        $finished = $this->job($provider, $customer);

        $this->signIn($customer);
        $waiting = $this->getJson('/api/v1/me/reviews/waiting')->assertOk()->json('data');
        $this->assertSame([$finished], array_column($waiting, 'request_id'));
        $this->assertSame('Okello Plumbing', $waiting[0]['provider']['display_name']);

        // Stars are needed; a message isn't
        $this->postJson("/api/v1/me/requests/{$finished}/review", ['comment' => 'Great'])->assertStatus(422)->assertJsonValidationErrors('rating');
        $this->postJson("/api/v1/me/requests/{$finished}/review", ['rating' => 6])->assertStatus(422);
        $this->postJson("/api/v1/me/requests/{$finished}/review", ['rating' => 5])->assertCreated()
            ->assertJsonPath('data.reviewer_name', 'Aisha N.')->assertJsonPath('data.rating', 5);

        $this->postJson("/api/v1/me/requests/{$finished}/review", ['rating' => 1])->assertStatus(409);
        $this->postJson("/api/v1/me/requests/{$unfinished}/review", ['rating' => 4])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/me/reviews/waiting')->json('data'));

        // Everyone sees the rating on the provider
        $listed = collect($this->getJson('/api/v1/providers')->json('data'))->firstWhere('id', $provider->id);
        $this->assertEquals(5, $listed['rating']);
        $this->assertSame(1, $listed['review_count']);
        $this->assertSame(['rating' => 5, 'comment' => null], collect($this->getJson('/api/v1/me/requests')->json('data'))->firstWhere('id', $finished)['review']);
        $this->assertTrue(AuditLog::where('action', 'marketplace.review_posted')->where('actor_id', $customer->id)->exists());
    }

    public function test_not_now_stops_the_pop_up_but_the_customer_can_still_rate_later()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->job($provider, $customer);

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/review/later")->assertOk();
        $this->assertSame([], $this->getJson('/api/v1/me/reviews/waiting')->json('data'));

        $this->postJson("/api/v1/me/requests/{$id}/review", ['rating' => 4, 'comment' => 'Fixed it quickly.'])->assertCreated();
        $this->assertSame('Fixed it quickly.', Review::sole()->comment);
    }

    public function test_only_the_customer_who_worked_with_the_provider_can_rate()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->job($provider, $customer);

        // Another customer, and the provider themselves
        foreach ([User::factory()->create(), $provider->owner] as $someoneElse) {
            $this->signIn($someoneElse);
            $this->postJson("/api/v1/me/requests/{$id}/review", ['rating' => 1])->assertNotFound();
            $this->postJson("/api/v1/me/requests/{$id}/review/later")->assertNotFound();
            $this->assertSame([], $this->getJson('/api/v1/me/reviews/waiting')->json('data'));
        }

        $this->assertSame(0, Review::count());
    }
}
