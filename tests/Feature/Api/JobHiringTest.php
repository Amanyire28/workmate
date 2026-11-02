<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ProviderApplications;

class JobHiringTest extends ApiTestCase
{
    private function provider(string $name): ProviderProfile
    {
        $owner = User::factory()->create(['name' => $name]);
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => $name, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function postJob(User $customer): int
    {
        $this->signIn($customer);

        return $this->postJson('/api/v1/jobs', [
            'category' => 'plumbing', 'title' => 'Fix a leaking pipe', 'description' => 'Under the kitchen sink.',
            'area' => 'ntinda', 'preferred_time' => 'This week', 'workers_needed' => 1, 'budget' => 50000, 'budget_basis' => 'fixed',
        ])->assertCreated()->json('data.id');
    }

    private function apply(ProviderProfile $provider, int $job, int $amount): int
    {
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/jobs/{$job}/applications", ['message' => 'I can fix this tomorrow morning.', 'start_time' => 'Tomorrow, 9am', 'amount' => $amount])->assertCreated();

        return Quotation::where('provider_profile_id', $provider->id)->where('service_request_id', $job)->value('id');
    }

    public function test_the_customer_sees_who_applied_hires_one_and_rates_them_after_the_work()
    {
        $customer = User::factory()->create();
        $okello = $this->provider('Okello Plumbing');
        $nambi = $this->provider('Nambi Pipes');
        $job = $this->postJob($customer);
        $okelloApplication = $this->apply($okello, $job, 45000);
        $nambiApplication = $this->apply($nambi, $job, 40000);

        $this->signIn($customer);
        $applicants = $this->getJson("/api/v1/me/jobs/{$job}/applications")->assertOk()->json('data');
        $this->assertSame(['Okello Plumbing', 'Nambi Pipes'], array_column(array_column($applicants, 'provider'), 'display_name'));
        $this->assertSame(45000, $applicants[0]['amount']);
        $this->assertSame('Plumber', $applicants[0]['provider']['main_profession']);
        $this->assertStringNotContainsString($okello->owner->email, json_encode($applicants));

        $this->postJson("/api/v1/me/jobs/{$job}/applications/{$okelloApplication}/hire")->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.kind', 'posted')
            ->assertJsonPath('data.provider.display_name', 'Okello Plumbing');
        $this->postJson("/api/v1/me/jobs/{$job}/applications/{$nambiApplication}/hire")->assertStatus(409);

        $this->assertSame('accepted', Quotation::find($okelloApplication)->status);
        $this->assertSame('declined', Quotation::find($nambiApplication)->status);
        // No longer listed for others to apply to
        $this->assertNotContains($job, array_column($this->getJson('/api/v1/jobs')->json('data'), 'id'));

        // The hired provider finds it with their requests and marks it done
        $this->signIn($okello->owner);
        $received = collect($this->getJson('/api/v1/me/received-requests')->json('data'))->firstWhere('id', $job);
        $this->assertSame('in_progress', $received['status']);
        $this->postJson("/api/v1/me/received-requests/{$job}/complete")->assertOk();

        // The other applicant sees they weren't chosen, and can't touch the job
        $this->signIn($nambi->owner);
        $this->assertSame('declined', collect($this->getJson('/api/v1/me/applications')->json('data'))->firstWhere('id', $nambiApplication)['status']);
        $this->postJson("/api/v1/me/received-requests/{$job}/complete")->assertNotFound();

        // The customer is asked to rate the hired provider
        $this->signIn($customer);
        $this->assertSame([$job], array_column($this->getJson('/api/v1/me/reviews/waiting')->json('data'), 'request_id'));
        $this->postJson("/api/v1/me/requests/{$job}/review", ['rating' => 5])->assertCreated();
        $mine = collect($this->getJson('/api/v1/me/requests')->json('data'))->firstWhere('id', $job);
        $this->assertSame('completed', $mine['status']);
        $this->assertSame(5, $mine['review']['rating']);
        $this->assertSame($okello->id, \App\Models\Review::sole()->provider_profile_id);

        $this->assertTrue(AuditLog::where('action', 'marketplace.hired')->where('actor_id', $customer->id)->exists());
    }

    public function test_only_the_customer_who_posted_the_job_sees_and_hires_applicants()
    {
        $customer = User::factory()->create();
        $okello = $this->provider('Okello Plumbing');
        $job = $this->postJob($customer);
        $application = $this->apply($okello, $job, 45000);

        foreach ([User::factory()->create(), $okello->owner] as $someoneElse) {
            $this->signIn($someoneElse);
            $this->getJson("/api/v1/me/jobs/{$job}/applications")->assertNotFound();
            $this->postJson("/api/v1/me/jobs/{$job}/applications/{$application}/hire")->assertNotFound();
        }

        // An application from another job can't be used
        $otherJob = $this->postJob($customer);
        $this->postJson("/api/v1/me/jobs/{$otherJob}/applications/{$application}/hire")->assertStatus(409);

        $this->assertSame('pending', Quotation::find($application)->status);
    }

    public function test_a_suspended_provider_cannot_be_hired()
    {
        $customer = User::factory()->create();
        $okello = $this->provider('Okello Plumbing');
        $job = $this->postJob($customer);
        $application = $this->apply($okello, $job, 45000);
        $okello->forceFill(['status' => ProviderProfile::STATUS_SUSPENDED])->save();

        $this->signIn($customer);
        $this->assertFalse($this->getJson("/api/v1/me/jobs/{$job}/applications")->json('data.0.provider.available'));
        $this->postJson("/api/v1/me/jobs/{$job}/applications/{$application}/hire")->assertStatus(409)->assertJson(['code' => 'provider_unavailable']);
    }
}
