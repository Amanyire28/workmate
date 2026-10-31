<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfilePhotoTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_uploading_a_photo_stores_a_square_jpeg_without_hidden_data()
    {
        $user = $this->signIn(User::factory()->create());

        $url = $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me.png', 900, 600)])
            ->assertOk()
            ->json('data.avatar_url');

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($url);
        $this->assertStringEndsWith(basename($path), $url);
        Storage::disk('local')->assertExists($path);

        $bytes = Storage::disk('local')->get($path);
        [$width, $height, $type] = getimagesizefromstring($bytes);
        $this->assertSame([512, 512, IMAGETYPE_JPEG], [$width, $height, $type]);
        // Re-drawn from pixels: no EXIF block (where phones keep GPS positions)
        $this->assertStringNotContainsString('Exif', $bytes);

        $this->get(parse_url($url, PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(1, AuditLog::where('action', 'account.photo_changed')->count());
    }

    public function test_replacing_a_photo_deletes_the_old_file()
    {
        $user = $this->signIn(User::factory()->create());

        $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)])->assertOk();
        $first = $user->fresh()->avatar_path;
        $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('b.jpg', 300, 300)])->assertOk();

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($user->fresh()->avatar_path);
    }

    public function test_removing_the_photo()
    {
        $user = $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)])->assertOk();
        $path = $user->fresh()->avatar_path;

        $this->deleteJson('/api/v1/me/photo')->assertOk()->assertJsonPath('data.avatar_url', null);

        Storage::disk('local')->assertMissing($path);
        $this->get('/api/v1/avatars/'.basename($path))->assertNotFound();
    }

    public function test_only_reasonable_images_are_accepted()
    {
        $this->postJson('/api/v1/me/photo', [])->assertUnauthorized();

        $this->signIn(User::factory()->create());

        $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
            ->assertStatus(422)->assertJsonPath('errors.photo.0', 'Choose a JPG, PNG or WebP photo.');
        $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('tiny.jpg', 40, 40)])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('huge.jpg', 300, 300)->size(6000)])
            ->assertStatus(422)->assertJsonPath('errors.photo.0', 'Choose a photo smaller than 5 MB.');
    }

    public function test_photo_addresses_cannot_reach_other_files()
    {
        Storage::disk('local')->put('secret.txt', 'private');

        $this->get('/api/v1/avatars/..%2Fsecret.txt')->assertNotFound();
        $this->get('/api/v1/avatars/secret.txt')->assertNotFound();
    }
}
