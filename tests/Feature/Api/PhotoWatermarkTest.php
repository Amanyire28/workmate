<?php

namespace Tests\Feature\Api;

use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\MessageAttachment;
use App\Models\ProviderProfile;
use App\Models\ProviderWorkPhoto;
use App\Models\ServiceRequestPhoto;
use App\Models\User;
use App\Models\VerificationDocument;
use App\Services\ImageWatermark;
use App\Services\PhotoVariants;
use App\Services\ProviderApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The WorkMate watermark on public photos (community posts and providers'
 * work): drawn into the picture, inside it, once; private photos and
 * documents untouched; the clean copy never served; older photos caught up.
 *
 * Photos here are plain grey, so any changed pixel is the watermark.
 */
class PhotoWatermarkTest extends ApiTestCase
{
    private const GREY = 120;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /* ---------------- Helpers ---------------- */

    private function grey(int $w, int $h, string $type = 'jpg', string $name = 'photo'): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, self::GREY, self::GREY, self::GREY));
        ob_start();
        if ($type === 'png') {
            imagepng($img);
        } elseif ($type === 'webp') {
            imagewebp($img, null, 90);
        } else {
            imagejpeg($img, null, 95);
        }
        imagedestroy($img);

        return UploadedFile::fake()->createWithContent("{$name}.{$type}", (string) ob_get_clean());
    }

    /** The bytes a photo address sends */
    private function served(string $url): string
    {
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        return (string) file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    /** How many pixels in the rectangle are clearly not grey (every other pixel, both ways) */
    private function marked(string $bytes, ?array $rect = null): int
    {
        $img = imagecreatefromstring($bytes);
        $rect = $rect ?: ['x' => 0, 'y' => 0, 'width' => imagesx($img), 'height' => imagesy($img)];
        $count = 0;
        for ($y = $rect['y']; $y < $rect['y'] + $rect['height']; $y += 2) {
            for ($x = $rect['x']; $x < $rect['x'] + $rect['width']; $x += 2) {
                $c = imagecolorat($img, $x, $y);
                $diff = max(abs((($c >> 16) & 0xFF) - self::GREY), abs((($c >> 8) & 0xFF) - self::GREY), abs(($c & 0xFF) - self::GREY));
                if ($diff > 25) {
                    $count++;
                }
            }
        }
        imagedestroy($img);

        return $count;
    }

    /** The stamp's corner, and everywhere else */
    private function assertWatermarked(string $bytes, string $what = 'photo'): void
    {
        [$w, $h] = getimagesizefromstring($bytes);
        $at = app(ImageWatermark::class)->placement($w, $h);
        // At least 2% of the stamp's area is clearly drawn (the letters; the rest is soft shadow and gaps)
        $sampled = (int) ceil($at['width'] / 2) * (int) ceil($at['height'] / 2);
        $this->assertGreaterThan(max(3, $sampled * 0.02), $this->marked($bytes, $at), "{$what}: no watermark in the corner");
        $this->assertSame(0, $this->marked($bytes, ['x' => 0, 'y' => 0, 'width' => $at['x'], 'height' => $h]), "{$what}: changed outside the corner");
        $this->assertSame(0, $this->marked($bytes, ['x' => 0, 'y' => 0, 'width' => $w, 'height' => $at['y']]), "{$what}: changed outside the corner");
    }

    private function assertClean(string $bytes, string $what = 'photo'): void
    {
        $this->assertSame(0, $this->marked($bytes), "{$what} was changed");
    }

    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create(['name' => 'Okello Peter']);
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function postWith(array $photos)
    {
        $this->travel(21)->seconds();   // past the posting limit

        return $this->post('/api/v1/community/posts', ['category' => 'my_work', 'body' => 'Finished a bathroom in Ntinda.', 'photos' => $photos], ['Accept' => 'application/json']);
    }

    /* ---------------- New photos ---------------- */

    public function test_community_photos_carry_the_watermark_and_the_clean_copy_is_never_served()
    {
        $this->signIn(User::factory()->create());
        $post = $this->postWith([$this->grey(2000, 1500)])->assertCreated()->json('data');
        $media = CommunityPostMedia::firstOrFail();
        $disk = Storage::disk('local');

        // Proportions kept, at the usual full size
        $this->assertSame([1600, 1200], [$media->width, $media->height]);
        $this->assertNotNull($media->watermarked_at);
        $this->assertSame('community/'.$media->uuid.'-o.jpg', $media->original_path);

        // Both sizes watermarked; the clean copy isn't
        $this->assertWatermarked($disk->get($media->path), 'full size');
        $this->assertWatermarked($disk->get($media->thumb_path), 'thumbnail');
        $this->assertClean($disk->get($media->original_path), 'clean copy');

        // What the app shows (and what people save or share) is the watermarked file
        $url = parse_url($post['media'][0]['url'], PHP_URL_PATH);
        $this->assertWatermarked($this->served($url), 'served photo');
        $this->assertWatermarked($this->served(parse_url($post['media'][0]['thumb_url'], PHP_URL_PATH)), 'served thumbnail');

        // The clean copy has no address, and paths never leave the server
        $this->get("/api/v1/community/media/{$media->uuid}-o.jpg")->assertNotFound();
        $this->assertStringNotContainsString('-o.jpg', json_encode($post));
        $this->assertStringNotContainsString('original_path', json_encode($post));
    }

    public function test_png_webp_portrait_small_and_large_photos_all_get_a_fitting_watermark()
    {
        $photos = app(PhotoVariants::class);
        $cases = [
            ['png', 900, 1600, [900, 1600]],
            ['webp', 1600, 900, [1600, 900]],
            ['jpg', 320, 240, [320, 240]],
            ['jpg', 6000, 4000, [1600, 1067]],
            ['png', 240, 900, [240, 900]],
        ];
        foreach ($cases as [$type, $w, $h, $expected]) {
            $stored = $photos->store($this->grey($w, $h, $type), 'community', 'community');
            $bytes = Storage::disk('local')->get($stored['path']);
            [$gotW, $gotH, $kind] = getimagesizefromstring($bytes);
            $this->assertSame($expected, [$gotW, $gotH], "{$type} {$w}x{$h}: size");
            $this->assertSame(IMAGETYPE_JPEG, $kind);
            $this->assertWatermarked($bytes, "{$type} {$w}x{$h}");
            $this->assertWatermarked(Storage::disk('local')->get($stored['thumb_path']), "{$type} {$w}x{$h} thumbnail");
        }
    }

    public function test_the_watermark_always_fits_inside_the_photo_and_scales_with_it()
    {
        $wm = app(ImageWatermark::class);
        foreach ([[1600, 1200], [1200, 1600], [720, 540], [320, 240], [120, 900], [1600, 90], [40, 40], [1, 1], [12000, 300]] as [$w, $h]) {
            $at = $wm->placement($w, $h);
            $this->assertGreaterThanOrEqual(0, $at['x'], "{$w}x{$h}");
            $this->assertGreaterThanOrEqual(0, $at['y'], "{$w}x{$h}");
            $this->assertLessThanOrEqual($w, $at['x'] + $at['width'], "{$w}x{$h}");
            $this->assertLessThanOrEqual($h, $at['y'] + $at['height'], "{$w}x{$h}");
            $this->assertLessThanOrEqual(max(1, $w * 0.5), $at['width'], "{$w}x{$h}: never more than half the width");
        }
        // Bigger photos, bigger stamp; same shape
        $small = $wm->placement(720, 540);
        $large = $wm->placement(1600, 1200);
        $this->assertGreaterThan($small['width'], $large['width']);
        $this->assertEqualsWithDelta($small['width'] / $small['height'], $large['width'] / $large['height'], 0.1);
        // Bottom right, the same gap from both edges
        $this->assertSame(1600 - $large['x'] - $large['width'], 1200 - $large['y'] - $large['height']);
    }

    public function test_the_watermark_shows_on_bright_photos_and_reads_the_same_on_every_shape()
    {
        $wm = app(ImageWatermark::class);

        // On a white photo the white letters alone wouldn't show: the soft dark halo makes them stand out
        $white = imagecreatetruecolor(1600, 1200);
        imagefill($white, 0, 0, imagecolorallocate($white, 255, 255, 255));
        $wm->apply($white);
        $at = $wm->placement(1600, 1200);
        $darker = 0;
        for ($y = $at['y']; $y < $at['y'] + $at['height']; $y += 2) {
            for ($x = $at['x']; $x < $at['x'] + $at['width']; $x += 2) {
                if ((imagecolorat($white, $x, $y) & 0xFF) < 225) {
                    $darker++;
                }
            }
        }
        imagedestroy($white);
        $this->assertGreaterThan(200, $darker, 'the watermark disappears on a white photo');

        // Opened on a phone, photos of every shape are shown at the screen's width,
        // so the stamp is the same share of the width on all of them
        foreach ([[1600, 1067], [1200, 1600], [1400, 1400], [900, 1600]] as [$w, $h]) {
            $this->assertEqualsWithDelta(0.28, $wm->placement($w, $h)['width'] / $w, 0.01, "{$w}x{$h}");
        }
        // Wide photos: never taller than a fifth of the photo
        $wide = $wm->placement(1600, 400);
        $this->assertLessThanOrEqual(80, $wide['height']);
    }

    public function test_providers_work_photos_carry_the_watermark()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $this->post('/api/v1/me/provider/work', ['title' => 'New bathroom pipes in Ntinda', 'photos' => [$this->grey(1200, 900), $this->grey(900, 1200, 'png')]], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(2, ProviderWorkPhoto::whereNotNull('watermarked_at')->count());
        foreach (ProviderWorkPhoto::all() as $photo) {
            $this->assertWatermarked(Storage::disk('local')->get($photo->path), 'work photo');
            $this->assertWatermarked($this->served("/api/v1/work/media/{$photo->uuid}.jpg"), 'served work photo');
            $this->get("/api/v1/work/media/{$photo->uuid}-o.jpg")->assertNotFound();
        }
    }

    public function test_unreadable_and_oversized_files_are_refused_and_nothing_is_kept()
    {
        $this->signIn(User::factory()->create());

        $fake = UploadedFile::fake()->createWithContent('photo.jpg', 'not a picture at all');
        $this->postWith([$fake])->assertStatus(422);

        // A tiny file claiming to be 40000 x 40000 pixels is refused before it's opened
        $png = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($png);
        $bytes = ob_get_clean();
        $bomb = substr_replace($bytes, pack('NN', 40000, 40000), 16, 8);
        $this->postWith([UploadedFile::fake()->createWithContent('huge.png', $bomb)])
            ->assertStatus(422)->assertJsonPath('code', 'image_too_large');

        $this->assertSame(0, CommunityPost::count());
        $this->assertSame([], Storage::disk('local')->allFiles('community'));
    }

    public function test_if_the_watermark_cant_be_drawn_the_photo_is_not_published()
    {
        config(['watermark.stamp' => storage_path('missing-stamp.png')]);
        $this->signIn(User::factory()->create());

        $this->postWith([$this->grey(800, 600)])->assertStatus(500)->assertJsonPath('code', 'image_processing_failed');

        $this->assertSame(0, CommunityPost::count());
        $this->assertSame([], Storage::disk('local')->allFiles('community'));
    }

    public function test_switched_off_photos_are_kept_as_before()
    {
        config(['watermark.enabled' => false]);
        $stored = app(PhotoVariants::class)->store($this->grey(800, 600), 'community', 'community');

        $this->assertArrayNotHasKey('original_path', $stored);
        $this->assertClean(Storage::disk('local')->get($stored['path']));
    }

    /* ---------------- Private photos and documents ---------------- */

    public function test_private_photos_profile_photos_and_documents_are_never_watermarked()
    {
        $disk = Storage::disk('local');
        $provider = $this->provider();

        // A chat photo
        $customer = $this->signIn(User::factory()->create(['name' => 'Nakato Sarah']));
        $chat = $this->postJson("/api/v1/me/conversations/provider/{$provider->id}")->assertOk()->json('data.id');
        $this->post("/api/v1/me/conversations/{$chat}/messages", ['attachments' => [$this->grey(1200, 900)]], ['Accept' => 'application/json'])->assertCreated();
        $attachment = MessageAttachment::firstOrFail();
        $this->assertClean($disk->get($attachment->path), 'chat photo');
        $this->assertClean($disk->get($attachment->thumb_path), 'chat thumbnail');

        // Photos of a job sent to a provider (someone's home)
        $this->post("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'ntinda', 'timing' => 'asap', 'photos' => [$this->grey(1200, 900)],
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertClean($disk->get(ServiceRequestPhoto::firstOrFail()->path), 'job photo');

        // A profile photo
        $this->postJson('/api/v1/me/photo', ['photo' => $this->grey(900, 600, 'png')])->assertOk();
        $this->assertClean($disk->get($customer->fresh()->avatar_path), 'profile photo');

        // Verification documents
        $this->signIn($provider->owner);
        $this->post('/api/v1/me/verification/documents', ['kind' => 'national_id', 'front' => $this->grey(1200, 800), 'back' => $this->grey(1200, 800, 'png')], ['Accept' => 'application/json'])->assertCreated();
        foreach (VerificationDocument::firstOrFail()->files as $file) {
            $this->assertClean($disk->get($file['path']), 'ID document');
        }

        // Nothing but public photos has a clean copy
        $this->assertSame([], array_values(array_filter($disk->allFiles(), function ($p) {
            return substr($p, -6) === '-o.jpg';
        })));
    }

    /* ---------------- Older photos ---------------- */

    /** Photos stored before the watermark existed */
    private function olderPhotos(): array
    {
        config(['watermark.enabled' => false]);
        $this->signIn(User::factory()->create());
        $this->postWith([$this->grey(1600, 1200), $this->grey(900, 1200)])->assertCreated();
        $work = $this->provider();
        $this->signIn($work->owner);
        $this->post('/api/v1/me/provider/work', ['title' => 'Kitchen tiles in Kira', 'photos' => [$this->grey(1200, 900)]], ['Accept' => 'application/json'])->assertCreated();
        config(['watermark.enabled' => true]);

        return [CommunityPostMedia::orderBy('id')->get(), ProviderWorkPhoto::orderBy('id')->get()];
    }

    public function test_older_photos_are_caught_up_once_without_touching_the_originals()
    {
        [$community, $work] = $this->olderPhotos();
        $disk = Storage::disk('local');
        $before = $community->concat($work)->mapWithKeys(function ($m) use ($disk) {
            return [$m->path => md5($disk->get($m->path))];
        });

        $this->artisan('workmate:watermark-photos', ['--dry-run' => true])
            ->expectsOutput('community: 2 to watermark, 0 already watermarked, 0 failed before (use --retry-failed)')
            ->expectsOutput('work: 1 to watermark, 0 already watermarked, 0 failed before (use --retry-failed)')
            ->assertExitCode(0);
        $this->assertSame(0, CommunityPostMedia::whereNotNull('watermarked_at')->count());   // a dry run changes nothing

        $this->artisan('workmate:watermark-photos')->assertExitCode(0);

        foreach (CommunityPostMedia::all()->concat(ProviderWorkPhoto::all()) as $m) {
            $this->assertNotNull($m->watermarked_at);
            $this->assertStringEndsWith('-w.jpg', $m->path);
            $this->assertStringEndsWith('-wt.jpg', $m->thumb_path);
            $this->assertWatermarked($disk->get($m->path));
            $this->assertWatermarked($disk->get($m->thumb_path));
            // The old file is now the clean copy, byte for byte unchanged
            $this->assertSame($before[$m->original_path], md5($disk->get($m->original_path)));
        }
        // Served at the same address as before, now watermarked
        $first = CommunityPostMedia::orderBy('id')->first();
        $this->assertWatermarked($this->served("/api/v1/community/media/{$first->uuid}.jpg"));

        // Running again does nothing
        $stamped = md5($disk->get($first->path));
        $this->artisan('workmate:watermark-photos')->expectsOutput('community: 0 watermarked, 0 failed')->assertExitCode(0);
        $this->assertSame($stamped, md5($disk->get($first->fresh()->path)));
    }

    public function test_redoing_starts_from_the_clean_copy_so_there_is_never_a_second_stamp()
    {
        $disk = Storage::disk('local');
        $this->signIn(User::factory()->create());
        $this->postWith([$this->grey(1600, 1200)])->assertCreated();
        $media = CommunityPostMedia::firstOrFail();
        $once = $disk->get($media->path);

        $this->artisan('workmate:watermark-photos', ['--redo' => true, '--type' => 'community'])->assertExitCode(0);
        $media->refresh();
        $twice = $disk->get($media->path);

        // Same file names, same single stamp (a second stamp would darken the corner further)
        $this->assertSame('community/'.$media->uuid.'.jpg', $media->path);
        $at = app(ImageWatermark::class)->placement(1600, 1200);
        $this->assertEqualsWithDelta($this->marked($once, $at), $this->marked($twice, $at), max(5, $this->marked($once, $at) * 0.05));
        $this->assertClean($disk->get($media->original_path), 'clean copy');
    }

    public function test_problems_are_recorded_and_can_be_retried()
    {
        [$community] = $this->olderPhotos();
        $disk = Storage::disk('local');
        $missing = $community->first();
        $kept = $disk->get($missing->path);
        $disk->delete($missing->path);

        $this->artisan('workmate:watermark-photos', ['--type' => 'community'])->expectsOutput('community: 1 watermarked, 1 failed')->assertExitCode(0);
        $this->assertStringContainsString('missing', $missing->fresh()->watermark_error);
        $this->assertNull($missing->fresh()->watermarked_at);

        // Skipped until asked to retry
        $this->artisan('workmate:watermark-photos', ['--type' => 'community'])->expectsOutput('community: 0 watermarked, 0 failed');
        $disk->put($missing->path, $kept);
        $this->artisan('workmate:watermark-photos', ['--type' => 'community', '--retry-failed' => true])->expectsOutput('community: 1 watermarked, 0 failed');
        $this->assertNull($missing->fresh()->watermark_error);
        $this->assertNotNull($missing->fresh()->watermarked_at);
    }
}
