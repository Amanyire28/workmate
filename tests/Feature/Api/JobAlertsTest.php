<?php

namespace Tests\Feature\Api;

use App\Models\Area;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ProviderApplications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JobAlertsTest extends ApiTestCase
{
    /** An approved provider with one skill, based at a seeded place */
    private function provider(string $profession, string $base, ?User $owner = null): ProviderProfile
    {
        $owner = $owner ?: User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => Str::title($profession).' '.$base.' '.$owner->id, 'professions' => [['profession' => $profession]], 'base_area' => $base]);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function postJob(User $customer, array $extra = [])
    {
        $this->signIn($customer);

        return $this->postJson('/api/v1/jobs', $extra + ['category' => 'plumbing', 'title' => 'Fix a leaking sink', 'area' => 'kisaasi', 'workers_needed' => 1]);
    }

    private function alertsFor(ProviderProfile $p): array
    {
        return UserNotification::where('user_id', $p->owner_id)->where('kind', 'job_alerts')->pluck('title')->all();
    }

    public function test_nearby_providers_in_the_right_line_of_work_are_told()
    {
        $near = $this->provider('plumber', 'ntinda');            // about 2 km from Kisaasi
        $far = $this->provider('plumber', 'entebbe');            // about 35 km away
        $servesIt = $this->provider('plumber', 'entebbe');       // far, but serves Kisaasi
        DB::table('provider_service_areas')->insert(['provider_profile_id' => $servesIt->id, 'area_id' => Area::where('slug', 'kisaasi')->value('id')]);
        $electrician = $this->provider('electrician', 'kisaasi');

        $this->postJob(User::factory()->create())->assertCreated();

        $this->assertSame(['New plumbing job near you'], $this->alertsFor($near));
        $this->assertSame(['New plumbing job near you'], $this->alertsFor($servesIt));
        $this->assertSame([], $this->alertsFor($far));
        $this->assertSame([], $this->alertsFor($electrician));
        $alert = UserNotification::where('user_id', $near->owner_id)->where('kind', 'job_alerts')->first();
        $this->assertSame('“Fix a leaking sink” in Kisaasi. Apply in WorkMate.', $alert->body);
        $this->assertStringStartsWith('/job/', $alert->url);
    }

    public function test_the_customer_retries_and_limits_do_not_flood_anyone()
    {
        $near = $this->provider('plumber', 'ntinda');
        $customer = User::factory()->create();

        // A provider posting a job in their own line of work isn't told about it
        $own = $this->provider('plumber', 'kisaasi');
        $this->postJob($own->owner)->assertCreated();
        $this->assertSame([], $this->alertsFor($own));
        $this->assertCount(1, $this->alertsFor($near));

        // A retried post (same token) doesn't alert again
        $token = (string) Str::uuid();
        $this->postJob($customer, ['client_token' => $token])->assertCreated();
        $this->postJob($customer, ['client_token' => $token])->assertOk();
        $this->assertCount(2, $this->alertsFor($near));

        // At most 10 a day per provider
        for ($i = 0; $i < 12; $i++) {
            $this->travel(1)->seconds();
            $this->postJob($customer, ['title' => "Job {$i}"])->assertCreated();
        }
        $this->assertCount(10, $this->alertsFor($near));
        $this->assertNotNull(Cache::get('job-alerts:'.$near->owner_id.':'.now()->toDateString()));
    }

    public function test_providers_can_turn_job_alerts_off_and_customers_never_see_the_choice()
    {
        $near = $this->provider('plumber', 'ntinda');

        $this->signIn($near->owner);
        $this->getJson('/api/v1/me/notification-settings')->assertJsonPath('data.job_alerts.label', 'New jobs near you');
        $this->putJson('/api/v1/me/notification-settings', ['job_alerts' => false])->assertOk();

        $this->postJob(User::factory()->create())->assertCreated();
        $this->assertSame([], $this->alertsFor($near));

        $this->signIn(User::factory()->create());
        $this->assertArrayNotHasKey('job_alerts', $this->getJson('/api/v1/me/notification-settings')->json('data'));
    }
}
