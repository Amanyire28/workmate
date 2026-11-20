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
        $o += ['brand' => 'isom', 'compatible' => 'isomavc1', 'video' => 'avc1', 'audio' => 'mp4a', 'seconds' => 12, 'width' => 720, 'height' => 1280,
            'moovAtEnd' => false, 'mdat' => 4000, 'fragmented' => false, 'ftyp' => true, 'before' => '', 'after' => ''];
        $track = function (string $handler, string $format, int $w, int $h) {
            $tkhd = str_repeat("\0", 76).pack('N', $w << 16).pack('N', $h << 16);
            $hdlr = str_repeat("\0", 8).$handler.str_repeat("\0", 13);
            $stsd = "\0\0\0\0".pack('N', 1).pack('N', 16).$format.str_repeat("\0", 8);

            return self::box('trak', self::box('tkhd', $tkhd).self::box('mdia', self::box('hdlr', $hdlr).self::box('minf', self::box('stbl', self::box('stsd', $stsd)))));
        };
        $mvhd = "\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', (int) ($o['seconds'] * 1000)).str_repeat("\0", 80);
        $moov = self::box('moov', self::box('mvhd', $mvhd).$track('vide', $o['video'], $o['width'], $o['height'])
            .($o['audio'] ? $track('soun', $o['audio'], 0, 0) : ''));
        $ftyp = $o['ftyp'] ? self::box('ftyp', $o['brand'].pack('N', 0).$o['compatible']) : '';
        $mdat = self::box('mdat', str_repeat("\x11", $o['mdat']));
        $extra = $o['fragmented'] ? self::box('moof', str_repeat("\0", 16)) : '';

        return $o['before'].($o['moovAtEnd'] ? $ftyp.$mdat.$extra.$moov : $ftyp.$moov.$extra.$mdat).$o['after'];
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
        $this->ask(self::mp4(), ['mime_type' => 'video/x-msvideo'])->assertStatus(422)->assertJsonValidationErrors('mime_type');
        $this->ask(self::mp4(), ['size' => 26 * 1024 * 1024])->assertStatus(422)->assertJsonValidationErrors('size');
        $this->ask(self::mp4(), ['width' => 9000])->assertStatus(422)->assertJsonValidationErrors('width');
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
    public function test_files_that_are_not_usable_videos_are_refused_and_removed(array $mp4, string $code)
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
            'unknown video codec' => [['video' => 'zzz1'], 'video_codec'],
            'encrypted video' => [['video' => 'encv'], 'video_encrypted'],
            'picture too large' => [['width' => 9000, 'height' => 9000], 'video_size'],
        ];
    }

    /** @dataProvider originalVideos */
    public function test_original_phone_videos_are_kept_as_they_are(array $mp4, string $mime, string $container, ?float $seconds)
    {
        $this->member();
        $bytes = self::mp4($mp4);
        $ticket = $this->ask($bytes, ['mime_type' => $mime, 'width' => $mp4['width'] ?? 720, 'height' => $mp4['height'] ?? 1280])->assertCreated()->json('data');
        $this->assertSame(['Content-Type' => 'video/mp4'], $ticket['upload']['headers']);
        $key = $this->putToStorage($ticket['upload']['url'], $bytes);

        $video = $this->complete($ticket['upload_id'])->assertCreated()->json('data.media.0');
        $this->assertSame('ready', $video['status']);
        $this->assertEquals($seconds, $video['duration']);
        // The very bytes that were uploaded
        $this->assertSame($bytes, $this->bucket[$key]);
        $this->assertStringEndsWith('.mp4', $key);
    }

    public function originalVideos(): array
    {
        return [
            'Samsung/iPhone H.265 (HEVC) MP4' => [['video' => 'hvc1'], 'video/mp4', 'mp4', 12.0],
            'iPhone MOV (H.264)' => [['brand' => 'qt  '], 'video/quicktime', 'mov', 12.0],
            'iPhone MOV (HEVC, Dolby Vision)' => [['brand' => 'qt  ', 'video' => 'dvh1'], 'video/quicktime', 'mov', 12.0],
            '3GP with AMR sound' => [['brand' => '3gp5', 'video' => 's263', 'audio' => 'samr', 'width' => 352, 'height' => 288], 'video/3gpp', '3gp', 12.0],
            'longer than a minute (no length limit)' => [['seconds' => 95], 'video/mp4', 'mp4', 95.0],
            'fragmented MP4' => [['fragmented' => true], 'video/mp4', 'mp4', 12.0],
            '8K Samsung' => [['width' => 7680, 'height' => 4320], 'video/mp4', 'mp4', 12.0],
            'VP9 in MP4' => [['video' => 'vp09', 'audio' => 'Opus'], 'video/mp4', 'mp4', 12.0],
        ];
    }

    /* ---------------- MP4 brands and layouts (Samsung and others) ---------------- */

    // Samsung appends a trailer after the last box (not a box itself), ending in "SEFT"
    private const SAMSUNG_TRAILER = 'SEFH'.'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'."Image_UTC_Data1700000000000\0\0\0\0".'SEFT';

    /** @dataProvider legitimateMp4s */
    public function test_legitimate_mp4_brands_and_layouts_are_accepted(array $mp4, string $container)
    {
        $this->member();
        $bytes = self::mp4($mp4);
        $ticket = $this->ask($bytes)->assertCreated()->json('data');
        $key = $this->putToStorage($ticket['upload']['url'], $bytes);
        $video = $this->complete($ticket['upload_id'])->assertCreated()->json('data.media.0');
        $this->assertSame('ready', $video['status']);
        $this->assertEquals(12, $video['duration']);
        $this->assertSame($bytes, $this->bucket[$key]);
    }

    public function legitimateMp4s(): array
    {
        $brands = [];
        foreach (['isom' => 'isomavc1', 'iso2' => 'isomiso2avc1mp41', 'mp41' => 'isommp41', 'mp42' => 'isommp42', 'avc1' => 'isomavc1',
            '3gp4' => '3gp4isom', 'MSNV' => 'MSNVmp42isom', 'XAVC' => 'XAVCmp42iso2', 'qt  ' => 'qt  '] as $brand => $compatible) {
            $brands["brand {$brand}"] = [['brand' => $brand, 'compatible' => $compatible], $brand === 'qt  ' ? 'mov' : 'mp4'];
        }

        return $brands + [
            'HEVC hvc1, moov at the end (Samsung)' => [['video' => 'hvc1', 'moovAtEnd' => true, 'brand' => 'mp42', 'compatible' => 'isommp42'], 'mp4'],
            'HEVC hev1' => [['video' => 'hev1'], 'mp4'],
            'Samsung trailer after the movie header' => [['moovAtEnd' => true, 'after' => self::SAMSUNG_TRAILER], 'mp4'],
            'Samsung trailer, HEVC' => [['video' => 'hvc1', 'moovAtEnd' => true, 'after' => self::SAMSUNG_TRAILER], 'mp4'],
            'a proper box after the movie header (sefd)' => [['moovAtEnd' => true, 'after' => self::box('sefd', self::SAMSUNG_TRAILER)], 'mp4'],
            'free box before ftyp' => [['before' => self::box('free', str_repeat("\0", 16))], 'mp4'],
            'uuid box before ftyp' => [['before' => self::box('uuid', str_repeat("\x07", 24))], 'mp4'],
            'no ftyp (older QuickTime layout)' => [['ftyp' => false, 'before' => self::box('wide', ''), 'moovAtEnd' => true], 'mov'],
        ];
    }

    /** @dataProvider malformedMp4s */
    public function test_malformed_mp4s_are_still_refused(string $bytes, string $code)
    {
        $this->member();
        $ticket = $this->ask($bytes)->assertCreated()->json('data');
        $key = $this->putToStorage($ticket['upload']['url'], $bytes);
        $this->complete($ticket['upload_id'])->assertStatus(422)->assertJsonPath('code', $code);
        $this->assertArrayNotHasKey($key, $this->bucket);
        $this->assertSame(0, CommunityPost::count());
    }

    public function malformedMp4s(): array
    {
        $ftyp = self::box('ftyp', 'isom'.pack('N', 0).'isomavc1');
        $good = self::mp4(['moovAtEnd' => true]);

        return [
            'junk before the movie header' => [$ftyp.'not a box at all, just junk bytes here'.substr($good, 28), 'video_damaged'],
            'unknown first box' => [substr($good, 0, 4).'abcd'.substr($good, 8), 'video_unsupported_format'],
            'first box with an impossible size' => ["\x7f\xff\xff\xff".substr($good, 4), 'video_damaged'],
            'no movie header' => [$ftyp.self::box('mdat', str_repeat("\x11", 3000)), 'video_damaged'],
            'no ftyp and no movie header' => [self::box('free', '').self::box('mdat', str_repeat("\x11", 3000)), 'video_not_mp4'],
            'movie header cut short' => [substr($good, 0, -60), 'video_damaged'],
            'Samsung trailer with no video before it' => [$ftyp.self::SAMSUNG_TRAILER, 'video_damaged'],
        ];
    }

    /* ---------------- WebM and MKV ---------------- */

    private static function ebml(string $id, string $payload): string
    {
        $n = strlen($payload);
        $size = $n < 127 ? chr(0x80 | $n) : ($n < 16383 ? chr(0x40 | ($n >> 8)).chr($n & 0xFF) : "\x01".substr(pack('J', $n), 1));

        return $id.$size.$payload;
    }

    /** A real WebM/MKV header (no picture data) */
    private static function webm(array $o = []): string
    {
        $o += ['doc' => 'webm', 'video' => 'V_VP9', 'audio' => 'A_OPUS', 'seconds' => 33.5, 'width' => 1080, 'height' => 1920, 'encrypted' => false];
        $info = self::ebml("\x2A\xD7\xB1", "\x0F\x42\x40")   // timecode scale 1,000,000 ns
            .($o['seconds'] !== null ? self::ebml("\x44\x89", pack('E', $o['seconds'] * 1000)) : '');
        $video = self::ebml("\xAE", self::ebml("\x83", "\x01").self::ebml("\x86", $o['video'])
            .($o['encrypted'] ? self::ebml("\x6D\x80", self::ebml("\x62\x40", "")) : '')
            .self::ebml("\xE0", self::ebml("\xB0", pack('n', $o['width'])).self::ebml("\xBA", pack('n', $o['height']))));
        $audio = $o['audio'] ? self::ebml("\xAE", self::ebml("\x83", "\x02").self::ebml("\x86", $o['audio'])) : '';
        $segment = self::ebml("\x15\x49\xA9\x66", $info).self::ebml("\x16\x54\xAE\x6B", $video.$audio)
            .self::ebml("\x1F\x43\xB6\x75", str_repeat("\x22", 3000));

        return self::ebml("\x1A\x45\xDF\xA3", self::ebml("\x42\x82", $o['doc']))
            // A segment of unknown size, as browsers write while recording
            ."\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".$segment;
    }

    public function test_webm_and_mkv_videos_are_checked_and_kept()
    {
        $this->member();
        foreach ([
            [self::webm(), 'video/webm', 33.5],
            [self::webm(['seconds' => null]), 'video/webm', null],               // browser recordings often don't say
            [self::webm(['doc' => 'matroska', 'video' => 'V_MPEGH/ISO/HEVC', 'audio' => 'A_AAC']), 'video/x-matroska', 33.5],
        ] as [$bytes, $mime, $seconds]) {
            $ticket = $this->ask($bytes, ['mime_type' => $mime, 'width' => 1080, 'height' => 1920])->assertCreated()->json('data');
            $this->assertSame(['Content-Type' => 'video/webm'], $ticket['upload']['headers']);
            $key = $this->putToStorage($ticket['upload']['url'], $bytes);
            $this->assertStringEndsWith('.webm', $key);
            $video = $this->complete($ticket['upload_id'])->assertCreated()->json('data.media.0');
            $this->assertSame([1080, 1920, 'ready'], [$video['width'], $video['height'], $video['status']]);
            $this->assertEquals($seconds, $video['duration']);
        }
    }

    public function test_bad_webm_files_are_refused()
    {
        $this->member();
        foreach ([
            [self::webm(['video' => 'V_THEORA']), 'video_codec'],
            [self::webm(['encrypted' => true]), 'video_encrypted'],
            [self::webm(['doc' => 'notvideo']), 'video_not_webm'],
            [substr(self::webm(), 0, 40), 'video_damaged'],
        ] as [$bytes, $code]) {
            $ticket = $this->ask($bytes, ['mime_type' => 'video/webm'])->assertCreated()->json('data');
            $this->putToStorage($ticket['upload']['url'], $bytes);
            $this->complete($ticket['upload_id'])->assertStatus(422)->assertJsonPath('code', $code);
        }
        $this->assertSame(0, CommunityPost::count());
    }

    public function test_the_files_real_structure_must_match_what_was_announced()
    {
        $this->member();
        // Announced as WebM, but it's an MP4 (or the other way round): refused, whatever the name says
        foreach ([[self::mp4(), 'video/webm'], [self::webm(), 'video/mp4']] as [$bytes, $mime]) {
            $ticket = $this->ask($bytes, ['mime_type' => $mime])->assertCreated()->json('data');
            $key = $this->putToStorage($ticket['upload']['url'], $bytes);
            $this->complete($ticket['upload_id'])->assertStatus(422)->assertJsonPath('code', 'video_unsupported_format');
            $this->assertArrayNotHasKey($key, $this->bucket);
        }
    }

    public function test_a_file_that_is_not_a_video_at_all_is_refused()
    {
        $this->member();
        $bytes = str_repeat('not a video ', 400);
        $ticket = $this->ask($bytes)->json('data');
        $this->putToStorage($ticket['upload']['url'], $bytes);
        $this->complete($ticket['upload_id'])->assertStatus(422)->assertJsonPath('code', 'video_unsupported_format');
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
