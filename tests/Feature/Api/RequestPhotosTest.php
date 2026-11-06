<?php

namespace Tests\Feature\Api;

use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\EventNotifications;
use App\Services\ProviderApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RequestPhotosTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** An approved plumber */
    private function provider(string $name = 'Okello Plumbing'): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => $name, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function sendRequest(ProviderProfile $provider, array $photos, array $extra = [])
    {
        return $this->post("/api/v1/providers/{$provider->id}/requests", $extra + [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow',
            'photos' => $photos,
        ], ['Accept' => 'application/json']);
    }

    private function postJob(array $photos)
    {
        return $this->post('/api/v1/jobs', [
            'category' => 'plumbing', 'title' => 'Fix a leaking sink', 'area' => 'kisaasi', 'workers_needed' => 1, 'photos' => $photos,
        ], ['Accept' => 'application/json']);
    }

    private function photoUrl(array $photo): string
    {
        return parse_url($photo['url'], PHP_URL_PATH);
    }

    public function test_photos_go_with_a_request_to_the_provider_only()
    {
        $provider = $this->provider();
        $customer = $this->signIn(User::factory()->create());
        $created = $this->sendRequest($provider, [UploadedFile::fake()->image('sink.jpg', 3000, 2000), UploadedFile::fake()->image('pipe.png', 600, 900)])
            ->assertCreated()
            ->assertJsonPath('data.photo_count', 2)
            ->assertJsonPath('data.photos.0.width', 1600)
            ->assertJsonPath('data.photos.1.height', 900)
            ->json('data');

        // Stored privately, re-drawn as JPEG, no paths in the API
        $photo = ServiceRequest::find($created['id'])->photos->first();
        Storage::disk('local')->assertExists([$photo->path, $photo->thumb_path]);
        $this->assertStringStartsWith('request-photos/', $photo->path);
        $this->assertStringNotContainsString('request-photos/', json_encode($created));
        $url = $this->photoUrl($created['photos'][0]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('no-store, private', $this->get($url)->headers->get('Cache-Control'));

        // The provider sees them in their inbox and can open them
        $this->signIn($provider->owner);
        $this->getJson('/api/v1/me/received-requests')->assertJsonCount(2, 'data.0.photos');
        $this->get($url)->assertOk();
        $this->get(str_replace('.jpg', '-t.jpg', $url))->assertOk();

        // Nobody else does
        $this->signIn($this->provider('Another Plumber')->owner);
        $this->get($url)->assertNotFound();
        $this->signIn(User::factory()->create());
        $this->get($url)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get($url, ['Accept' => 'application/json'])->assertNotFound();

        // Administrators who can see the marketplace can
        $this->signIn($this->userWithRoles(['operations']));
        $this->get($url)->assertOk();
        $this->assertCount(2, collect($this->getJson('/api/v1/admin/data')->json('data.core.jobs'))->firstWhere('requestId', $created['id'])['photos']);
        $this->assertNotNull($customer);
    }

    public function test_posted_job_photos_are_for_providers_who_could_take_the_job()
    {
        $customer = $this->signIn(User::factory()->create());
        $job = $this->postJob([UploadedFile::fake()->image('door.jpg')])->assertCreated()->json('data');
        $url = $this->photoUrl($job['photos'][0]);

        // The public list says there are photos, but doesn't show them
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/jobs')->assertJsonPath('data.0.photo_count', 1)->assertJsonPath('data.0.photos', []);
        $this->get($url, ['Accept' => 'application/json'])->assertNotFound();
        $this->signIn(User::factory()->create());
        $this->get($url)->assertNotFound();

        // Approved providers who might apply see them
        $applicant = $this->provider();
        $this->signIn($applicant->owner);
        $this->getJson('/api/v1/jobs')->assertJsonCount(1, 'data.0.photos');
        $this->get($url)->assertOk();
        $application = $this->postJson("/api/v1/jobs/{$job['id']}/applications", ['message' => 'I can fix this sink tomorrow morning.', 'start_time' => 'Tomorrow'])
            ->assertCreated()->json('data.id');

        // Once someone is hired, providers who didn't apply no longer do
        $this->signIn($customer);
        $this->postJson("/api/v1/me/jobs/{$job['id']}/applications/{$application}/hire")->assertOk();
        $this->signIn($this->provider('Late Plumber')->owner);
        $this->get($url)->assertNotFound();
        $this->signIn($applicant->owner);
        $this->get($url)->assertOk();
    }

    public function test_photos_are_checked_and_nothing_is_left_behind()
    {
        $provider = $this->provider();
        $this->signIn(User::factory()->create());

        $this->sendRequest($provider, array_fill(0, 7, UploadedFile::fake()->image('p.jpg')))->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->sendRequest($provider, [UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422)->assertJsonValidationErrors('photos.0');
        $this->sendRequest($provider, [UploadedFile::fake()->image('huge.jpg')->size(9000)])->assertStatus(422)->assertJsonValidationErrors('photos.0');
        $this->sendRequest($provider, [UploadedFile::fake()->createWithContent('fake.jpg', 'not really a photo')])->assertStatus(422);
        // A photo that can't be read stops the whole request, and the good one is removed again
        $this->sendRequest($provider, [UploadedFile::fake()->image('good.jpg'), UploadedFile::fake()->createWithContent('bad.png', 'nope')])->assertStatus(422);

        $this->assertSame(0, ServiceRequest::count());
        $this->assertSame([], Storage::disk('local')->allFiles('request-photos'));
    }

    public function test_a_retried_request_is_not_sent_twice()
    {
        $notify = $this->spy(EventNotifications::class);
        $provider = $this->provider();
        $this->signIn(User::factory()->create());
        $token = (string) Str::uuid();

        $first = $this->sendRequest($provider, [UploadedFile::fake()->image('a.jpg')], ['client_token' => $token])->assertCreated()->json('data.id');
        // The connection dropped before the answer arrived; the app sends it again
        $again = $this->sendRequest($provider, [UploadedFile::fake()->image('a.jpg')], ['client_token' => $token])->assertOk()->json('data');

        $this->assertSame($first, $again['id']);
        $this->assertSame(1, $again['photo_count']);
        $this->assertSame(1, ServiceRequest::count());
        $this->assertCount(2, Storage::disk('local')->allFiles('request-photos'), 'One photo in two sizes; the retry stored nothing');
        $notify->shouldHaveReceived('requestReceived')->once();

        // A new request (new token) is a new request
        $this->sendRequest($provider, [], ['client_token' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(2, ServiceRequest::count());
        $this->sendRequest($provider, [], ['client_token' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors('client_token');
    }
}
