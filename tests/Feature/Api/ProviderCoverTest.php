<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\ProviderApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProviderCoverTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function provider(?User $owner = null): ProviderProfile
    {
        $owner = $owner ?: User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    public function test_the_owner_uploads_a_cover_redrawn_without_hidden_data()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);

        $url = $this->postJson('/api/v1/me/provider/cover', ['cover' => UploadedFile::fake()->image('shop.png', 1600, 900)])
            ->assertOk()
            ->assertJsonPath('data.id', $provider->id)
            ->json('data.cover_url');

        $path = $provider->fresh()->cover_path;
        $this->assertStringEndsWith(basename($path), $url);
        $bytes = Storage::disk('local')->get($path);
        [$width, $height, $type] = getimagesizefromstring($bytes);
        $this->assertSame([1200, 400, IMAGETYPE_JPEG], [$width, $height, $type]);
        // Re-drawn from pixels: no EXIF block (where phones keep GPS positions)
        $this->assertStringNotContainsString('Exif', $bytes);

        $this->app['auth']->forgetGuards();
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.cover_url', $url);
        $this->assertSame(1, AuditLog::where('action', 'provider.cover_changed')->count());
    }

    public function test_replacing_deletes_the_old_cover_and_removing_clears_it()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);

        $this->postJson('/api/v1/me/provider/cover', ['cover' => UploadedFile::fake()->image('a.jpg', 900, 300)])->assertOk();
        $first = $provider->fresh()->cover_path;
        $this->postJson('/api/v1/me/provider/cover', ['cover' => UploadedFile::fake()->image('b.jpg', 900, 300)])->assertOk();
        $second = $provider->fresh()->cover_path;
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->deleteJson('/api/v1/me/provider/cover')->assertOk()->assertJsonPath('data.cover_url', null);
        Storage::disk('local')->assertMissing($second);
        $this->assertNull($provider->fresh()->cover_path);
        $this->get('/api/v1/covers/'.basename($second))->assertNotFound();
        $this->assertSame(1, AuditLog::where('action', 'provider.cover_removed')->count());
    }

    public function test_nobody_else_can_change_a_providers_cover()
    {
        $provider = $this->provider();
        $this->postJson('/api/v1/me/provider/cover', ['cover' => UploadedFile::fake()->image('a.jpg', 900, 300)])->assertUnauthorized();

        // Another provider changes only their own profile
        $other = $this->provider();
        $this->signIn($other->owner);
        $this->postJson('/api/v1/me/provider/cover', ['cover' => UploadedFile::fake()->image('a.jpg', 900, 300)])
            ->assertOk()->assertJsonPath('data.id', $other->id);
        $this->deleteJson('/api/v1/me/provider/cover')->assertOk();
        $this->assertNull($provider->fresh()->cover_path);

        // Someone without a provider profile has nothing to change
        $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/me/provider/cover', ['cover' => UploadedFile::fake()->image('a.jpg', 900, 300)])
            ->assertStatus(409)->assertJson(['code' => 'no_provider_profile']);
        $this->assertNull($provider->fresh()->cover_path);
    }

    public function test_only_reasonable_images_are_accepted()
    {
        $this->signIn($this->provider()->owner);

        $this->postJson('/api/v1/me/provider/cover', ['cover' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
            ->assertStatus(422)->assertJsonPath('errors.cover.0', 'Choose a JPG, PNG or WebP photo.');
        $this->postJson('/api/v1/me/provider/cover', ['cover' => UploadedFile::fake()->image('small.jpg', 300, 100)])
            ->assertStatus(422)->assertJsonPath('errors.cover.0', 'Choose a larger photo (at least 600 × 200 pixels).');
        $this->postJson('/api/v1/me/provider/cover', ['cover' => UploadedFile::fake()->image('huge.jpg', 900, 300)->size(6000)])
            ->assertStatus(422)->assertJsonPath('errors.cover.0', 'Choose a photo smaller than 5 MB.');
    }

    public function test_cover_addresses_cannot_reach_other_files()
    {
        Storage::disk('local')->put('secret.txt', 'private');

        $this->get('/api/v1/covers/..%2Fsecret.txt')->assertNotFound();
        $this->get('/api/v1/covers/secret.txt')->assertNotFound();
    }
}
