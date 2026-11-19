<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\CommunityVideoUpload;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\R2Storage;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Community videos: the phone uploads straight to a private R2 bucket with
 * an address signed by WorkMate; WorkMate checks the arrived file's size and
 * MP4 header (small byte ranges only) and publishes the post. R2 is faked
 * here by an in-memory bucket behind Http::fake; the MP4s are real header
 * structures with an empty picture.
 */
class CommunityVideoTest extends ApiTestCase
{
    /** @var array<string, string> object key => bytes */
    private $bucket = [];

    /** @var array<int, array{0: string, 1: string}> method, key */
    private $calls = [];

    /** @var bool R2 answers with errors */
    private $r2Down = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'videos.r2.account_id' => 'acct123',
            'videos.r2.access_key_id' => 'AKIDTESTKEY',
            'videos.r2.secret_access_key' => 'very-secret-test-value',
            'videos.r2.bucket' => 'workmate-community-videos',
            'videos.r2.endpoint' => null,
        ]);

        Http::fake(function (HttpRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $key = rawurldecode(Str::after($path, '/workmate-community-videos/'));
            $this->calls[] = [$request->method(), $key];
            if ($this->r2Down) {
                return Http::response('', 500);
            }
            $exists = array_key_exists($key, $this->bucket);
            switch ($request->method()) {
                case 'HEAD':
                    return $exists ? Http::response('', 200, ['Content-Length' => strlen($this->bucket[$key]), 'Content-Type' => 'video/mp4']) : Http::response('', 404);
                case 'GET':
                    if (! $exists) {
                        return Http::response('', 404);
                    }
                    preg_match('/bytes=(\d+)-(\d+)/', (string) $request->header('Range')[0], $m);

                    return Http::response(substr($this->bucket[$key], (int) $m[1], (int) $m[2] - (int) $m[1] + 1), 206);
                case 'DELETE':
                    unset($this->bucket[$key]);

                    return Http::response('', 204);
            }

            return Http::response('', 400);
        });
    }

    /* ---------------- Helpers ---------------- */

    private function settings(array $values): void
    {
        $admin = $this->userWithRoles(['super_admin']);
        foreach ($values as $key => $value) {
            app(PlatformSettings::class)->set($key, $value, $admin);
        }
    }

    private function member(string $email = null): User
    {
        return $this->signIn(User::factory()->create(['name' => 'Aisha Nakato'] + ($email ? ['email' => $email] : [])));
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }

    /** A real MP4 header (no picture data) */
    private static function mp4(array $o = []): string
    {
        $o += ['brand' => 'isom', 'video' => 'avc1', 'audio' => 'mp4a', 'seconds' => 12, 'width' => 720, 'height' => 1280, 'moovAtEnd' => false, 'mdat' => 4000, 'fragmented' => false];
        $track = function (string $handler, string $format, int $w, int $h) {
            $tkhd = str_repeat("\0", 76).pack('N', $w << 16).pack('N', $h << 16);
            $hdlr = str_repeat("\0", 8).$handler.str_repeat("\0", 13);
            $stsd = "\0\0\0\0".pack('N', 1).pack('N', 16).$format.str_repeat("\0", 8);

            return self::box('trak', self::box('tkhd', $tkhd).self::box('mdia', self::box('hdlr', $hdlr).self::box('minf', self::box('stbl', self::box('stsd', $stsd)))));
        };
        $mvhd = "\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', (int) ($o['seconds'] * 1000)).str_repeat("\0", 80);
        $moov = self::box('moov', self::box('mvhd', $mvhd).$track('vide', $o['video'], $o['width'], $o['height'])
            .($o['audio'] ? $track('soun', $o['audio'], 0, 0) : ''));
        $ftyp = self::box('ftyp', $o['brand'].pack('N', 0).'isomavc1');
        $mdat = self::box('mdat', str_repeat("\x11", $o['mdat']));
        $extra = $o['fragmented'] ? self::box('moof', str_repeat("\0", 16)) : '';

        return $o['moovAtEnd'] ? $ftyp.$mdat.$extra.$moov : $ftyp.$moov.$extra.$mdat;
    }

    private function ask(string $bytes, array $overrides = [])
    {
        $this->travel(21)->seconds(); // past the three-a-minute posting limit

        return $this->postJson('/api/v1/community/video-uploads', $overrides + [
            'category' => 'my_work', 'body' => 'Fixing a leaking pipe', 'mime_type' => 'video/mp4',
            'size' => strlen($bytes), 'duration' => 12, 'width' => 720, 'height' => 1280,
            'client_token' => (string) Str::uuid(), 'file_name' => 'clip.mp4',
        ]);
    }

    /** What the phone does: PUT to the signed address (here, straight into the fake bucket) */
    private function putToStorage(string $uploadUrl, string $bytes): string
    {
        $key = rawurldecode(Str::after(parse_url($uploadUrl, PHP_URL_PATH), '/workmate-community-videos/'));
        $this->bucket[$key] = $bytes;

        return $key;
    }

    private function complete(string $uploadId, bool $poster = true)
    {
        $data = $poster ? ['poster' => UploadedFile::fake()->image('poster.jpg', 360, 640)] : [];

        return $this->post("/api/v1/community/video-uploads/{$uploadId}/complete", $data, ['Accept' => 'application/json']);
    }

    /** Asks, uploads and finishes; returns the post's JSON */
    private function share(string $bytes = null, array $overrides = [], bool $poster = true): array
    {
        $bytes = $bytes ?? self::mp4();
        $ticket = $this->ask($bytes, $overrides)->assertCreated()->json('data');
        $this->putToStorage($ticket['upload']['url'], $bytes);

        return $this->complete($ticket['upload_id'], $poster)->assertCreated()->json('data');
    }

    /* ---------------- Who may post: the community's own rules ---------------- */

    public function test_customers_providers_and_admins_who_can_post_can_upload_a_video()
    {
        $this->member();
        $this->assertArrayNotHasKey('can_post_video', $this->getJson('/api/v1/me')->assertOk()->json('data'));
        $this->ask(self::mp4())->assertCreated();

        $provider = $this->signIn(User::factory()->create(['name' => 'Okello Peter']));
        $this->putJson('/api/v1/me/provider', [
            'type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']],
        ])->assertOk();
        $provider->providerProfile->forceFill(['status' => 'approved', 'document_verified_at' => now()])->save();
        $this->ask(self::mp4())->assertCreated();

        $this->signIn($this->userWithRoles(['super_admin']));
        $this->ask(self::mp4())->assertCreated();

        $this->assertSame(3, CommunityVideoUpload::count());
    }

    public function test_signed_out_unconfirmed_and_suspended_members_cannot_upload()
    {
        $this->postJson('/api/v1/community/video-uploads', [])->assertUnauthorized();

        $this->signIn(User::factory()->unverified()->create());
        $this->ask(self::mp4())->assertStatus(403)->assertJsonPath('code', 'email_not_verified');

        $this->signIn(User::factory()->create(['status' => User::STATUS_SUSPENDED]));
        $this->ask(self::mp4())->assertStatus(403)->assertJsonPath('code', 'account_inactive');

        $this->assertSame(0, CommunityVideoUpload::count());
    }

    public function test_maintenance_pauses_videos_like_other_posts()
    {
        $this->settings(['platform.maintenance_mode' => true]);
        $this->member();
        $this->ask(self::mp4())->assertStatus(503)->assertJsonPath('code', 'maintenance');
    }

    public function test_without_storage_keys_uploads_are_refused_clearly()
    {
        config(['videos.r2.secret_access_key' => null]);
        $this->member();
        $this->ask(self::mp4())->assertStatus(503)->assertJsonPath('code', 'video_storage_unavailable');
        $this->assertSame(0, CommunityVideoUpload::count());
    }

    public function test_the_storage_check_command_reports_without_showing_keys()
    {
        $this->artisan('workmate:video-check')
            ->expectsOutput('R2 accepted the keys and the bucket exists. Video uploads can work (browser uploads also need the bucket\'s CORS rule).')
            ->doesntExpectOutput('very-secret-test-value')
            ->assertExitCode(0);

        $this->r2Down = true;
        $this->artisan('workmate:video-check')->assertExitCode(1);
        $this->r2Down = false;

        config(['videos.r2.access_key_id' => null]);
        $this->artisan('workmate:video-check')->assertExitCode(1);
    }

    /* ---------------- The whole journey ---------------- */

    public function test_a_video_goes_straight_to_storage_is_checked_and_published()
    {
        $author = $this->member();
        $bytes = self::mp4();

        $response = $this->ask($bytes)->assertCreated();
        $ticket = $response->json('data');

        // A signed address for this object only: right type, exact size, short-lived; no keys sent
        $url = $ticket['upload']['url'];
        $this->assertStringStartsWith('https://acct123.r2.cloudflarestorage.com/workmate-community-videos/community/', $url);
        $this->assertStringContainsString('X-Amz-SignedHeaders=content-length%3Bcontent-type%3Bhost', $url);
        $this->assertStringContainsString('X-Amz-Expires=1800', $url);
        $this->assertSame('PUT', $ticket['upload']['method']);
        $this->assertSame(['Content-Type' => 'video/mp4'], $ticket['upload']['headers']);
        $this->assertStringNotContainsString('very-secret-test-value', $response->getContent());
        $this->assertStringNotContainsString('AKIDTESTKEY', json_encode(array_diff_key($ticket, ['upload' => 1])));

        // Not published until finished
        $this->getJson('/api/v1/community/posts')->assertJsonCount(0, 'data');

        $key = $this->putToStorage($url, $bytes);
        $post = $this->complete($ticket['upload_id'])->assertCreated()->json('data');

        $this->assertSame('Fixing a leaking pipe', $post['body']);
        $this->assertCount(1, $post['media']);
        $video = $post['media'][0];
        $this->assertSame('video', $video['type']);
        $this->assertSame('ready', $video['status']);
        $this->assertSame([720, 1280], [$video['width'], $video['height']]);
        $this->assertEquals(12, $video['duration']);
        $this->assertSame(strlen($bytes), $video['size']);
        $this->assertStringStartsWith('https://acct123.r2.cloudflarestorage.com/workmate-community-videos/'.$key.'?', $video['url']);
        $this->assertStringContainsString('X-Amz-Expires=3600', $video['url']);
        $this->assertNotNull($video['poster_url']);
        $this->assertSame($video['poster_url'], str_replace('-t.jpg', '.jpg', $video['thumb_url']));
        $this->getJson(parse_url($video['poster_url'], PHP_URL_PATH))->assertOk();

        // Only the header was read, in small ranges; nothing else fetched
        $this->assertSame([['HEAD', $key], ['GET', $key]], $this->calls);

        // It's in the feed for everyone, and stays after a reload
        $this->member();
        $this->getJson('/api/v1/community/posts')->assertJsonCount(1, 'data')->assertJsonPath('data.0.media.0.type', 'video');
        $this->getJson("/api/v1/community/posts/{$post['id']}")->assertOk()->assertJsonPath('data.media.0.status', 'ready');

        $upload = CommunityVideoUpload::first();
        $this->assertSame('completed', $upload->status);
        $this->assertSame($author->id, CommunityPost::first()->user_id);
        $this->assertTrue(AuditLog::where('action', 'community.post_published')->exists());

        // No video was kept on the server: only the poster (and its sizes), as JPEGs
        foreach (Storage::disk('local')->allFiles() as $file) {
            $this->assertStringEndsWith('.jpg', $file);
        }
    }

    public function test_finishing_twice_gives_the_same_post_and_asking_twice_the_same_upload()
    {
        $this->member();
        $bytes = self::mp4();
        $token = (string) Str::uuid();
        $first = $this->ask($bytes, ['client_token' => $token])->assertCreated()->json('data.upload_id');
        $again = $this->ask($bytes, ['client_token' => $token])->assertCreated()->json('data');
        $this->assertSame($first, $again['upload_id']);

        $this->putToStorage($again['upload']['url'], $bytes);
        $one = $this->complete($first)->assertCreated()->json('data.id');
        $two = $this->complete($first)->assertCreated()->json('data.id');
        $this->assertSame($one, $two);
        $this->assertSame(1, CommunityPost::count());
    }

    public function test_moov_at_the_end_and_no_sound_and_no_poster_are_fine()
    {
        $this->member();
        $post = $this->share(self::mp4(['moovAtEnd' => true, 'audio' => null, 'mdat' => 70000]), [], false);
        $this->assertNull($post['media'][0]['poster_url']);
        $this->assertSame('ready', $post['media'][0]['status']);
    }

    public function test_a_phone_video_turned_on_its_side_keeps_the_size_the_phone_saw()
    {
        $this->member();
        // The file says 1280x720 with a rotation; the phone showed 720x1280
        $post = $this->share(self::mp4(['width' => 1280, 'height' => 720]));
        $this->assertSame([720, 1280], [$post['media'][0]['width'], $post['media'][0]['height']]);
    }

    /* ---------------- Refused ---------------- */

    public function test_what_the_phone_claims_is_checked_before_an_address_is_given()
    {
        $this->member();
        $this->ask(self::mp4(), ['mime_type' => 'video/webm'])->assertStatus(422)->assertJsonValidationErrors('mime_type');
        $this->ask(self::mp4(), ['size' => 26 * 1024 * 1024])->assertStatus(422)->assertJsonValidationErrors('size');
        $this->ask(self::mp4(), ['duration' => 61])->assertStatus(422)->assertJsonValidationErrors('duration');
        $this->ask(self::mp4(), ['category' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->ask(self::mp4(), ['client_token' => 'not-a-uuid'])->assertStatus(422);
        $this->assertSame(0, CommunityVideoUpload::count());
    }

    public function test_finishing_before_the_video_arrived_can_be_retried()
    {
        $this->member();
        $bytes = self::mp4();
        $ticket = $this->ask($bytes)->json('data');
        $this->complete($ticket['upload_id'])->assertStatus(422)->assertJsonPath('code', 'video_not_received');
        $this->assertSame('pending', CommunityVideoUpload::first()->status);

        $this->putToStorage($ticket['upload']['url'], $bytes);
        $this->complete($ticket['upload_id'])->assertCreated();
    }

    public function test_a_different_file_than_announced_is_refused_and_removed()
    {
        $this->member();
        $ticket = $this->ask(self::mp4())->json('data');
        $key = $this->putToStorage($ticket['upload']['url'], self::mp4(['mdat' => 9000]));
        $this->complete($ticket['upload_id'])->assertStatus(422)->assertJsonPath('code', 'video_not_received');
        $this->assertSame('failed', CommunityVideoUpload::first()->status);
        $this->assertArrayNotHasKey($key, $this->bucket);
        $this->assertSame(0, CommunityPost::count());
    }

    /** @dataProvider badVideos */
    public function test_videos_phones_cannot_play_are_refused_and_removed(array $mp4, string $code)
    {
        $this->member();
        $bytes = self::mp4($mp4);
        $ticket = $this->ask($bytes)->assertCreated()->json('data');
        $key = $this->putToStorage($ticket['upload']['url'], $bytes);

        $this->complete($ticket['upload_id'])->assertStatus(422)->assertJsonPath('code', $code);
        $this->assertSame('failed', CommunityVideoUpload::first()->status);
        $this->assertArrayNotHasKey($key, $this->bucket, 'refused file left in storage');
        $this->assertSame(0, CommunityPost::count());
    }

    public function badVideos(): array
    {
        return [
            'H.265 (HEVC)' => [['video' => 'hvc1'], 'video_hevc'],
            'iPhone MOV container' => [['brand' => 'qt  '], 'video_mov'],
            'unknown video codec' => [['video' => 'mp4v'], 'video_codec'],
            'AMR sound' => [['audio' => 'samr'], 'video_audio_codec'],
            'fragmented MP4' => [['fragmented' => true], 'video_fragmented'],
            'really longer than claimed' => [['seconds' => 95], 'video_too_long'],
        ];
    }

    public function test_a_file_that_is_not_an_mp4_at_all_is_refused()
    {
        $this->member();
        $bytes = str_repeat('not a video ', 400);
        $ticket = $this->ask($bytes)->json('data');
        $this->putToStorage($ticket['upload']['url'], $bytes);
        $this->complete($ticket['upload_id'])->assertStatus(422)->assertJsonPath('code', 'video_damaged');
    }

    public function test_storage_trouble_says_so_and_the_upload_can_be_finished_later()
    {
        $this->member();
        $bytes = self::mp4();
        $ticket = $this->ask($bytes)->json('data');
        $this->putToStorage($ticket['upload']['url'], $bytes);
        $this->r2Down = true;
        $this->complete($ticket['upload_id'])->assertStatus(503)->assertJsonPath('code', 'video_storage_unavailable');
        $this->assertSame('pending', CommunityVideoUpload::first()->status);
        $this->r2Down = false;
        $this->complete($ticket['upload_id'])->assertCreated();
    }

    public function test_an_upload_left_too_long_has_expired()
    {
        $this->member();
        $bytes = self::mp4();
        $ticket = $this->ask($bytes)->json('data');
        $this->putToStorage($ticket['upload']['url'], $bytes);
        $this->travel(121)->minutes();
        $this->complete($ticket['upload_id'])->assertStatus(410)->assertJsonPath('code', 'upload_expired');
    }

    public function test_only_a_few_uploads_at_once()
    {
        $this->member();
        for ($i = 0; $i < 3; $i++) {
            $this->ask(self::mp4())->assertCreated();
        }
        $this->ask(self::mp4())->assertStatus(429)->assertJsonPath('code', 'too_many_uploads');
    }

    /* ---------------- Someone else's upload ---------------- */

    public function test_no_one_can_finish_or_stop_someone_elses_upload()
    {
        $this->member();
        $bytes = self::mp4();
        $ticket = $this->ask($bytes)->json('data');
        $this->putToStorage($ticket['upload']['url'], $bytes);

        $this->member();
        $this->complete($ticket['upload_id'])->assertNotFound();
        $this->deleteJson("/api/v1/community/video-uploads/{$ticket['upload_id']}")->assertNotFound();
        $this->assertSame('pending', CommunityVideoUpload::first()->status);
        $this->assertSame(0, CommunityPost::count());
    }

    public function test_stopping_an_upload_removes_its_file()
    {
        $this->member();
        $bytes = self::mp4();
        $ticket = $this->ask($bytes)->json('data');
        $key = $this->putToStorage($ticket['upload']['url'], $bytes);
        $this->deleteJson("/api/v1/community/video-uploads/{$ticket['upload_id']}")->assertNoContent();
        $this->assertSame('cancelled', CommunityVideoUpload::first()->status);
        $this->assertArrayNotHasKey($key, $this->bucket);
        $this->complete($ticket['upload_id'])->assertStatus(409);
    }

    /* ---------------- Watching, removing, deleting ---------------- */

    public function test_removed_posts_get_no_playback_address_until_restored()
    {
        $this->member();
        $post = $this->share();
        $media = $post['media'][0]['id'];
        $this->getJson("/api/v1/community/media/{$media}/playback")->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']]);

        $this->signIn($this->userWithRoles(['content']));
        $this->postJson("/api/v1/admin/community/posts/{$post['id']}/remove", ['reason' => 'Unsafe work shown'])->assertOk();

        // Not for anyone: the author still sees the post, but not the video
        $this->signIn(User::find(CommunityPost::first()->user_id));
        $this->getJson("/api/v1/community/posts/{$post['id']}")->assertOk()
            ->assertJsonPath('data.media.0.url', null)->assertJsonPath('data.media.0.status', 'unavailable');
        $this->getJson("/api/v1/community/media/{$media}/playback")->assertNotFound();

        $this->signIn($this->userWithRoles(['content']));
        $this->postJson("/api/v1/admin/community/posts/{$post['id']}/restore", ['reason' => 'Reviewed again'])->assertOk();
        $this->getJson("/api/v1/community/media/{$media}/playback")->assertOk();
    }

    public function test_photos_are_not_given_playback_addresses()
    {
        $this->member();
        $this->travel(21)->seconds();
        $this->post('/api/v1/community/posts', ['category' => 'tips', 'body' => 'x', 'photos' => [UploadedFile::fake()->image('a.jpg', 400, 300)]], ['Accept' => 'application/json'])->assertCreated();
        $photo = CommunityPostMedia::first();
        $this->assertSame('photo', $photo->type);
        $this->getJson("/api/v1/community/media/{$photo->uuid}/playback")->assertNotFound();
        $this->assertArrayNotHasKey('type', $this->getJson('/api/v1/community/posts')->json('data.0.media.0'));
    }

    public function test_a_deleted_posts_video_stops_playing_and_is_removed_from_storage_later()
    {
        $this->member();
        $post = $this->share();
        $media = CommunityPostMedia::first();
        $this->deleteJson("/api/v1/community/posts/{$post['id']}")->assertOk();
        $this->getJson("/api/v1/community/media/{$media->uuid}/playback")->assertNotFound();
        $this->getJson("/api/v1/community/posts/{$post['id']}")->assertNotFound();

        // Kept a few days (for reports), then removed; a storage failure is retried
        $this->artisan('workmate:video-cleanup')->assertExitCode(0);
        $this->assertArrayHasKey($media->video_key, $this->bucket);

        $this->travel(8)->days();
        $this->r2Down = true;
        $this->artisan('workmate:video-cleanup')->assertExitCode(0);
        $this->assertNull($media->fresh()->video_deleted_at);
        $this->r2Down = false;
        $this->artisan('workmate:video-cleanup')->assertExitCode(0);
        $this->assertNotNull($media->fresh()->video_deleted_at);
        $this->assertArrayNotHasKey($media->video_key, $this->bucket);
    }

    public function test_abandoned_uploads_expire_and_their_files_are_removed()
    {
        $this->member();
        $bytes = self::mp4();
        $ticket = $this->ask($bytes)->json('data');
        $key = $this->putToStorage($ticket['upload']['url'], $bytes);

        $this->artisan('workmate:video-cleanup');
        $this->assertSame('pending', CommunityVideoUpload::first()->status);
        $this->assertArrayHasKey($key, $this->bucket);

        $this->travel(4)->hours();
        $this->artisan('workmate:video-cleanup');
        $upload = CommunityVideoUpload::first();
        $this->assertSame('expired', $upload->status);
        $this->artisan('workmate:video-cleanup');
        $this->assertNotNull($upload->fresh()->object_deleted_at);
        $this->assertArrayNotHasKey($key, $this->bucket);
    }

    public function test_the_photo_watermark_catch_up_skips_videos()
    {
        $this->member();
        $this->share(self::mp4(), []);
        // A video with no poster
        $ticket = $this->ask(self::mp4())->json('data');
        $this->putToStorage($ticket['upload']['url'], self::mp4());
        $this->complete($ticket['upload_id'], false)->assertCreated();

        $this->artisan('workmate:watermark-photos')->assertExitCode(0);
        $this->assertNull(CommunityPostMedia::where('path', '')->value('watermark_error'));
    }

    /* ---------------- The signature ---------------- */

    public function test_signed_addresses_match_the_published_aws_example()
    {
        // docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
        $url = R2Storage::presignUrl('GET', 'https', 'examplebucket.s3.amazonaws.com', '/test.txt', 'us-east-1', 's3',
            'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 86400, [], new \DateTimeImmutable('2013-05-24T00:00:00Z'));
        $this->assertStringEndsWith('X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $url);
    }
}
