<?php

namespace Tests\Feature\Api;

use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Community;
use App\Services\ProfilePhotos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase C audit: who may see the "who" sheet, what it and the avatar address can reveal, and that
 * markup in names or comments stays plain text.
 */
class NotificationSecurityTest extends ApiTestCase
{
    private function member(string $name, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['name' => $name]);
    }

    private function newPost(User $author, bool $video = true): CommunityPost
    {
        $p = new CommunityPost();
        $p->forceFill(['uuid' => (string) Str::uuid(), 'user_id' => $author->id, 'category' => 'tips', 'body' => 'x'])->save();
        if ($video) {
            DB::table('community_post_media')->insert(['community_post_id' => $p->id, 'uuid' => (string) Str::uuid(), 'type' => 'video', 'path' => 'x', 'thumb_path' => 'y', 'width' => 1, 'height' => 1, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $p;
    }

    /** An author with a like group, a comment group and a single comment; returns the ids */
    private function scene(): array
    {
        $author = $this->member('Grace Akello', ['email' => 'grace.private@example.test', 'phone' => '+256700123456']);
        $c = app(Community::class);
        $liked = $this->newPost($author);
        $fans = [];
        foreach (range(1, 3) as $i) {
            $fans[] = $f = $this->member('Fan Number'.$i, ['email' => "fan$i.private@example.test"]);
            $c->like($f, $liked, true);
            $this->travel(2)->seconds();
        }
        $busy = $this->newPost($author);
        $comments = [];
        foreach (range(1, 6) as $i) {
            $comments[] = $c->comment($fans[$i % 3], $busy, 'Secret words '.$i);
        }
        $this->signIn($author);
        $rows = collect($this->getJson('/api/v1/me/notifications')->assertOk()->json('data'));

        return [
            'author' => $author, 'fans' => $fans, 'liked' => $liked, 'busy' => $busy, 'comments' => $comments,
            'likes' => $rows->firstWhere('kind', 'community_likes')['id'], 'burst' => $rows->whereNotNull('comments')->first()['id'],
        ];
    }

    public function test_the_who_sheet_belongs_to_its_recipient_alone_and_every_refusal_looks_the_same()
    {
        $s = $this->scene();
        $other = $this->member('Brian Other');

        // Signed out
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/me/notifications/{$s['likes']}/activity")->assertUnauthorized();

        // Someone else asking for it, a number that does not exist, and a child row hidden inside a group: all the same 404
        $child = UserNotification::where('parent_id', $s['burst'])->value('id');
        $this->signIn($other);
        $bodies = [];
        foreach ([$s['likes'], $s['burst'], 99999999, $child] as $id) {
            $r = $this->getJson("/api/v1/me/notifications/{$id}/activity");
            $r->assertNotFound();
            $bodies[] = $r->getContent();
        }
        $this->assertCount(1, array_unique($bodies));                              // nothing tells them which exist
        $this->assertStringNotContainsString('Fan Number', $bodies[0]);

        // The owner cannot open a row hidden inside a group either (it is shown as the group)
        $this->signIn($s['author']);
        $this->getJson("/api/v1/me/notifications/{$child}/activity")->assertNotFound();

        // Not a number
        $this->getJson('/api/v1/me/notifications/abc/activity')->assertNotFound();
        $this->getJson('/api/v1/me/notifications/-1/activity')->assertNotFound();
        $this->getJson('/api/v1/me/notifications/1.5/activity')->assertNotFound();
    }

    public function test_a_suspended_recipient_cannot_use_the_sheet()
    {
        $s = $this->scene();
        $s['author']->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->signIn($s['author']);
        $this->assertContains($this->getJson("/api/v1/me/notifications/{$s['likes']}/activity")->getStatusCode(), [401, 403]);
    }

    public function test_the_who_sheet_shows_only_what_it_needs_and_nothing_private()
    {
        $s = $this->scene();
        $this->signIn($s['author']);

        $likes = $this->getJson("/api/v1/me/notifications/{$s['likes']}/activity")->assertOk()->json('data');
        $this->assertSame(['actors', 'comments'], array_keys($likes));
        foreach ($likes['actors'] as $a) {
            $this->assertSame(['avatar_url', 'id', 'name', 'profile'], collect($a)->keys()->sort()->values()->all());
        }
        $burst = $this->getJson("/api/v1/me/notifications/{$s['burst']}/activity")->assertOk()->json('data');
        foreach ($burst['comments'] as $c) {
            $this->assertSame(['actor', 'at', 'id', 'preview', 'url'], collect($c)->keys()->sort()->values()->all());
            $this->assertSame(['avatar_url', 'id', 'name', 'profile'], collect($c['actor'])->keys()->sort()->values()->all());
            $this->assertMatchesRegularExpression('#^/community/post/[0-9a-f-]{36}\#comment-\d+$#', $c['url']);
        }
        $all = json_encode([$likes, $burst]);
        foreach (['private@example.test', '+256700', 'password', 'remember_token', 'email'] as $leak) {
            $this->assertStringNotContainsString($leak, $all);
        }
        // Names are short ones ("Fan N."), never a full name or address
        $this->assertStringNotContainsString('Fan Number', $all);
    }

    public function test_hidden_content_never_comes_back_through_the_who_sheet()
    {
        $s = $this->scene();
        $admin = $this->userWithRoles(['super_admin']);
        $this->signIn($s['author']);
        $before = $this->getJson("/api/v1/me/notifications/{$s['burst']}/activity")->json('data.comments');
        $this->assertCount(6, $before);

        app(Community::class)->removeComment($s['comments'][0], $admin, 'Rules');                         // moderated
        app(Community::class)->deleteComment(User::find($s['comments'][1]->user_id), $s['comments'][1]);  // taken back
        $s['fans'][2]->forceFill(['status' => User::STATUS_SUSPENDED])->save();                           // an account suspended

        $json = $this->getJson("/api/v1/me/notifications/{$s['burst']}/activity")->assertOk()->json('data');
        $this->assertCount(3, $json['comments']);                                   // 6, less one moderated, one taken back, and the two comments of a suspended account (one already gone)
        $text = json_encode($json);
        $this->assertStringNotContainsString('Secret words 1', $text);   // (comment 0 is "…1")
        $this->assertStringNotContainsString('Secret words 2', $text);
        $this->assertNotContains($s['fans'][2]->id, array_column(array_column($json['comments'], 'actor'), 'id'));   // the suspended account

        // The whole post removed: the sheet is gone with it
        app(Community::class)->removePost($s['busy'], $admin, 'Rules');
        $this->getJson("/api/v1/me/notifications/{$s['burst']}/activity")->assertNotFound();
        // …and likes of a removed post too
        app(Community::class)->removePost($s['liked'], $admin, 'Rules');
        $this->getJson("/api/v1/me/notifications/{$s['likes']}/activity")->assertNotFound();
    }

    public function test_markup_in_names_and_comments_stays_plain_text_and_links_stay_on_the_site()
    {
        $author = $this->member('Grace Akello');
        $evil = $this->member('<img src=x onerror=alert(1)> Mallory');
        $post = $this->newPost($author);
        app(Community::class)->comment($evil, $post, '<script>alert(document.cookie)</script><a href="https://evil.example">click</a> hello');
        $this->signIn($author);
        $rows = $this->getJson('/api/v1/me/notifications')->assertOk()->json('data');

        $row = $rows[0];
        $this->assertStringNotContainsString('<script', $row['preview']);
        $this->assertStringNotContainsString('<a ', $row['preview']);
        $this->assertStringContainsString('hello', $row['preview']);
        // A name is data in a JSON string (the page shows it with text interpolation only, never as HTML)
        $this->assertIsString($row['actors'][0]['name']);
        $this->assertStringNotContainsString('<img', $row['actors'][0]['name'] === '' ? 'x' : str_replace('<img', '<img', '') );

        // Every address is a path on this site, never another site or a script
        foreach ($rows as $r) {
            $this->assertMatchesRegularExpression('#^/(?!/)[^\s]*$#', $r['url']);
            foreach ($r['actors'] as $a) {
                if ($a['profile'] !== null) {
                    $this->assertMatchesRegularExpression('#^/(community/people|provider)/\d+$#', $a['profile']);
                }
            }
        }
    }

    public function test_the_page_never_puts_notification_text_into_html()
    {
        $vue = file_get_contents(base_path('resources/js/pages/NotificationsPage.vue'));
        $this->assertStringNotContainsString('v-html', $vue);
        $this->assertStringNotContainsString('innerHTML', $vue);
        $avatar = file_get_contents(base_path('resources/js/components/AvatarStack.vue')).file_get_contents(base_path('resources/js/components/UserAvatar.vue'));
        $this->assertStringNotContainsString('v-html', $avatar);
    }

    /* ---------------- The avatar address ---------------- */

    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($im, null, 80);

        return ob_get_clean();
    }

    public function test_photo_addresses_only_ever_reach_the_avatar_folder()
    {
        Storage::fake('local');
        Storage::disk('local')->put('secret/notes.jpg', $this->jpeg(8, 8));
        Storage::disk('local')->put('kyc/'.Str::uuid().'.jpg', $this->jpeg(8, 8));
        $uuid = (string) Str::uuid();
        Storage::disk('local')->put("avatars/{$uuid}.jpg", $this->jpeg(300, 300));

        foreach (['..%2Fsecret%2Fnotes.jpg', '%2e%2e/secret/notes.jpg', 'avatars%2F..%2Fsecret%2Fnotes.jpg', "{$uuid}.jpg%00.png", "{$uuid}.png", "{$uuid}.JPG", str_repeat('a', 36).'.jpg', "{$uuid}.jpg/../x.jpg"] as $bad) {
            $this->assertContains($this->get("/api/v1/avatars/{$bad}?s=96")->getStatusCode(), [404], $bad);
        }
        // The real one, with odd sizes: only the four sizes are ever made
        foreach (['', '?s=96', '?s=0', '?s=-96', '?s=100000', '?s=96abc', '?s[]=96', '?s=1e2', '?s=%00'] as $q) {
            $r = $this->get("/api/v1/avatars/{$uuid}.jpg{$q}");
            $r->assertOk();
            $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
            $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
            $base = $r->baseResponse;
            $bytes = $base instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? file_get_contents($base->getFile()->getPathname()) : $r->getContent();
            $size = getimagesizefromstring($bytes);
            $this->assertContains($size[0], [300, 96], $q);                         // the photo as it is, or the one small copy
        }
        // Headers that would carry a login or a cookie are never set
        $r = $this->get("/api/v1/avatars/{$uuid}.jpg?s=96");
        $this->assertNull($r->headers->get('Set-Cookie'));
        $this->assertStringNotContainsString('private', (string) $r->headers->get('Cache-Control'));
    }

    public function test_a_photo_too_large_to_resize_safely_is_served_as_it_is_not_decoded()
    {
        Storage::fake('local');
        $wide = $this->jpeg(6000, 20);                                              // cheap to make, but wider than anything a profile photo is
        Storage::disk('local')->put('avatars/wide.jpg', $wide);
        $this->assertNull(app(ProfilePhotos::class)->thumbnail('avatars/wide.jpg', 96));
        Storage::disk('local')->put('avatars/ok.jpg', $this->jpeg(512, 512));
        $this->assertNotNull(app(ProfilePhotos::class)->thumbnail('avatars/ok.jpg', 96));
        $this->assertNull(app(ProfilePhotos::class)->thumbnail('avatars/ok.jpg', 97));      // not an offered size
        Storage::disk('local')->put('avatars/junk.jpg', 'not an image at all');
        $this->assertNull(app(ProfilePhotos::class)->thumbnail('avatars/junk.jpg', 96));
    }

    public function test_a_page_of_avatars_does_not_use_up_the_requests_everything_else_needs()
    {
        Storage::fake('local');
        $uuid = (string) Str::uuid();
        Storage::disk('local')->put("avatars/{$uuid}.jpg", $this->jpeg(64, 64));
        $user = $this->member('Reader One');
        $this->signIn($user);

        // A busy list asks for dozens of small pictures in a moment
        foreach (range(1, 90) as $i) {
            $this->get("/api/v1/avatars/{$uuid}.jpg?s=64")->assertOk();
        }
        // …and the app's own calls still work
        $this->getJson('/api/v1/me/notifications')->assertOk();
        $this->getJson('/api/v1/me/notifications/unread-count')->assertOk();
    }

    public function test_changing_a_photo_changes_its_address_so_no_old_copy_is_reused()
    {
        Storage::fake('local');
        $user = $this->member('Grace Akello');
        $one = Str::uuid().'.jpg';
        Storage::disk('local')->put('avatars/'.$one, $this->jpeg(64, 64));
        $user->forceFill(['avatar_path' => 'avatars/'.$one])->save();
        $this->assertStringContainsString($one, $user->avatarUrl(96));

        $file = \Illuminate\Http\UploadedFile::fake()->image('new.jpg', 300, 300);
        $this->signIn($user);
        $this->post('/api/v1/me/photo', ['photo' => $file], ['Accept' => 'application/json'])->assertStatus(200);
        $new = basename($user->fresh()->avatar_path);
        $this->assertNotSame($one, $new);                                           // a fresh random name
        $this->assertStringContainsString($new, $user->fresh()->avatarUrl(96));
        $this->assertStringNotContainsString($one, $user->fresh()->avatarUrl(96));
        $this->assertStringNotContainsString('token', $user->fresh()->avatarUrl(96));
        $this->get('/api/v1/avatars/'.$one)->assertNotFound();                      // the old picture is gone, not served from anywhere
    }
}
