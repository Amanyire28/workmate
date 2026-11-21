<?php

namespace Tests\Feature\Api;

use App\Jobs\SendUserNotification;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\NotificationPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Community;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;

/**
 * Phase C: notifications that say who did what to which content, with their real picture; likes grouped
 * by a ladder of names; comments individual (with a preview) until the sixth in a fixed minute, then grouped.
 */
class PersonalNotificationsTest extends ApiTestCase
{
    /** @var array<int, array{endpoint: string, payload: array, options: array}> */
    private $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.webpush.public_key' => 'test-public-key', 'services.webpush.private_key' => 'test-private-key']);
        $this->pushed = [];
        $queued = [];
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->andReturnUsing(function ($subscription, $payload, $options = []) use (&$queued) {
            $queued[] = [$subscription->getEndpoint(), json_decode($payload, true), $options];
        });
        $client->shouldReceive('flush')->andReturnUsing(function () use (&$queued) {
            $batch = $queued;
            $queued = [];

            return (function () use ($batch) {
                foreach ($batch as [$endpoint, $payload, $options]) {
                    $this->pushed[] = ['endpoint' => $endpoint, 'payload' => $payload, 'options' => $options];
                    yield new MessageSentReport(new PsrRequest('POST', $endpoint), new PsrResponse(201));
                }
            })();
        });
        $this->app->instance(WebPush::class, $client);
    }

    /* ---------------- Helpers ---------------- */

    private function member(string $name, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['name' => $name]);
    }

    /** A published post by $author: text, 'video', 'photo' or 'question' */
    private function newPost(User $author, string $kind = 'post'): CommunityPost
    {
        $post = new CommunityPost();
        $post->forceFill(['uuid' => (string) Str::uuid(), 'user_id' => $author->id, 'category' => $kind === 'question' ? 'question' : 'tips', 'body' => 'Some advice'])->save();
        if (in_array($kind, ['video', 'photo'], true)) {
            DB::table('community_post_media')->insert([
                'community_post_id' => $post->id, 'uuid' => (string) Str::uuid(), 'type' => $kind, 'path' => 'x', 'thumb_path' => 'y', 'width' => 360, 'height' => 640,
                'position' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $post;
    }

    private function photoFor(User $user): string
    {
        $file = Str::uuid().'.jpg';
        $user->forceFill(['avatar_path' => 'avatars/'.$file])->save();

        return $file;
    }

    private function like(User $by, CommunityPost $post): void
    {
        app(Community::class)->like($by, $post, true);
    }

    private function comment(User $by, CommunityPost $post, string $body = 'A comment', ?int $parent = null): CommunityComment
    {
        return app(Community::class)->comment($by, $post, $body, $parent);
    }

    /** Several different people each liking, a couple of seconds apart (so "newest first" is certain) */
    private function likers(CommunityPost $post, int $n, int $from = 1): array
    {
        $out = [];
        foreach (range($from, $from + $n - 1) as $i) {
            $out[] = $fan = $this->member(['Alice', 'Brian', 'Carol', 'David', 'Esther', 'Frank', 'Grace', 'Henry'][($i - 1) % 8].' Person'.$i);
            $this->like($fan, $post);
            $this->travel(2)->seconds();
        }

        return $out;
    }

    /** Several different people each commenting, $every seconds apart */
    private function commenters(CommunityPost $post, int $n, int $every = 1, int $from = 1): array
    {
        $out = [];
        foreach (range($from, $from + $n - 1) as $i) {
            $out[] = $fan = $this->member(['Alice', 'Brian', 'Carol', 'David', 'Esther', 'Frank', 'Grace', 'Henry'][($i - 1) % 8].' Person'.$i);
            $this->comment($fan, $post, 'Comment number '.$i);
            $this->travel($every)->seconds();
        }

        return $out;
    }

    private function list(User $user, string $query = ''): array
    {
        $this->signIn($user);

        return $this->getJson('/api/v1/me/notifications'.$query)->assertOk()->json();
    }

    private function device(User $user): PushSubscription
    {
        $e = 'https://push.test/device-'.$user->id;
        $s = new PushSubscription();
        $s->fill(['endpoint' => $e, 'public_key' => 'BPublicKey', 'auth_token' => 'authSecret', 'content_encoding' => 'aes128gcm', 'platform' => 'android']);
        $s->forceFill(['endpoint_hash' => PushSubscription::hashEndpoint($e), 'user_id' => $user->id])->save();

        return $s;
    }

    private function smart(bool $on = true): void
    {
        app(PlatformSettings::class)->set('notifications.smart_push', $on, $this->userWithRoles(['super_admin']), 'test');
    }

    private function row(array $list, string $kind = 'community_comments'): array
    {
        return collect($list['data'])->firstWhere('kind', $kind);
    }

    /* ---------------- Real people: pictures, names, links ---------------- */

    public function test_a_single_actor_shows_their_real_photo_name_and_a_page_to_open()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $fan = $this->member('Mathew Amanyire');
        $file = $this->photoFor($fan);
        $this->newPost($fan);                                              // they have a community page
        $this->like($fan, $post);

        $n = $this->list($author)['data'][0];
        $this->assertSame('Mathew A. liked your video.', $n['body']);
        $this->assertSame(['Mathew A.', ' liked your video.'], [$n['lead'], $n['tail']]);
        $this->assertSame('video', $n['content']);
        $this->assertCount(1, $n['actors']);
        $a = $n['actors'][0];
        $this->assertSame($fan->id, $a['id']);
        $this->assertStringEndsWith('/api/v1/avatars/'.$file.'?s=96', $a['avatar_url']);   // their own photo, the small copy
        $this->assertSame('/community/people/'.$fan->id, $a['profile']);
    }

    public function test_an_actor_without_a_photo_has_none_and_the_page_shows_initials()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $this->like($this->member('Brian Kato'), $post);

        $a = $this->list($author)['data'][0]['actors'][0];
        $this->assertNull($a['avatar_url']);                               // never a stock or invented picture
        $this->assertSame('Brian K.', $a['name']);
        $this->assertNull($a['profile']);                                  // and no page to open (they have not posted)
    }

    public function test_up_to_three_distinct_actors_newest_first_and_a_person_is_never_shown_twice()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $fans = $this->likers($post, 5);
        $this->like($fans[0], $post);                                      // the first fan taps again: still one

        $n = $this->list($author)['data'][0];
        $this->assertSame(5, $n['count']);
        $this->assertSame([$fans[4]->id, $fans[3]->id, $fans[2]->id], array_column($n['actors'], 'id'));   // three, newest first
        $this->assertSame(3, count(array_unique(array_column($n['actors'], 'id'))));
        $this->assertTrue($n['activity']);                                 // a group can be opened

        // A comment group never shows the same person twice either, however often they wrote
        $other = $this->newPost($author, 'video');
        $a = $this->member('Alice Same');
        foreach (range(1, 6) as $i) {
            $this->comment($a, $other, 'Again '.$i);
        }
        $c = collect($this->list($author)['data'])->firstWhere('comments', 6);
        $this->assertSame([$a->id], array_column($c['actors'], 'id'));
    }

    public function test_the_like_wording_follows_the_ladder_with_the_real_count_and_the_right_content()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $fans = $this->likers($post, 6);
        $names = ['Alice P.', 'Brian P.', 'Carol P.', 'David P.', 'Esther P.', 'Frank P.'];
        $expected = [
            'Alice P. liked your video.',
            'Brian P. and Alice P. liked your video.',
            'Carol P., Brian P. and Alice P. liked your video.',
            'David P., Carol P. and 2 others liked your video.',
            'Esther P., David P. and 3 others liked your video.',
            'Frank P., Esther P. and 4 others liked your video.',
        ];
        // the list after each like (rebuild: fresh author each time keeps one row)
        foreach (range(1, 6) as $n) {
            $own = $this->member('Owner '.$n);
            $p = $this->newPost($own, 'video');
            $this->likers($p, $n, 1);
            $this->assertSame($expected[$n - 1], $this->list($own)['data'][0]['body'], "with $n likes");
        }
        $this->assertSame($expected[5], $this->list($author)['data'][0]['body']);
    }

    public function test_the_content_is_called_what_it_is()
    {
        $author = $this->member('Grace Akello');
        foreach ([['video', 'video'], ['photo', 'photo'], ['question', 'question'], ['post', 'post']] as [$kind, $word]) {
            $post = $this->newPost($author, $kind);
            $this->like($this->member('Fan '.$kind), $post);
            $this->travel(2)->seconds();
        }
        $rows = collect($this->list($author)['data'])->pluck('body', 'content')->all();
        $this->assertSame('Fan v. liked your video.', str_replace('Fan video', 'Fan v.', $rows['video'] ?? ''));
        $this->assertStringContainsString('liked your photo.', $rows['photo']);
        $this->assertStringContainsString('liked your question.', $rows['question']);
        $this->assertStringContainsString('liked your post.', $rows['post']);
        $this->assertSame(['Your video was liked', 'Your photo was liked', 'Your question was liked', 'Your post was liked'], collect($this->list($author)['data'])->reverse()->pluck('title')->values()->all());
    }

    public function test_unlike_and_relike_keep_the_count_honest_and_other_content_or_people_never_mix()
    {
        $author = $this->member('Grace Akello');
        $other = $this->member('Brian Other');
        $a = $this->newPost($author, 'video');
        $b = $this->newPost($author, 'video');
        $c = $this->newPost($other, 'video');
        [$f1, $f2] = $this->likers($a, 2);
        $this->like($f1, $b);
        $this->like($f1, $c);

        $this->assertSame([1, 2], collect($this->list($author)['data'])->pluck('count')->sort()->values()->all());   // one group per post
        $this->assertSame(1, $this->list($other)['meta']['unread']);                                                // someone else's own

        app(Community::class)->like($f2, $a, false);
        $this->assertSame('Alice P. liked your video.', collect($this->list($author)['data'])->firstWhere('count', 1)['body'] ?? '');
        $this->travel(5)->seconds();
        app(Community::class)->like($f2, $a, true);
        $this->assertSame(2, collect($this->list($author)['data'])->max('count'));
    }

    public function test_a_deleted_or_suspended_person_is_never_shown_and_a_changed_photo_shows_the_new_one()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        [$a, $b, $c] = $this->likers($post, 3);
        $first = $this->photoFor($a);

        $this->assertStringContainsString($first, json_encode($this->list($author)['data'][0]['actors']));
        $second = $this->photoFor($a);                                         // they change their picture
        $json = json_encode($this->list($author)['data'][0]['actors']);
        $this->assertStringContainsString($second, $json);
        $this->assertStringNotContainsString($first, $json);

        $b->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $c->delete();                                                           // deleted
        $n = $this->list($author)['data'][0];
        $this->assertSame(1, $n['count']);
        $this->assertSame([$a->id], array_column($n['actors'], 'id'));
        $this->assertStringNotContainsString('Carol', json_encode($n));
        $this->assertStringNotContainsString('Brian', json_encode($n));
    }

    public function test_the_actors_are_read_with_a_fixed_number_of_queries()
    {
        $author = $this->member('Grace Akello');
        $make = function (int $n) use ($author) {
            foreach (range(1, $n) as $i) {
                $p = $this->newPost($author, 'photo');
                $this->likers($p, 3, 1);
                $this->comment($this->member('Commenter '.$i), $p, 'Hello');
            }
        };
        $count = function () use ($author) {
            $this->signIn($author);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/me/notifications')->assertOk();   // (the page's own queries; later push jobs in the test app are not part of it)

            return count(array_filter(DB::getQueryLog(), function ($q) {
                return strpos($q['query'], 'push_subscriptions') === false;
            }));
        };
        $make(2);
        $few = $count();
        $make(6);
        $this->assertSame($few, $count());          // more notifications, the same number of queries
        $this->assertLessThanOrEqual(16, $few);
    }

    public function test_profile_pages_open_only_for_people_who_have_one()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $quiet = $this->member('Quiet Person');                               // no post, not a provider
        $writer = $this->member('Writer Person');
        $this->newPost($writer);
        $owner = $this->member('Okello Mukasa');
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));
        foreach ([$quiet, $writer, $owner->fresh()] as $fan) {
            $this->like($fan, $post);
            $this->travel(2)->seconds();
        }

        $actors = collect($this->list($author)['data'][0]['actors'])->keyBy('id');
        $this->assertNull($actors[$quiet->id]['profile']);
        $this->assertSame('/community/people/'.$writer->id, $actors[$writer->id]['profile']);
        $this->assertSame('/provider/'.$owner->providerProfile()->first()->id, $actors[$owner->id]['profile']);   // a listed business opens its profile
        $this->assertSame('Okello Plumbing', $actors[$owner->id]['name']);
    }

    public function test_profile_photos_come_in_small_sizes_without_leaking_anything_else()
    {
        $file = (string) Str::uuid().'.jpg';
        $big = imagecreatetruecolor(512, 512);
        ob_start();
        imagejpeg($big, null, 85);
        Storage::disk('local')->put('avatars/'.$file, ob_get_clean());

        $full = $this->get("/api/v1/avatars/{$file}")->assertOk();
        $this->assertSame([512, 512], array_slice(getimagesizefromstring($this->bytes($full)), 0, 2));

        $small = $this->get("/api/v1/avatars/{$file}?s=96")->assertOk();
        $this->assertSame([96, 96], array_slice(getimagesizefromstring($this->bytes($small)), 0, 2));
        $this->assertSame('image/jpeg', $small->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $small->headers->get('Cache-Control'));
        $this->assertLessThan(strlen($this->bytes($full)), strlen($this->bytes($small)));

        // Any other size is not made: the photo is served as it is
        $this->assertSame([512, 512], array_slice(getimagesizefromstring($this->bytes($this->get("/api/v1/avatars/{$file}?s=7"))), 0, 2));
        $this->assertSame([512, 512], array_slice(getimagesizefromstring($this->bytes($this->get("/api/v1/avatars/{$file}?s=abc"))), 0, 2));

        // Missing, malformed or outside the folder: not found
        $this->get('/api/v1/avatars/'.Str::uuid().'.jpg?s=96')->assertNotFound();
        $this->get('/api/v1/avatars/not-a-photo.jpg')->assertNotFound();
        $this->get('/api/v1/avatars/..%2F..%2F.env')->assertNotFound();

        // A damaged file falls back to the original instead of failing
        $bad = (string) Str::uuid().'.jpg';
        Storage::disk('local')->put('avatars/'.$bad, 'not an image');
        $this->get("/api/v1/avatars/{$bad}?s=96")->assertOk();
    }

    /* ---------------- Comments: individual, then grouped at the sixth in a fixed minute ---------------- */

    public function test_the_first_five_comments_are_individual_with_who_what_and_a_preview()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $fans = $this->commenters($post, 5);

        $list = $this->list($author);
        $this->assertCount(5, $list['data']);
        $this->assertSame(5, $list['meta']['unread']);
        $newest = $list['data'][0];
        $this->assertSame('Esther P. commented on your video.', $newest['body']);
        $this->assertSame('"'.'Comment number 5'.'"' === '' ? '' : 'Comment number 5', $newest['preview']);
        $this->assertSame('video', $newest['content']);
        $this->assertFalse($newest['grouped']);
        $this->assertSame([$fans[4]->id], array_column($newest['actors'], 'id'));
        $last = CommunityComment::where('user_id', $fans[4]->id)->first();
        $this->assertSame('/community/post/'.$post->uuid.'#comment-'.$last->id, $newest['url']);   // straight to that comment
        $this->assertSame('New comment', $newest['title']);
    }

    public function test_the_sixth_comment_in_the_minute_makes_one_group_and_later_ones_update_it()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $this->commenters($post, 6);                                           // one second apart: all in one minute

        $list = $this->list($author);
        $this->assertCount(1, $list['data']);                                   // one grouped notification
        $this->assertSame(1, $list['meta']['unread']);
        $g = $list['data'][0];
        $this->assertTrue($g['grouped']);
        $this->assertSame([6, 6], [$g['count'], $g['comments']]);               // six people, six comments
        $this->assertSame('Frank P., Esther P. and 4 others commented on your video.', $g['body']);
        $this->assertSame('6 new comments', $g['detail']);
        $this->assertSame('/community/post/'.$post->uuid.'#comments', $g['url']);
        $this->assertSame(3, count($g['actors']));

        $this->commenters($post, 1, 1, 7);                                      // the seventh
        $g = $this->list($author)['data'][0];
        $this->assertSame(1, count($this->list($author)['data']));
        $this->assertSame([7, 7], [$g['count'], $g['comments']]);
        $this->assertSame('7 new comments', $g['detail']);
        $this->assertSame(7, UserNotification::where('user_id', $author->id)->where('subject_type', 'comment')->count());   // every comment keeps its own record
        $this->assertSame(1, UserNotification::where('user_id', $author->id)->where('subject_type', 'burst')->count());
    }

    public function test_one_person_commenting_many_times_is_counted_once_as_a_person()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $a = $this->member('Alice Busy');
        $b = $this->member('Brian Busy');
        foreach ([$a, $a, $b, $a, $b, $a] as $by) {
            $this->comment($by, $post, 'Busy');
            $this->travel(1)->seconds();
        }

        $g = $this->list($author)['data'][0];
        $this->assertSame([2, 6], [$g['count'], $g['comments']]);                // two people, six comments
        $this->assertSame('Alice B. and Brian B. posted 6 comments on your video.', $g['body']);
        $this->assertSame(2, count($g['actors']));
        $this->assertSame([$a->id, $b->id], array_column($g['actors'], 'id'));   // most recent commenter first, each once

        // One person six times: "posted 6 comments", not "and 5 others"
        $solo = $this->member('Grace Two');
        $post2 = $this->newPost($solo, 'post');
        foreach (range(1, 6) as $i) {
            $this->comment($a, $post2, 'Again');
        }
        $this->assertSame('Alice B. posted 6 comments on your post.', $this->list($solo)['data'][0]['body']);
    }

    public function test_the_window_is_fixed_on_the_first_comment_and_never_slides()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC'));
        $fans = [];
        foreach ([0, 20, 40, 59, 61, 62] as $i => $second) {                     // the fourth is the last in the first minute
            $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC')->addSeconds($second));
            $fans[] = $f = $this->member('Fan Number'.$i);
            $this->comment($f, $post, 'At '.$second);
        }

        // 0, 20, 40, 59 share the first window (four individual); 61 and 62 begin a new one: individual again
        $list = $this->list($author);
        $this->assertCount(6, $list['data']);
        $this->assertSame(0, UserNotification::where('subject_type', 'burst')->count());
        $this->assertSame(6, $list['meta']['unread']);
    }

    public function test_the_sixth_comment_groups_at_59_seconds_but_not_at_exactly_60()
    {
        $author = $this->member('Grace Akello');
        $at59 = $this->newPost($author, 'video');
        $at60 = $this->newPost($author, 'video');
        foreach ([[$at59, 59], [$at60, 60]] as [$post, $sixth]) {
            foreach ([0, 10, 20, 30, 40, $sixth] as $i => $second) {
                $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC')->addSeconds($second));
                $this->comment($this->member('Window'.$post->id.'Fan'.$i), $post, 'At '.$second);
            }
        }

        $rows = collect($this->list($author)['data']);
        $this->assertSame(1, $rows->where('grouped', true)->count());                         // the 59 s post: one group…
        $this->assertSame(6, $rows->firstWhere('grouped', true)['comments']);                  // …of six
        $this->assertSame(6, $rows->where('grouped', false)->count());                         // the 60 s post: five, and the sixth alone in a new window
        $this->assertSame(1, UserNotification::where('subject_type', 'burst')->count());
        $this->assertSame(12, UserNotification::where('user_id', $author->id)->where('subject_type', 'comment')->count());   // every comment keeps its own record
    }

    public function test_six_in_the_second_window_group_on_their_own_and_the_first_stays_individual()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC'));
        foreach ([0, 10] as $i => $s) {
            $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC')->addSeconds($s));
            $this->comment($this->member('Early'.$i), $post, 'early');
        }
        foreach (range(0, 5) as $i) {
            $this->travelTo(Carbon::parse('2026-11-24 09:02:00', 'UTC')->addSeconds($i));
            $this->comment($this->member('Late'.$i), $post, 'late');
        }

        $data = $this->list($author)['data'];
        $this->assertCount(3, $data);                                            // the two early ones, and one group of the later six
        $this->assertSame(1, collect($data)->where('grouped', true)->count());
        $this->assertSame(6, collect($data)->firstWhere('grouped', true)['comments']);
    }

    public function test_comments_on_other_posts_or_for_other_people_are_never_grouped_together()
    {
        $author = $this->member('Grace Akello');
        $one = $this->newPost($author, 'video');
        $two = $this->newPost($author, 'video');
        $other = $this->member('Brian Other');
        $three = $this->newPost($other, 'video');
        foreach (range(1, 3) as $i) {
            $f = $this->member('Fan Number'.$i);
            $this->comment($f, $one, 'x');
            $this->comment($f, $two, 'y');
            $this->comment($f, $three, 'z');
        }

        $this->assertCount(6, $this->list($author)['data']);                     // 3 + 3, no group
        $this->assertCount(3, $this->list($other)['data']);
        $this->assertSame(0, UserNotification::where('subject_type', 'burst')->count());

        // Six on one post group only that post
        $this->commenters($one, 3, 1, 4);
        $data = $this->list($author)['data'];
        $this->assertSame(1, collect($data)->where('grouped', true)->count());
        $this->assertCount(4, $data);                                            // the group, and post two's three
    }

    public function test_notifications_already_read_are_never_merged_into_a_new_group()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $this->commenters($post, 3);
        app(\App\Services\UserNotifications::class)->markRead($author);          // the first three are read
        $this->commenters($post, 3, 1, 4);                                       // three more in the same minute: the sixth arrives

        $list = $this->list($author);
        $data = collect($list['data']);
        $this->assertSame(1, $data->where('grouped', true)->count());
        $this->assertSame(3, $data->firstWhere('grouped', true)['comments']);    // only the unread ones are in the group
        $this->assertSame(3, $data->where('grouped', false)->where('read', true)->count());   // the three read ones are untouched
        $this->assertSame(1, $list['meta']['unread']);
        $this->assertSame(6, CommunityComment::where('community_post_id', $post->id)->count());
    }

    public function test_a_group_that_was_read_is_finished_and_later_comments_start_individual_entries()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $this->commenters($post, 6);
        $burst = UserNotification::where('subject_type', 'burst')->first();
        $this->signIn($author);
        $this->postJson("/api/v1/me/notifications/{$burst->id}/read")->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertSame(0, UserNotification::where('user_id', $author->id)->whereNull('read_at')->count());   // reading it read what was inside it

        $this->commenters($post, 1, 1, 7);
        $list = $this->list($author);
        $this->assertSame(1, $list['meta']['unread']);
        $new = collect($list['data'])->firstWhere('read', false);
        $this->assertFalse($new['grouped']);                                      // not pushed into the finished group
        $this->assertSame(6, collect($list['data'])->firstWhere('grouped', true)['comments']);   // which still says six
    }

    public function test_deleted_and_moderated_comments_vanish_from_the_list_and_leak_nothing()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $mean = $this->member('Mean Person');
        $c1 = $this->comment($mean, $post, 'A very private remark about money');
        $c2 = $this->comment($this->member('Nice Person'), $post, 'Lovely work');
        $this->assertStringContainsString('private remark', json_encode($this->list($author)['data']));

        app(Community::class)->deleteComment($mean, $c1);                          // its author takes it back
        $json = json_encode($this->list($author));
        $this->assertStringNotContainsString('private remark', $json);
        $this->assertCount(1, $this->list($author)['data']);
        $this->assertSame(1, $this->list($author)['meta']['unread']);

        $admin = $this->userWithRoles(['super_admin']);
        app(Community::class)->removeComment($c2, $admin, 'Rules');                // a moderator removes the other
        $this->assertSame([], $this->list($author)['data']);
        $this->assertSame(0, $this->list($author)['meta']['unread']);
        $this->assertStringNotContainsString('Lovely work', json_encode($this->list($author)));
        $this->assertSame('A very private remark about money', CommunityComment::withTrashed()->find($c1->id)->body);   // the record itself is untouched

        // In a group: counts follow what is left, and an emptied group disappears
        $post2 = $this->newPost($author, 'video');
        $made = [];
        foreach (range(1, 6) as $i) {
            $made[] = $this->comment($this->member('Fan Number'.$i), $post2, 'Secret '.$i);
            $this->travel(1)->seconds();
        }
        $g = $this->list($author)['data'][0];
        $this->assertSame(6, $g['comments']);
        app(Community::class)->deleteComment(User::find($made[0]->user_id), $made[0]);
        app(Community::class)->removeComment($made[1], $admin, 'Rules');
        $g = $this->list($author)['data'][0];
        $this->assertSame([4, 4], [$g['count'], $g['comments']]);
        $this->assertSame('4 new comments', $g['detail']);
        $this->assertStringNotContainsString('Secret 1', json_encode($g));
        foreach (array_slice($made, 2) as $m) {
            app(Community::class)->removeComment($m, $admin, 'Rules');
        }
        $this->assertSame([], $this->list($author)['data']);

        // A removed post takes its notifications with it
        $post3 = $this->newPost($author, 'video');
        $this->like($this->member('Fan Number9'), $post3);
        $this->assertCount(1, $this->list($author)['data']);
        app(Community::class)->removePost($post3, $admin, 'Rules');
        $this->assertSame([], array_values(array_filter($this->list($author)['data'], function ($r) {
            return $r['kind'] === 'community_likes';
        })));
    }

    public function test_a_comment_from_a_suspended_account_is_not_shown()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $bad = $this->member('Bad Actor');
        $this->comment($bad, $post, 'hello');
        $this->assertCount(1, $this->list($author)['data']);
        $bad->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->assertSame([], $this->list($author)['data']);
    }

    public function test_replies_are_individual_notifications_with_their_own_person_and_preview()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, 'video');
        $asker = $this->member('Sarah Asker');
        $top = $this->comment($asker, $post, 'Is this available?');
        $this->travel(2)->seconds();
        foreach (range(1, 7) as $i) {
            $this->comment($author, $post, 'Yes, number '.$i.' is free', $top->id);     // the author replies seven times
            $this->travel(1)->seconds();
        }

        $rows = collect($this->list($asker)['data']);
        $this->assertCount(7, $rows);                                              // never grouped
        $this->assertSame(0, $rows->where('grouped', true)->count());
        $newest = $rows->first();
        $this->assertSame('Grace A. replied to your comment.', $newest['body']);
        $this->assertSame('Yes, number 7 is free', $newest['preview']);
        $this->assertSame('New reply', $newest['title']);
    }

    public function test_previews_are_plain_short_text_and_the_comment_itself_is_unchanged()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $fan = $this->member('Brian Typist');
        $body = '<script>alert(1)</script> <b>bold</b>   words'."\n\n".'on   lines & '.str_repeat('long ', 80);
        $c = $this->comment($fan, $post, $body);

        $row = $this->list($author)['data'][0];
        $this->assertStringNotContainsString('<', $row['preview']);
        $this->assertStringStartsWith('alert(1) bold words on lines &', $row['preview']);
        $this->assertLessThanOrEqual(141, mb_strlen($row['preview']));
        $this->assertStringEndsWith('…', $row['preview']);
        $this->assertStringContainsString('<script>', CommunityComment::find($c->id)->body);   // what was written is what is kept

        // A short one is shown whole
        $this->comment($this->member('Carol Short'), $post, 'Great work!');
        $this->assertSame('Great work!', $this->list($author)['data'][0]['preview']);
    }

    public function test_the_who_sheet_shows_people_or_comments_and_only_to_the_person_it_belongs_to()
    {
        $author = $this->member('Grace Akello');
        $other = $this->member('Brian Other');
        $video = $this->newPost($author, 'video');
        $this->likers($video, 4);
        $busy = $this->newPost($author, 'photo');
        $this->commenters($busy, 6);
        $this->comment($this->member('Lone Commenter'), $this->newPost($author), 'solo');

        $rows = collect($this->list($author)['data']);
        $likes = $rows->firstWhere('kind', 'community_likes');
        $burst = $rows->firstWhere('grouped', true);
        $this->assertTrue($likes['activity']);

        $this->signIn($author);
        $who = $this->getJson("/api/v1/me/notifications/{$likes['id']}/activity")->assertOk()->json('data');
        $this->assertCount(4, $who['actors']);
        $this->assertSame([], $who['comments']);
        $this->assertSame('David P.', $who['actors'][0]['name']);                    // newest first

        $comments = $this->getJson("/api/v1/me/notifications/{$burst['id']}/activity")->assertOk()->json('data');
        $this->assertCount(6, $comments['comments']);
        $this->assertSame('Comment number 6', $comments['comments'][0]['preview']);
        $this->assertStringContainsString('#comment-', $comments['comments'][0]['url']);

        // Not anyone else's, not when it is gone, not without signing in
        $this->signIn($other);
        $this->getJson("/api/v1/me/notifications/{$likes['id']}/activity")->assertNotFound();
        $this->getJson('/api/v1/me/notifications/999999/activity')->assertNotFound();
        $this->signIn($author);
        app(Community::class)->removePost($video, $this->userWithRoles(['super_admin']), 'Rules');
        $this->getJson("/api/v1/me/notifications/{$likes['id']}/activity")->assertNotFound();
    }

    public function test_the_who_sheet_requires_signing_in()
    {
        $this->getJson('/api/v1/me/notifications/1/activity')->assertUnauthorized();
    }

    public function test_choices_still_apply_to_comment_notifications()
    {
        $author = $this->member('Grace Akello', ['notification_preferences' => ['community_comments' => false]]);
        $post = $this->newPost($author);
        $this->comment($this->member('Brian Talker'), $post, 'Hi');
        $this->assertSame([], $this->list($author)['data']);
        $this->assertSame(0, UserNotification::where('user_id', $author->id)->count());
    }

    /* ---------------- The phone: immediate, private, personal, replaced not stacked ---------------- */

    public function test_comment_pushes_are_immediate_private_and_replace_each_other_silently_within_a_window()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author, 'video');
        $photo = $this->photoFor($fan1 = $this->member('Alice Person'));
        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC'));

        $this->comment($fan1, $post, 'My secret quote is 5 million shillings');
        $this->assertCount(0, $this->pushed);                                       // saved with the comment, tried after it commits
        $this->flushNow();
        $this->assertCount(1, $this->pushed);                                       // at once, nothing waited
        $p = $this->pushed[0]['payload'];
        $this->assertSame('Alice P. commented on your video.', $p['body']);
        $this->assertStringNotContainsString('secret', json_encode($p));            // never what was said
        $this->assertStringNotContainsString('million', json_encode($p));
        $this->assertSame('/api/v1/avatars/'.$photo.'?s=128', $p['icon']);          // their own photo, on this site, a small copy
        $this->assertArrayNotHasKey('silent', $p);                                   // the first alerts
        $tag = $p['tag'];

        $this->travelTo(Carbon::parse('2026-11-24 09:00:05', 'UTC'));
        $this->comment($this->member('Brian Person'), $post, 'Second');
        $this->flushNow();
        $this->assertCount(2, $this->pushed);
        $this->assertSame($tag, $this->pushed[1]['payload']['tag']);                // the same phone notification, replaced
        $this->assertTrue($this->pushed[1]['payload']['silent']);                   // without another sound
        $this->assertArrayNotHasKey('icon', $this->pushed[1]['payload']);           // (no photo of their own: the app icon is used)

        foreach (range(3, 6) as $i) {                                               // the sixth makes the group
            $this->travel(1)->seconds();
            $this->comment($this->member('Fan Number'.$i), $post, 'More '.$i);
            $this->flushNow();
        }
        $last = end($this->pushed)['payload'];
        $this->assertCount(6, $this->pushed);                                       // one push per comment: grouping adds none
        $this->assertSame($tag, $last['tag']);
        $this->assertTrue($last['silent']);
        $this->assertStringContainsString('and 4 others commented on your video', $last['body']);
        $this->assertStringNotContainsString('More', json_encode($last));
        $this->assertSame(6, NotificationPush::where('type', 'direct')->count());   // no extra rows for the group

        // A comment in the next window is a new phone notification, with a sound
        $this->travelTo(Carbon::parse('2026-11-24 09:05:00', 'UTC'));
        $this->comment($this->member('Late Person'), $post, 'Later');
        $this->flushNow();
        $this->assertCount(7, $this->pushed);
        $this->assertNotSame($tag, $this->pushed[6]['payload']['tag']);
        $this->assertArrayNotHasKey('silent', $this->pushed[6]['payload']);
    }

    public function test_like_pushes_carry_the_latest_persons_photo_and_stay_replaced_and_silent()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author, 'video');
        $a = $this->member('Alice Person');
        $b = $this->member('Brian Person');
        $fileB = $this->photoFor($b);

        $this->like($a, $post);
        $this->flushNow();
        $this->travel(2)->seconds();
        $this->like($b, $post);
        $this->flushNow();

        $this->assertCount(2, $this->pushed);
        $this->assertSame('Alice P. liked your video.', $this->pushed[0]['payload']['body']);
        $this->assertArrayNotHasKey('icon', $this->pushed[0]['payload']);                        // Alice has no photo: the app icon
        $this->assertSame('Brian P. and Alice P. liked your video.', $this->pushed[1]['payload']['body']);
        $this->assertSame('/api/v1/avatars/'.$fileB.'?s=128', $this->pushed[1]['payload']['icon']);   // the latest person's own photo
        $this->assertSame($this->pushed[0]['payload']['tag'], $this->pushed[1]['payload']['tag']);
        $this->assertTrue($this->pushed[1]['payload']['silent']);
    }

    public function test_with_smart_push_off_comments_still_go_out_at_once_without_their_words()
    {
        Bus::fake();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author, 'video');
        $this->comment($this->member('Alice Person'), $post, 'Private words');

        $this->assertSame(0, NotificationPush::count());                              // the earlier immediate path
        Bus::assertDispatchedAfterResponse(SendUserNotification::class, 1);
        $this->assertCount(1, $this->list($author)['data']);
    }

    public function test_a_mention_names_the_person_and_carries_their_photo_for_the_phone()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $fan = $this->member('Brian Talker');
        $file = $this->photoFor($fan);
        $this->signIn($fan);
        $this->postJson('/api/v1/community/posts', ['category' => 'tips', 'body' => 'Thanks @[u:'.$author->id.']'])->assertCreated();
        $this->flushNow();

        $this->assertSame('/api/v1/avatars/'.$file.'?s=128', $this->pushed[0]['payload']['icon']);
        $row = $this->row($this->list($author), 'community_mentions');
        $this->assertSame('Brian T.', $row['lead']);
        $this->assertSame(' mentioned you in a post.', $row['tail']);
        $this->assertStringContainsString($file, $row['actors'][0]['avatar_url']);
    }

    private function bytes($response): string
    {
        $base = $response->baseResponse;

        return $base instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? (string) file_get_contents($base->getFile()->getPathname()) : (string) $response->getContent();
    }

    public function test_comment_pushes_respect_the_phone_choice_and_quiet_hours()
    {
        $this->smart();
        $off = $this->member('Grace Akello', ['notification_preferences' => ['push:community_comments' => false]]);
        $this->device($off);
        $this->comment($this->member('Alice Person'), $this->newPost($off, 'video'), 'Hello');
        $this->flushNow();
        $this->assertCount(0, $this->pushed);                                         // the phone is not told…
        $this->assertCount(1, $this->list($off)['data']);                              // …the list still is

        $quiet = $this->member('Quiet Person', ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->device($quiet);
        $this->travelTo(Carbon::parse('2026-11-24 20:30:00', 'UTC'));                  // 23:30 in Kampala
        $this->comment($this->member('Brian Person'), $this->newPost($quiet, 'video'), 'Hello');
        $this->flushNow();
        $this->assertCount(1, $this->pushed);                                         // delivered at once…
        $this->assertTrue($this->pushed[0]['payload']['silent']);                      // …but quietly
        $this->assertStringNotContainsString('Hello', json_encode($this->pushed[0]['payload']));
    }

    public function test_a_failed_push_is_retried_and_a_comment_group_never_sends_two_for_one_comment()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author, 'video');
        $this->travelTo(Carbon::parse('2026-11-24 09:00:00', 'UTC'));
        foreach (range(1, 7) as $i) {
            $this->comment($this->member('Fan Number'.$i), $post, 'x'.$i);
        }
        $this->assertSame(7, NotificationPush::where('type', 'direct')->count());       // one saved push per comment, grouping or not
        $this->flushNow();
        $this->flushNow();                                                              // the safety net running again changes nothing
        $this->assertCount(7, $this->pushed);
        $this->assertSame(1, count(array_unique(array_column(array_column($this->pushed, 'payload'), 'tag'))));
    }

    /** Tries what the after-response step would (in these tests nothing runs it after a service call) */
    private function flushNow(): void
    {
        app(\App\Services\PushDelivery::class)->flushDue(100);
    }
}
