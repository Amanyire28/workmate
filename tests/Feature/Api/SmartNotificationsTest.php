<?php

namespace Tests\Feature\Api;

use App\Jobs\FlushNotificationPushes;
use App\Jobs\SendUserNotification;
use App\Models\CommunityPost;
use App\Models\NotificationPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Community;
use App\Services\PlatformSettings;
use App\Services\PushDelivery;
use App\Services\UserNotifications;
use App\Support\QuietHours;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;

/**
 * Step 5: likes and follows grouped in the bell, and the smart push rules
 * (windows, early push, cooldown, daily limit, quiet hours, retries, claims).
 */
class SmartNotificationsTest extends ApiTestCase
{
    /** @var array<int, array{endpoint: string, payload: array, options: array}> everything the push client was asked to send */
    private $pushed = [];

    /** @var string[] endpoints the fake push service refuses (HTTP 500) */
    private $failing = [];

    /** @var string[] endpoints the fake push service says are gone (HTTP 410) */
    private $gone = [];

    /** @var string[] endpoints the fake push service refuses for good (HTTP 403) */
    private $rejected = [];

    /** @var bool the push library itself throws (not an HTTP answer) */
    private $explode = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.webpush.public_key' => 'test-public-key', 'services.webpush.private_key' => 'test-private-key']);
        $this->pushed = $this->failing = $this->gone = $this->rejected = [];
        $this->explode = false;
        $queued = [];
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->andReturnUsing(function ($subscription, $payload, $options = []) use (&$queued) {
            $queued[] = [$subscription->getEndpoint(), json_decode($payload, true), $options];
        });
        $client->shouldReceive('flush')->andReturnUsing(function () use (&$queued) {
            $batch = $queued;
            $queued = [];
            if ($this->explode) {
                throw new \RuntimeException('the push library failed');
            }

            return (function () use ($batch) {
                foreach ($batch as [$endpoint, $payload, $options]) {
                    $this->pushed[] = ['endpoint' => $endpoint, 'payload' => $payload, 'options' => $options];
                    $request = new PsrRequest('POST', $endpoint);
                    if (in_array($endpoint, $this->gone, true)) {
                        yield new MessageSentReport($request, new PsrResponse(410), false, 'Gone');
                    } elseif (in_array($endpoint, $this->rejected, true)) {
                        yield new MessageSentReport($request, new PsrResponse(403), false, 'Forbidden');
                    } elseif (in_array($endpoint, $this->failing, true)) {
                        yield new MessageSentReport($request, new PsrResponse(500), false, 'Server error');
                    } else {
                        yield new MessageSentReport($request, new PsrResponse(201));
                    }
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

    /** A published post (or a video post) by $author */
    private function newPost(User $author, bool $video = false): CommunityPost
    {
        $post = new CommunityPost();
        $post->forceFill(['uuid' => (string) Str::uuid(), 'user_id' => $author->id, 'category' => 'tips', 'body' => 'Some advice'])->save();
        if ($video) {
            DB::table('community_post_media')->insert([
                'community_post_id' => $post->id, 'uuid' => (string) Str::uuid(), 'type' => 'video', 'path' => 'x', 'thumb_path' => 'y', 'width' => 360, 'height' => 640, 'position' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $post;
    }

    private function like(User $by, CommunityPost $post)
    {
        $this->signIn($by);

        return $this->postJson("/api/v1/community/posts/{$post->uuid}/like")->assertOk();
    }

    private function unlike(User $by, CommunityPost $post)
    {
        $this->signIn($by);

        return $this->deleteJson("/api/v1/community/posts/{$post->uuid}/like")->assertOk();
    }

    private function follow(User $by, User $person)
    {
        $this->signIn($by);

        return $this->postJson("/api/v1/community/people/{$person->id}/follow");
    }

    private function smart(bool $on = true): void
    {
        app(PlatformSettings::class)->set('notifications.smart_push', $on, $this->userWithRoles(['super_admin']), 'test');
    }

    private function device(User $user, ?string $endpoint = null): PushSubscription
    {
        $endpoint = $endpoint ?: 'https://push.test/device-'.$user->id;
        $s = new PushSubscription();
        $s->fill(['endpoint' => $endpoint, 'public_key' => 'BPublicKey', 'auth_token' => 'authSecret', 'content_encoding' => 'aes128gcm', 'platform' => 'android']);
        $s->forceFill(['endpoint_hash' => PushSubscription::hashEndpoint($endpoint), 'user_id' => $user->id])->save();

        return $s;
    }

    /** Many people who like the same post, each a few seconds apart */
    private function likers(CommunityPost $post, int $n, int $from = 1): array
    {
        $out = [];
        for ($i = $from; $i < $from + $n; $i++) {
            $liker = $this->member(['Alice', 'Brian', 'Carol', 'David', 'Esther', 'Frank'][($i - 1) % 6].' Person'.$i);
            $this->like($liker, $post);
            $this->travel(2)->seconds();
            $out[] = $liker;
        }

        return $out;
    }

    private function list(User $user, string $query = ''): array
    {
        $this->signIn($user);

        return $this->getJson('/api/v1/me/notifications'.$query)->assertOk()->json();
    }

    /**
     * Queries the list itself made. (The test app replays earlier requests' after-response
     * push jobs on every request, which a real request never does, so those are left out.)
     */
    private function listQueries(): int
    {
        return count(array_filter(DB::getQueryLog(), function ($q) {
            return strpos($q['query'], 'push_subscriptions') === false;
        }));
    }

    private function flush(): array
    {
        return app(PushDelivery::class)->flushDue(100);
    }

    private function pushTo(string $endpoint): array
    {
        return array_values(array_filter($this->pushed, function ($p) use ($endpoint) {
            return $p['endpoint'] === $endpoint;
        }));
    }

    /* ---------------- Grouped likes ---------------- */

    public function test_likes_on_one_post_are_one_notification_with_real_people_and_a_live_count()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $people = $this->likers($post, 3);

        $res = $this->list($author);
        $this->assertCount(1, $res['data']);
        $n = $res['data'][0];
        $this->assertTrue($n['grouped']);
        $this->assertSame(3, $n['count']);
        $this->assertSame(['Carol P.', 'Brian P.', 'Alice P.'], array_column($n['actors'], 'name'));
        $this->assertSame([$people[2]->id, $people[1]->id, $people[0]->id], array_column($n['actors'], 'id'));
        $this->assertSame('Carol P., Brian P. and 1 other liked your post.', $n['body']);
        $this->assertSame('Your post was liked', $n['title']);
        $this->assertSame('/community/post/'.$post->uuid, $n['url']);
        $this->assertSame(1, $res['meta']['unread']);
        $this->assertSame(1, UserNotification::where('user_id', $author->id)->count());
    }

    public function test_a_video_says_video_and_two_people_are_named_together()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author, true);
        $this->likers($post, 2);

        $n = $this->list($author)['data'][0];
        $this->assertSame('Your video was liked', $n['title']);
        $this->assertSame('Brian P. and Alice P. liked your video.', $n['body']);
    }

    public function test_the_count_is_distinct_current_people_so_unlike_and_relike_stay_honest()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        [$a, $b] = $this->likers($post, 2);

        $this->like($a, $post);                       // tapping twice changes nothing
        $this->assertSame(2, $this->list($author)['data'][0]['count']);

        $this->unlike($b, $post);
        $res = $this->list($author);
        $this->assertSame(1, $res['data'][0]['count']);
        $this->assertSame('Alice P. liked your post.', $res['data'][0]['body']);

        $this->unlike($a, $post);                     // no one left: hidden, and not counted as unread
        $res = $this->list($author);
        $this->assertSame([], $res['data']);
        $this->assertSame(0, $res['meta']['unread']);

        $this->travel(5)->seconds();
        $this->like($a, $post);                       // back again: the same notification, not a new one
        $res = $this->list($author);
        $this->assertSame(1, UserNotification::where('user_id', $author->id)->count());
        $this->assertSame(1, $res['data'][0]['count']);
        $this->assertSame(1, $res['meta']['unread']);
    }

    public function test_after_it_is_read_new_likes_start_a_new_group_with_only_the_new_people()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $this->likers($post, 2);
        $this->signIn($author);
        $this->postJson('/api/v1/me/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);

        $this->travel(5)->seconds();
        $this->likers($post, 1, 3);
        $res = $this->list($author);
        $this->assertCount(2, $res['data']);
        $this->assertSame([1, 2], array_column($res['data'], 'count'));
        $this->assertSame([false, true], array_column($res['data'], 'read'));
        $this->assertSame(1, $res['meta']['unread']);
    }

    public function test_different_posts_never_share_a_group_and_your_own_like_never_notifies()
    {
        $author = $this->member('Grace Akello');
        $one = $this->newPost($author);
        $two = $this->newPost($author);
        $fan = $this->member('Brian Fan');
        $this->like($fan, $one);
        $this->like($fan, $two);
        $this->like($author, $one);

        $res = $this->list($author);
        $this->assertCount(2, $res['data']);
        $this->assertEqualsCanonicalizing(['/community/post/'.$one->uuid, '/community/post/'.$two->uuid], array_column($res['data'], 'url'));
        $this->assertSame([1, 1], array_column($res['data'], 'count'));
        $this->assertSame(2, $res['meta']['unread']);
    }

    public function test_a_suspended_account_stops_appearing_in_the_group()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        [$a, $b] = $this->likers($post, 2);
        $b->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $n = $this->list($author)['data'][0];
        $this->assertSame(1, $n['count']);
        $this->assertSame([$a->id], array_column($n['actors'], 'id'));
        $this->assertSame('Alice P. liked your post.', $n['body']);
    }

    public function test_the_list_uses_a_fixed_number_of_queries_however_many_groups_it_shows()
    {
        $author = $this->member('Grace Akello');
        $fan = $this->member('Brian Fan');
        $first = $this->newPost($author);
        $this->like($fan, $first);
        $this->signIn($author);
        DB::enableQueryLog();
        $this->getJson('/api/v1/me/notifications')->assertOk();
        $few = $this->listQueries();

        foreach (range(1, 6) as $i) {
            $p = $this->newPost($author);
            $this->like($fan, $p);
            $this->like($this->member('Liker '.$i), $p);
        }
        $this->signIn($author);
        DB::flushQueryLog();
        $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(7, 'data');
        $this->assertSame($few, $this->listQueries());
        $this->assertLessThanOrEqual(6, $few);
    }

    /* ---------------- Grouped follows ---------------- */

    public function test_followers_are_grouped_distinctly_and_open_the_newest_followers_page()
    {
        $star = $this->member('Grace Akello');
        $post = $this->newPost($star);                     // so the account has a community page to follow
        $one = $this->member('Alice One');
        $two = $this->member('Brian Two');
        $three = $this->member('Carol Three');
        foreach ([$one, $two, $three] as $f) {
            $this->follow($f, $star)->assertOk();
            $this->travel(2)->seconds();
        }

        $res = $this->list($star);
        $this->assertCount(1, $res['data']);
        $n = $res['data'][0];
        $this->assertSame('New followers', $n['title']);
        $this->assertSame(3, $n['count']);
        $this->assertSame('Carol T., Brian T. and 1 other started following you.', $n['body']);
        $this->assertSame([$three->id, $two->id, $one->id], array_column($n['actors'], 'id'));
        $this->assertSame('/community/people/'.$three->id, $n['url']);

        // Unfollow and follow again: still counted once, no extra notification
        $this->signIn($two);
        $this->deleteJson("/api/v1/community/people/{$star->id}/follow")->assertOk();
        $this->assertSame(2, $this->list($star)['data'][0]['count']);
        $this->travel(2)->seconds();
        $this->follow($two, $star)->assertOk();
        $res = $this->list($star);
        $this->assertSame(3, $res['data'][0]['count']);
        $this->assertSame(1, UserNotification::where('user_id', $star->id)->where('kind', 'community_follows')->count());
        $this->assertSame(1, $res['meta']['unread']);

        // You can't follow yourself, so there is nothing to notify
        $this->follow($star, $star)->assertStatus(422);
    }

    public function test_likes_and_follows_never_share_a_notification()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $this->like($this->member('Brian Fan'), $post);
        $this->follow($this->member('Carol Fan'), $author)->assertOk();

        $kinds = array_column($this->list($author)['data'], 'kind');
        sort($kinds);
        $this->assertSame(['community_follows', 'community_likes'], $kinds);
    }

    /* ---------------- The list: filters, reading, who can see what ---------------- */

    public function test_unread_filter_mark_read_and_ownership()
    {
        $author = $this->member('Grace Akello');
        $other = $this->member('Brian Other');
        $this->like($this->member('Fan One'), $this->newPost($author));
        $this->like($this->member('Fan Two'), $this->newPost($author));
        $this->like($this->member('Fan Three'), $this->newPost($other));

        $all = $this->list($author);
        $this->assertSame(2, $all['meta']['unread']);
        $first = $all['data'][0]['id'];

        // Someone else's notification can't be marked read
        $this->signIn($other);
        $this->postJson("/api/v1/me/notifications/{$first}/read")->assertOk();
        $this->assertNull(UserNotification::find($first)->read_at);

        $this->signIn($author);
        $this->postJson("/api/v1/me/notifications/{$first}/read")->assertOk()->assertJsonPath('data.unread', 1);
        $this->assertCount(1, $this->list($author, '?filter=unread')['data']);
        $this->assertCount(2, $this->list($author, '?filter=all')['data']);
        $this->getJson('/api/v1/me/notifications?filter=weird')->assertStatus(422);
        $this->assertSame(1, count($this->list($other)['data']));
    }

    public function test_notifications_need_signing_in()
    {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/me/notification-settings')->assertUnauthorized();
        $this->putJson('/api/v1/me/notification-settings', ['community_likes' => false])->assertUnauthorized();
        $this->postJson('/api/v1/me/notifications/read')->assertUnauthorized();
    }

    public function test_comments_and_mentions_carry_who_did_it_for_the_avatar()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $this->signIn($this->member('Brian Talker'));
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'Nice tip'])->assertCreated();

        $n = $this->list($author)['data'][0];
        $this->assertFalse($n['grouped']);
        $this->assertSame('medium', $n['priority']);
        $this->assertSame(['Brian T.'], array_column($n['actors'], 'name'));
    }

    /* ---------------- Preferences ---------------- */

    public function test_bell_and_push_are_separate_choices()
    {
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);

        // Bell only: the notification is kept, no push
        app(UserNotifications::class)->choose($author, [], ['community_likes' => false]);
        $this->like($this->member('Fan One'), $post);
        $this->assertSame(1, UserNotification::where('user_id', $author->id)->count());
        $this->assertSame([], $this->pushed);

        // Bell off: nothing at all
        app(UserNotifications::class)->choose($author->fresh(), ['community_likes' => false]);
        $this->travel(5)->seconds();
        $this->like($this->member('Fan Two'), $this->newPost($author));
        $this->assertSame(1, UserNotification::where('user_id', $author->id)->count());
    }

    public function test_settings_show_both_choices_and_quiet_hours_and_validate_them()
    {
        $user = $this->signIn($this->member('Grace Akello'));
        $res = $this->getJson('/api/v1/me/notification-settings')->assertOk();
        $res->assertJsonPath('data.community_likes.on', true)->assertJsonPath('data.community_likes.push', true)
            ->assertJsonPath('data.community_likes.priority', 'low')->assertJsonPath('data.requests.priority', 'high')
            ->assertJsonPath('meta.quiet_hours.enabled', false)->assertJsonPath('meta.quiet_hours.timezone', 'Africa/Kampala')
            ->assertJsonPath('meta.smart_push', false);

        $this->putJson('/api/v1/me/notification-settings', ['push' => ['community_likes' => false], 'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'timezone' => 'Africa/Kampala']])
            ->assertOk()->assertJsonPath('data.community_likes.push', false)->assertJsonPath('data.community_likes.on', true)
            ->assertJsonPath('meta.quiet_hours.enabled', true)->assertJsonPath('meta.quiet_hours.start', '22:00');

        $this->putJson('/api/v1/me/notification-settings', ['quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '22:00']])->assertStatus(422)->assertJsonPath('code', 'quiet_hours_invalid');
        $this->putJson('/api/v1/me/notification-settings', ['quiet_hours' => ['enabled' => true, 'start' => '25:00', 'end' => '07:00']])->assertStatus(422);

        // Switching quiet hours off
        $this->putJson('/api/v1/me/notification-settings', ['quiet_hours' => ['enabled' => false]])->assertOk()->assertJsonPath('meta.quiet_hours.enabled', false);

        // The existing way of choosing still works
        $this->putJson('/api/v1/me/notification-settings', ['community_likes' => false])->assertOk()->assertJsonPath('data.community_likes.on', false);
    }

    /* ---------------- Quiet hours ---------------- */

    public function test_quiet_hours_cross_midnight_and_follow_the_timezone()
    {
        $q = new QuietHours('22:00', '07:00', 'Africa/Kampala');   // UTC+3
        $this->assertTrue($q->isQuiet(Carbon::parse('2026-11-22 20:00:00', 'UTC')));    // 23:00 local
        $this->assertTrue($q->isQuiet(Carbon::parse('2026-11-23 03:59:00', 'UTC')));    // 06:59 local
        $this->assertFalse($q->isQuiet(Carbon::parse('2026-11-23 04:00:00', 'UTC')));   // 07:00 local
        $this->assertFalse($q->isQuiet(Carbon::parse('2026-11-22 18:59:00', 'UTC')));   // 21:59 local
        $this->assertSame('2026-11-23 04:00:00', $q->endsAt(Carbon::parse('2026-11-22 20:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-23 04:00:00', $q->endsAt(Carbon::parse('2026-11-23 01:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertNull($q->endsAt(Carbon::parse('2026-11-23 12:00:00', 'UTC')));

        // Same-day interval, and a different timezone
        $day = new QuietHours('13:00', '15:00', 'Africa/Kampala');
        $this->assertTrue($day->isQuiet(Carbon::parse('2026-11-22 11:00:00', 'UTC')));
        $this->assertFalse($day->isQuiet(Carbon::parse('2026-11-22 13:00:00', 'UTC')));
        $this->assertTrue((new QuietHours('22:00', '07:00', 'Europe/London'))->isQuiet(Carbon::parse('2026-11-22 23:00:00', 'UTC')));
        // No or wrong timezone: Uganda
        $this->assertTrue((new QuietHours('22:00', '07:00', 'Nowhere/Land'))->isQuiet(Carbon::parse('2026-11-22 20:00:00', 'UTC')));
        $this->assertNull(QuietHours::forUser(new User(['quiet_hours_start' => '22:00'])));
    }

    public function test_the_summary_is_dropped_when_everything_was_read_meanwhile()
    {
        $this->smart();
        $user = $this->member('Grace Akello', ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->device($user);
        $this->travelTo(Carbon::parse('2026-11-22 21:00:00', 'UTC'));
        Bus::fake();
        app(UserNotifications::class)->send('community_comments', [$user->id], ['title' => 'New comment', 'body' => 'Brian commented.', 'url' => '/community', 'tag' => 'a']);
        app(UserNotifications::class)->markRead($user);

        $this->travelTo(Carbon::parse('2026-11-23 04:05:00', 'UTC'));
        $this->assertSame(1, $this->flush()['skipped']);
        $this->assertSame([], $this->pushed);
    }

    /* ---------------- Smart push: windows, early push, cooldown, daily limit ---------------- */

    /**
     * The like is stamped in one second and its notification written a moment later: if the clock
     * ticked over in between, the like must still be inside its own group (it once counted as
     * nobody, which broke the like itself with an error).
     */
    public function test_a_like_is_inside_its_group_even_if_the_clock_ticks_over_before_the_notice_is_written()
    {
        foreach ([false, true] as $smart) {
            $this->smart($smart);
            $author = $this->member('Grace Akello '.(int) $smart);
            $this->device($author);
            $post = $this->newPost($author);
            $fan = $this->member('Brian Fan');
            $at = now()->startOfSecond();
            DB::table('community_post_likes')->insert(['community_post_id' => $post->id, 'user_id' => $fan->id, 'created_at' => $at]);
            $this->travel(2)->seconds();                                  // "the clock ticked over"

            DB::transaction(function () use ($post, $fan, $at) {
                app(\App\Services\EngagementNotifications::class)->likeAdded($post, $fan, $at);
            });

            $row = UserNotification::where('user_id', $author->id)->first();
            $this->assertSame(1, $row->group_count);
            $this->assertSame(1, $this->list($author)['data'][0]['count']);
            $this->assertSame($smart ? 1 : 0, NotificationPush::where('user_id', $author->id)->count());
        }
    }

    /* ---------------- Claims, duplicates, retries ---------------- */

    public function test_a_device_that_keeps_failing_is_forgotten()
    {
        $author = $this->member('Grace Akello');
        $sub = $this->device($author);
        $sub->forceFill(['failure_count' => 7])->save();
        $this->failing = [$sub->endpoint];

        app(\App\Services\PushNotifications::class)->deliverNow([$author->id], ['title' => 't', 'body' => 'b', 'url' => '/', 'tag' => 'x']);
        $this->assertNull(PushSubscription::find($sub->id));
    }

    /* ---------------- The switch, and what it never touches ---------------- */

    public function test_smart_push_is_off_by_default_and_grouping_still_shows_in_the_bell()
    {
        $this->assertFalse(app(PlatformSettings::class)->get('notifications.smart_push'));
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->likers($post, 3);

        $this->assertSame(0, NotificationPush::count());
        $this->assertSame(3, $this->list($author)['data'][0]['count']);
    }

    public function test_with_smart_push_off_the_old_hourly_like_push_still_works()
    {
        Bus::fake();
        $jobs = function () {
            return Bus::dispatchedAfterResponse(SendUserNotification::class);
        };
        $message = function ($job) {
            $p = new \ReflectionProperty($job, 'message');
            $p->setAccessible(true);

            return $p->getValue($job);
        };
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->likers($post, 1);
        $this->assertCount(1, $jobs());
        $this->assertSame('Alice P. liked your post.', $message($jobs()->last())['body']);

        $this->likers($post, 1, 2);                                      // within the hour: the bell is updated, no push
        $this->assertCount(1, $jobs());
        $this->assertSame(2, $this->list($author)['data'][0]['count']);

        $this->travel(61)->minutes();
        $this->likers($post, 1, 3);
        $this->assertCount(2, $jobs());
        $this->assertSame('Carol P., Brian P. and 1 other liked your post.', $message($jobs()->last())['body']);
    }

    public function test_mentions_and_comments_still_push_at_once_with_smart_push_on()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->signIn($this->member('Brian Talker'));
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'Nice tip'])->assertCreated();

        $this->assertCount(1, $this->pushed);
        $this->assertSame('New comment', $this->pushed[0]['payload']['title']);
        $this->assertSame('normal', $this->pushed[0]['options']['urgency']);
    }

    public function test_high_priority_pushes_ask_the_phone_to_wake_up()
    {
        $user = $this->member('Grace Akello');
        $this->device($user);
        app(\App\Services\PushNotifications::class)->deliverToUsers([$user->id], ['title' => 't', 'body' => 'b', 'url' => '/', 'tag' => 'x'], app(WebPush::class), 'high');
        $this->assertSame('high', $this->pushed[0]['options']['urgency']);
        $this->assertSame('high', UserNotifications::priority('requests'));
        $this->assertSame('high', UserNotifications::priority('messages'));
        $this->assertSame('medium', UserNotifications::priority('community_mentions'));
        $this->assertSame('low', UserNotifications::priority('community_likes'));
    }

    /* ---------------- Real-time delivery: the push outbox ---------------- */

    /** A like through the service (no web request): its push is saved but nothing has tried it yet, as if the process had stopped */
    private function likeDirect(User $by, CommunityPost $post): void
    {
        app(Community::class)->like($by, $post, true);
    }

    private function process(int $id): string
    {
        return app(PushDelivery::class)->process($id);
    }

    private function endpoints(): array
    {
        return array_column($this->pushed, 'endpoint');
    }

    public function test_the_first_like_is_pushed_at_once_and_the_wording_is_not_kept()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->like($this->member('Alice One'), $post);                    // one request: nothing waits, no scheduler run

        $this->assertCount(1, $this->pushed);
        $p = $this->pushed[0];
        $this->assertSame('Alice O. liked your post.', $p['payload']['body']);
        $this->assertArrayNotHasKey('silent', $p['payload']);              // the first one alerts
        $this->assertSame('community-post-'.$post->uuid.'-likes', $p['payload']['tag']);
        $this->assertSame('low', $p['options']['urgency']);
        $row = NotificationPush::sole();
        $this->assertSame('sent', $row->status);
        $this->assertNull($row->payload);                                  // nothing is kept once it is delivered
        $this->assertSame(1, $row->devices_delivered);
        $this->assertSame(1, $this->list($author)['meta']['unread']);
    }

    public function test_later_likes_update_the_same_group_and_the_same_phone_notification_silently()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->likers($post, 4);                                           // each goes at once, none waits for more

        $this->assertCount(4, $this->pushed);
        $this->assertCount(1, array_unique(array_column(array_column($this->pushed, 'payload'), 'tag')));   // one phone notification, replaced
        $this->assertSame([false, true, true, true], array_map(function ($p) {
            return isset($p['payload']['silent']);
        }, $this->pushed));                                                // the first alerts, the updates are quiet
        $this->assertSame([
            'Alice P. liked your post.',
            'Brian P. and Alice P. liked your post.',
            'Carol P., Brian P. and 1 other liked your post.',
            'David P., Carol P. and 2 others liked your post.',
        ], array_column(array_column($this->pushed, 'payload'), 'body'));  // the wording is as true as the list's, each time
        $this->assertSame(1, UserNotification::where('user_id', $author->id)->count());
        $this->assertSame(4, $this->list($author)['data'][0]['count']);
        $this->assertSame(4, NotificationPush::where('status', 'sent')->count());
    }

    public function test_events_arriving_while_a_push_is_waiting_share_it()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        foreach (['Alice One', 'Brian Two', 'Carol Three'] as $name) {
            $this->likeDirect($this->member($name), $post);
            $this->travel(2)->seconds();
        }
        $this->assertSame(1, NotificationPush::count());                   // one push on its way, not three
        $this->assertSame([], $this->pushed);

        $this->flush();
        $this->assertCount(1, $this->pushed);
        $this->assertSame('Carol T., Brian T. and 1 other liked your post.', $this->pushed[0]['payload']['body']);   // said as the group is when it goes
    }

    public function test_an_event_arriving_while_a_push_is_in_flight_gets_a_follow_up()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->likeDirect($this->member('Alice One'), $post);
        NotificationPush::query()->update(['status' => 'sending', 'claimed_at' => now()]);   // being sent right now

        $this->travel(2)->seconds();
        $this->likeDirect($this->member('Brian Two'), $post);
        $this->assertSame(2, NotificationPush::count());
        $this->assertSame(1, NotificationPush::where('status', 'pending')->count());
    }

    public function test_the_scheduler_finds_a_push_whose_immediate_attempt_never_ran()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));      // saved with the event; the after-response attempt was lost
        $this->assertSame([], $this->pushed);

        $stats = $this->flush();
        $this->assertSame(1, $stats['sent']);
        $this->assertCount(1, $this->pushed);
    }

    public function test_a_push_no_device_took_is_retried_with_growing_waits_then_given_up()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $sub = $this->device($author);
        $this->failing = [$sub->endpoint];
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));
        $id = NotificationPush::sole()->id;

        $this->assertSame('retry', $this->process($id));                  // try 1: again in 20 seconds
        $row = NotificationPush::find($id);
        $this->assertSame([1, 'pending'], [$row->attempts, $row->status]);
        $this->assertSame('busy', $this->process($id));                   // not yet due
        $this->travel(21)->seconds();
        $this->assertSame('retry', $this->process($id));                  // try 2: in 60 seconds
        $this->travel(61)->seconds();
        $this->assertSame('retry', $this->process($id));                  // try 3: in 5 minutes
        $this->travel(301)->seconds();
        $this->assertSame('retry', $this->process($id));                  // try 4: in 30 minutes
        $this->travel(1801)->seconds();
        $this->assertSame('gave_up', $this->process($id));                // try 5: no more
        $this->assertCount(5, $this->pushed);
        $this->assertSame('gave_up', NotificationPush::find($id)->status);
        $this->assertNull(NotificationPush::find($id)->payload);
        $this->assertSame(5, $sub->fresh()->failure_count);
        $this->travel(2)->hours();
        $this->assertSame('busy', $this->process($id));
        $this->assertCount(5, $this->pushed);
        $this->assertSame(1, UserNotification::where('user_id', $author->id)->count());   // the notification itself is never lost
    }

    public function test_a_retry_goes_only_to_the_devices_that_have_not_taken_it()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $a = $this->device($author, 'https://push.test/a');
        $b = $this->device($author, 'https://push.test/b');
        $this->failing = [$b->endpoint];
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));
        $id = NotificationPush::sole()->id;

        $this->assertSame('retry', $this->process($id));
        $this->assertEqualsCanonicalizing([$a->endpoint, $b->endpoint], $this->endpoints());
        $this->failing = [];
        $this->travel(21)->seconds();
        $this->assertSame('sent', $this->process($id));
        $this->assertEqualsCanonicalizing([$a->endpoint, $b->endpoint, $b->endpoint], $this->endpoints());   // A was not sent it twice
        $this->assertSame(['accepted', 'accepted'], DB::table('notification_push_deliveries')->where('notification_push_id', $id)->orderBy('push_subscription_id')->pluck('status')->all());
    }

    public function test_gone_devices_are_removed_and_refused_ones_are_not_retried()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $ok = $this->device($author, 'https://push.test/ok');
        $old = $this->device($author, 'https://push.test/old');
        $no = $this->device($author, 'https://push.test/no');
        $this->gone = [$old->endpoint];
        $this->rejected = [$no->endpoint];
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));

        $this->assertSame('sent', $this->process(NotificationPush::sole()->id));    // one device took it: done
        $this->assertNull(PushSubscription::find($old->id));
        $this->assertNotNull(PushSubscription::find($no->id));
        $this->assertCount(3, $this->pushed);
        $this->flush();
        $this->assertCount(3, $this->pushed);                                       // nobody is tried again

        // Only a refusal: given up, not retried
        $solo = $this->member('Grace Two');
        $r = $this->device($solo, 'https://push.test/solo');
        $this->rejected = [$r->endpoint];
        $this->likeDirect($this->member('Brian Two'), $this->newPost($solo));
        $this->assertSame('gave_up', $this->process(NotificationPush::where('user_id', $solo->id)->sole()->id));
        $this->assertSame('rejected', NotificationPush::where('user_id', $solo->id)->value('note'));
    }

    public function test_a_claim_left_by_a_dead_process_is_recovered_and_does_not_send_again_to_a_device_that_took_it()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $a = $this->device($author, 'https://push.test/a');
        $b = $this->device($author, 'https://push.test/b');
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));
        $push = NotificationPush::sole();
        // The process died after A took it (and that was written down) but before B was tried
        $push->forceFill(['status' => 'sending', 'claimed_at' => now()->subMinutes(11), 'attempts' => 1])->save();
        DB::table('notification_push_deliveries')->insert(['notification_push_id' => $push->id, 'push_subscription_id' => $a->id, 'status' => 'accepted', 'http_status' => 201, 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $stats = $this->flush();
        $this->assertSame(1, $stats['recovered']);
        $this->assertSame(1, $stats['sent']);
        $this->assertSame([$b->endpoint], $this->endpoints());                // only B: A already has it

        // A claim that is only a minute old is left alone
        $this->likeDirect($this->member('Brian Two'), $this->newPost($author));
        NotificationPush::where('status', 'pending')->update(['status' => 'sending', 'claimed_at' => now()->subMinute()]);
        $this->assertSame(0, $this->flush()['recovered']);
    }

    public function test_two_workers_cannot_claim_the_same_push()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));
        $id = NotificationPush::sole()->id;

        // Another worker has claimed it
        NotificationPush::whereKey($id)->update(['status' => 'sending', 'claimed_at' => now()]);
        $this->assertSame('busy', $this->process($id));
        $this->assertSame([], $this->pushed);
        NotificationPush::whereKey($id)->update(['status' => 'pending', 'claimed_at' => null]);

        $this->assertSame('sent', $this->process($id));
        $this->assertSame('busy', $this->process($id));                        // finished: nothing more
        $this->assertCount(1, $this->pushed);
        $this->assertSame(1, NotificationPush::count());                        // and the same event can't be made twice
    }

    public function test_every_device_is_handled_independently()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        foreach (['a', 'b', 'c'] as $d) {
            $this->device($author, 'https://push.test/'.$d);
        }
        $this->like($this->member('Alice One'), $this->newPost($author));

        $this->assertEqualsCanonicalizing(['https://push.test/a', 'https://push.test/b', 'https://push.test/c'], $this->endpoints());
        $this->assertSame(3, NotificationPush::sole()->devices_delivered);
        $this->assertSame(3, DB::table('notification_push_deliveries')->where('status', 'accepted')->count());
    }

    public function test_quiet_hours_make_phone_alerts_silent_but_never_late()
    {
        $this->smart();
        $author = $this->member('Grace Akello', ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->device($author);
        $this->travelTo(Carbon::parse('2026-11-22 20:30:00', 'UTC'));       // 23:30 in Kampala
        $this->like($this->member('Alice One'), $this->newPost($author));

        $this->assertCount(1, $this->pushed);                               // at once, not at 07:00
        $this->assertTrue($this->pushed[0]['payload']['silent']);          // but quietly
        $this->assertSame(1, $this->list($author)['meta']['unread']);      // and the list has it

        $this->travelTo(Carbon::parse('2026-11-23 05:30:00', 'UTC'));       // 08:30: out of quiet hours
        $this->like($this->member('Brian Two'), $this->newPost($author));
        $this->assertCount(2, $this->pushed);
        $this->assertArrayNotHasKey('silent', $this->pushed[1]['payload']); // a new post's first like alerts again
    }

    public function test_comments_and_mentions_are_pushed_at_once_without_their_words()
    {
        $this->smart();
        $author = $this->member('Grace Akello', ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->device($author);
        $post = $this->newPost($author);
        $brian = $this->member('Brian Talker');
        $this->signIn($brian);

        $this->travelTo(Carbon::parse('2026-11-23 09:00:00', 'UTC'));       // 12:00 local
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'My secret price is 5 million'])->assertCreated();
        $this->assertCount(1, $this->pushed);
        $this->assertSame('Brian T. commented on your post.', $this->pushed[0]['payload']['body']);
        $this->assertStringNotContainsString('secret', json_encode($this->pushed[0]['payload']));
        $this->assertArrayNotHasKey('silent', $this->pushed[0]['payload']);
        $row = NotificationPush::where('type', 'direct')->sole();
        $this->assertSame(['sent', null], [$row->status, $row->payload]);   // the wording is gone once delivered

        // In quiet hours: still at once, but silent
        $this->travelTo(Carbon::parse('2026-11-22 21:30:00', 'UTC'));       // 00:30 local
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'Another one'])->assertCreated();
        $this->assertCount(2, $this->pushed);
        $this->assertTrue($this->pushed[1]['payload']['silent']);

        // A mention
        $this->travelTo(Carbon::parse('2026-11-23 09:30:00', 'UTC'));
        $this->postJson('/api/v1/community/posts', ['category' => 'tips', 'body' => 'Thanks @[u:'.$author->id.']'])->assertCreated();
        $this->assertCount(3, $this->pushed);
        $this->assertSame('You were mentioned', $this->pushed[2]['payload']['title']);
        $this->assertSame('normal', $this->pushed[2]['options']['urgency']);
    }

    public function test_other_medium_notices_still_get_one_quiet_hours_summary_and_bookings_are_never_held()
    {
        $this->smart();
        $user = $this->member('Grace Akello', ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->device($user);
        $this->travelTo(Carbon::parse('2026-11-22 21:00:00', 'UTC'));       // 00:00 in Kampala
        $notices = app(UserNotifications::class);

        Bus::fake();
        $notices->send('community_posts', [$user->id], ['title' => 'New post', 'body' => 'Brian shared something.', 'url' => '/community', 'tag' => 'a']);
        $notices->send('referrals', [$user->id], ['title' => 'Invite joined', 'body' => 'Carol joined.', 'url' => '/account/invite', 'tag' => 'b']);
        Bus::assertNotDispatchedAfterResponse(SendUserNotification::class);
        $this->assertSame(1, NotificationPush::where('type', 'digest')->count());   // two notices, one summary

        // Bookings, messages and account notices are not touched by the outbox or by quiet hours
        $notices->send('requests', [$user->id], ['title' => 'New request', 'body' => 'A customer asked for you.', 'url' => '/requests', 'tag' => 'c']);
        $notices->send('messages', [$user->id], ['title' => 'New message', 'body' => 'Okello wrote.', 'url' => '/messages', 'tag' => 'd']);
        Bus::assertDispatchedAfterResponse(SendUserNotification::class, 2);
        $this->assertSame(0, NotificationPush::where('type', 'direct')->count());

        $this->travelTo(Carbon::parse('2026-11-23 04:05:00', 'UTC'));
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertSame('You have 4 new notifications.', $this->pushed[0]['payload']['body']);
    }

    public function test_too_many_sounds_make_later_pushes_silent_never_lost()
    {
        $this->smart();
        config(['notification_policy.audible_per_hour' => 2]);
        $author = $this->member('Grace Akello');
        $this->device($author);
        foreach (range(1, 4) as $i) {
            $this->like($this->member('Fan Number'.$i), $this->newPost($author));      // four different posts
        }

        $this->assertCount(4, $this->pushed);                                           // every one delivered
        $this->assertSame([false, false, true, true], array_map(function ($p) {
            return isset($p['payload']['silent']);
        }, $this->pushed));                                                             // but only two with a sound
        $this->assertSame(4, UserNotification::where('user_id', $author->id)->count());   // and the list has all four
    }

    public function test_choices_are_checked_again_when_the_push_is_sent()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));
        app(UserNotifications::class)->choose($author, [], ['community_likes' => false]);   // phone off, after the event

        $this->assertSame(1, $this->flush()['skipped']);
        $this->assertSame([], $this->pushed);
        $this->assertSame('turned off', NotificationPush::sole()->note);
        $this->assertSame(1, $this->list($author)['meta']['unread']);                       // the list is untouched

        // The list switched off: nothing is made at all
        app(UserNotifications::class)->choose($author->fresh(), ['community_likes' => false]);
        $this->travel(5)->seconds();
        $this->like($this->member('Brian Two'), $this->newPost($author));
        $this->assertSame(1, NotificationPush::count());
    }

    public function test_nothing_is_pushed_for_what_was_read_or_taken_back_or_for_nobody_with_a_device()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $readPost = $this->newPost($author);
        $this->likeDirect($this->member('Alice One'), $readPost);
        app(UserNotifications::class)->markRead($author);                                   // read before it is sent

        $this->travel(5)->seconds();
        $gone = $this->newPost($author);
        $fan = $this->member('Brian Two');
        $this->likeDirect($fan, $gone);
        app(Community::class)->like($fan, $gone, false);                                    // taken back

        $quiet = $this->member('No Device');
        $this->likeDirect($this->member('Carol Three'), $this->newPost($quiet));        // no device: nothing is even saved

        $stats = $this->flush();
        $this->assertSame(2, $stats['skipped']);
        $this->assertSame([], $this->pushed);
        $this->assertEqualsCanonicalizing(['already read', 'no one left'], NotificationPush::pluck('note')->all());
        $this->assertSame(0, NotificationPush::where('user_id', $quiet->id)->count());
    }

    public function test_an_old_push_is_dropped_not_sent_hours_late()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));
        $this->travel(4)->hours();

        $this->assertSame(1, $this->flush()['skipped']);
        $this->assertSame('stale', NotificationPush::sole()->note);
        $this->assertSame([], $this->pushed);
    }

    public function test_with_smart_push_off_nothing_goes_through_the_outbox()
    {
        Bus::fake();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->like($this->member('Alice One'), $post);
        $this->signIn($this->member('Brian Talker'));
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'Nice'])->assertCreated();

        $this->assertSame(0, NotificationPush::count());                       // the old immediate path, as before
        Bus::assertDispatchedAfterResponse(SendUserNotification::class, 2);
        $this->assertSame(2, UserNotification::where('user_id', $author->id)->count());
    }

    public function test_the_unread_count_endpoint_is_private_and_exact()
    {
        $this->getJson('/api/v1/me/notifications/unread-count')->assertUnauthorized();

        $author = $this->member('Grace Akello');
        $other = $this->member('Brian Other');
        $this->like($this->member('Alice One'), $this->newPost($author));
        $this->like($this->member('Carol Three'), $this->newPost($author));
        $this->signIn($author);
        $this->getJson('/api/v1/me/notifications/unread-count')->assertOk()->assertExactJson(['data' => ['unread' => 2]]);
        $this->signIn($other);
        $this->getJson('/api/v1/me/notifications/unread-count')->assertOk()->assertExactJson(['data' => ['unread' => 0]]);
    }

    /* ---------------- Pre-deployment review: the limit, switching off, saved rows, commit order ---------------- */

    public function test_past_the_hard_limit_nothing_is_dropped_the_overflow_becomes_one_summary()
    {
        $this->smart();
        config(['notification_policy.pushes_per_minute' => 3]);
        $author = $this->member('Grace Akello');
        $this->device($author);
        foreach (range(1, 6) as $i) {
            $this->like($this->member('Fan Number'.$i), $this->newPost($author));      // six different posts, a minute's flood
            if ($i === 3) {
                $this->travel(6)->seconds();                                            // the first three were pushed; the rest are over the limit
            }
        }
        $this->signIn($this->member('Brian Talker'));
        $post = $this->newPost($author);
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'A comment in the flood'])->assertCreated();   // the 7th event is a comment

        $this->assertCount(3, $this->pushed);                                          // the first three went at once
        $this->assertSame(4, NotificationPush::where('note', 'summarized')->count());  // the other four were not discarded...
        $this->assertSame(1, NotificationPush::where('type', 'digest')->count());      // ...they became ONE summary
        $this->assertSame(7, UserNotification::where('user_id', $author->id)->count());
        $this->assertSame(7, $this->list($author)['meta']['unread']);                  // the list has every one

        $this->travel(61)->seconds();
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertCount(4, $this->pushed);                                          // the summary reached the phone
        $this->assertSame('You have 4 new notifications.', $this->pushed[3]['payload']['body']);   // counting the ones it stands in for
        $this->assertSame('/notifications', $this->pushed[3]['payload']['url']);
    }

    public function test_a_crowd_of_waiting_pushes_is_let_through_in_order_up_to_the_limit_not_all_summarized()
    {
        $this->smart();
        config(['notification_policy.pushes_per_minute' => 3]);
        $author = $this->member('Grace Akello');
        $this->device($author);
        foreach (range(1, 8) as $i) {
            $this->likeDirect($this->member('Fan Number'.$i), $this->newPost($author));     // eight pushes waiting at the same moment
        }

        $stats = $this->flush();
        $this->assertSame(3, $stats['sent']);                                               // the first three go (the limit)
        $this->assertSame(5, $stats['skipped']);                                            // the other five are folded into one summary
        $this->assertCount(3, $this->pushed);
        $this->assertSame(1, NotificationPush::where('type', 'digest')->where('status', 'pending')->count());
        $this->assertSame(8, UserNotification::where('user_id', $author->id)->count());     // and the list has all eight
    }

    public function test_the_summary_is_not_sent_if_everything_was_read_meanwhile()
    {
        $this->smart();
        config(['notification_policy.pushes_per_minute' => 2]);
        $author = $this->member('Grace Akello');
        $this->device($author);
        foreach (range(1, 4) as $i) {
            $this->like($this->member('Fan Number'.$i), $this->newPost($author));
        }
        app(UserNotifications::class)->markRead($author);

        $this->travel(61)->seconds();
        $this->assertSame(1, $this->flush()['skipped']);
        $this->assertCount(2, $this->pushed);
    }

    public function test_a_flood_of_engagement_never_touches_bookings_or_messages()
    {
        $this->smart();
        config(['notification_policy.pushes_per_minute' => 1, 'notification_policy.audible_per_hour' => 1]);
        $user = $this->member('Grace Akello');
        $this->device($user);
        foreach (range(1, 4) as $i) {
            $this->likeDirect($this->member('Fan Number'.$i), $this->newPost($user));      // four waiting engagement pushes
        }

        Bus::fake();
        $notices = app(UserNotifications::class);
        $notices->send('requests', [$user->id], ['title' => 'New request', 'body' => 'A customer asked for you.', 'url' => '/activity', 'tag' => 'r']);
        $notices->send('messages', [$user->id], ['title' => 'New message', 'body' => 'Okello wrote.', 'url' => '/messages', 'tag' => 'm']);
        Bus::assertDispatchedAfterResponse(SendUserNotification::class, 2);             // both go at once, on their own path
        $this->assertSame(0, NotificationPush::where('type', 'direct')->count());       // and are not in the outbox
        $this->assertSame(6, UserNotification::where('user_id', $user->id)->count());
    }

    public function test_after_switching_off_new_events_use_the_old_immediate_path_and_keep_their_records()
    {
        $this->smart();
        $this->smart(false);
        Bus::fake();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $jobs = function () {
            return Bus::dispatchedAfterResponse(SendUserNotification::class)->count();
        };

        // Likes: the old rule, a push at most once an hour per post; the list is updated every time
        $this->like($this->member('Alice One'), $post);
        $this->assertSame(1, $jobs());
        $this->like($this->member('Brian Two'), $post);
        $this->assertSame(1, $jobs());
        $this->assertSame(2, $this->list($author)['data'][0]['count']);

        // Comments and mentions: at once
        $this->signIn($this->member('Carol Three'));
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'Nice'])->assertCreated();
        $this->assertSame(2, $jobs());
        $this->postJson('/api/v1/community/posts', ['category' => 'tips', 'body' => 'Thanks @[u:'.$author->id.']'])->assertCreated();
        $this->assertSame(3, $jobs());

        // Follows: the old rule, once however often they follow again
        $fan = $this->member('David Four');
        $this->newPost($author);
        $this->follow($fan, $author)->assertOk();
        $this->signIn($fan);
        $this->deleteJson("/api/v1/community/people/{$author->id}/follow")->assertOk();
        $this->postJson("/api/v1/community/people/{$author->id}/follow")->assertOk();
        $this->assertSame(4, $jobs());

        $this->assertSame(0, NotificationPush::count());                                // nothing went through the outbox
        $this->assertSame(4, UserNotification::where('user_id', $author->id)->count()); // every notification is in the list
        // It is the old rules coming back, not the end of engagement pushes: only the person's own phone choices stop those
    }

    public function test_pushes_already_saved_are_still_delivered_and_retried_after_switching_off()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $sub = $this->device($author);
        $this->failing = [$sub->endpoint];
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));
        $id = NotificationPush::sole()->id;
        $this->assertSame('retry', $this->process($id));                               // the first try failed: waiting for a retry

        $this->smart(false);                                                           // switched off meanwhile
        $this->failing = [];
        $this->travel(21)->seconds();
        $stats = $this->flush();                                                       // the scheduler still works
        $this->assertSame(1, $stats['sent']);
        $this->assertCount(2, $this->pushed);                                          // the retry went out
        $this->assertSame('sent', NotificationPush::find($id)->status);

        // A push saved but not yet tried is sent too
        $this->smart();
        $this->likeDirect($this->member('Brian Two'), $this->newPost($author));
        $this->smart(false);
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertCount(3, $this->pushed);
        $this->assertSame(2, UserNotification::where('user_id', $author->id)->count());   // and the records were never touched
    }

    public function test_a_summary_waiting_for_the_end_of_quiet_hours_is_still_sent_after_switching_off()
    {
        $this->smart();
        $user = $this->member('Grace Akello', ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->device($user);
        $this->travelTo(Carbon::parse('2026-11-22 21:00:00', 'UTC'));                  // 00:00 local
        Bus::fake();
        app(UserNotifications::class)->send('community_posts', [$user->id], ['title' => 'New post', 'body' => 'Brian shared.', 'url' => '/community', 'tag' => 'a']);
        $this->assertSame(1, NotificationPush::where('type', 'digest')->count());
        $this->smart(false);

        $this->travelTo(Carbon::parse('2026-11-23 04:05:00', 'UTC'));
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertSame('You have 1 new notification.', $this->pushed[0]['payload']['body']);
    }

    public function test_pushes_saved_by_the_earlier_version_are_handled_after_the_upgrade()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->likeDirect($this->member('Alice One'), $post);                          // a group row, as the earlier version made it
        // ...with the old shape: still waiting out a 15 minute window, no kind, no payload, no silent flag
        NotificationPush::query()->update(['send_after' => now()->addMinutes(12), 'kind' => null, 'payload' => null, 'silent' => null]);

        $stats = $this->flush();                                                        // nothing waits any more
        $this->assertSame(1, $stats['sent']);
        $this->assertCount(1, $this->pushed);
        $this->assertSame('Alice O. liked your post.', $this->pushed[0]['payload']['body']);

        // A summary of the earlier version keeps its own due time (it is a quiet hours summary)
        $user = $this->member('Brian Two');
        $this->device($user, 'https://push.test/two');
        DB::table('user_notifications')->insert(['user_id' => $user->id, 'kind' => 'community_posts', 'title' => 'New post', 'body' => 'x', 'url' => '/', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_pushes')->insert(['user_id' => $user->id, 'type' => 'digest', 'dedupe_key' => 'digest:old:1', 'status' => 'pending', 'send_after' => now()->addHours(2), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, $this->flush()['sent']);
        $this->travel(121)->minutes();
        $this->assertSame(1, $this->flush()['sent']);

        // One claimed by a process that stopped long ago
        $this->likeDirect($this->member('Carol Three'), $this->newPost($author));
        NotificationPush::where('status', 'pending')->update(['status' => 'sending', 'claimed_at' => now()->subHour()]);
        $this->assertSame(1, $this->flush()['recovered']);
    }

    public function test_nothing_is_saved_for_someone_with_no_device()
    {
        $this->smart();
        $nobody = $this->member('Grace Akello');
        $this->like($this->member('Alice One'), $this->newPost($nobody));
        $this->signIn($this->member('Brian Talker'));
        $this->postJson("/api/v1/community/posts/{$this->newPost($nobody)->uuid}/comments", ['body' => 'Hi'])->assertCreated();

        $this->assertSame(0, NotificationPush::count());                               // no row, no work
        $this->assertSame(2, UserNotification::where('user_id', $nobody->id)->count());   // the list is complete

        $this->device($nobody);                                                         // now they turn notifications on
        $this->like($this->member('Carol Three'), $this->newPost($nobody));
        $this->assertSame(1, NotificationPush::count());
    }

    public function test_the_attempt_waits_for_the_commit_and_never_happens_for_a_rollback()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $fan = $this->member('Alice One');

        Bus::fake();
        DB::transaction(function () use ($post, $fan) {
            app(Community::class)->like($fan, $post, true);
            $this->assertSame(1, NotificationPush::count());                           // saved with the event
            Bus::assertNotDispatchedAfterResponse(FlushNotificationPushes::class);     // but not tried before the commit
        });
        Bus::assertDispatchedAfterResponse(FlushNotificationPushes::class, 1);         // tried once it is committed

        // Rolled back: no row and no attempt
        $other = $this->newPost($author);
        $fan2 = $this->member('Brian Two');
        try {
            DB::transaction(function () use ($other, $fan2) {
                app(Community::class)->like($fan2, $other, true);
                throw new \RuntimeException('something later fails');
            });
        } catch (\RuntimeException $e) {
        }
        Bus::assertDispatchedAfterResponse(FlushNotificationPushes::class, 1);         // still just the one
        $this->assertSame(1, NotificationPush::count());
        $this->assertSame(0, DB::table('community_post_likes')->where('community_post_id', $other->id)->count());
    }

    public function test_an_immediate_attempt_that_blows_up_stays_recoverable()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likeDirect($this->member('Alice One'), $this->newPost($author));
        $id = NotificationPush::sole()->id;

        $this->explode = true;                                                         // the push library itself throws
        $this->assertSame('retry', $this->process($id));
        $row = NotificationPush::find($id);
        $this->assertSame(['pending', 1], [$row->status, $row->attempts]);
        $this->assertNotNull($row->payload === null ? 'group rows keep no payload' : $row->payload);

        $this->explode = false;
        $this->travel(21)->seconds();
        $this->assertSame(1, $this->flush()['sent']);                                  // the scheduler finds it and delivers
        $this->assertCount(1, $this->pushed);
        $this->assertSame(1, UserNotification::where('user_id', $author->id)->count());
    }

    /* ---------------- Release safety ---------------- */

    protected function tearDown(): void
    {
        \App\Services\EngagementNotifications::$withoutWindowFunctions = false;

        parent::tearDown();
    }

    public function test_a_database_without_window_functions_gives_the_same_list()
    {
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $this->likers($post, 4);
        foreach ([$this->member('Alice One'), $this->member('Brian Two')] as $f) {
            $this->follow($f, $author);
            $this->travel(2)->seconds();
        }
        $normal = $this->list($author);

        \App\Services\EngagementNotifications::$withoutWindowFunctions = true;
        $slow = $this->list($author);

        $this->assertSame($normal['data'], $slow['data']);
        $this->assertSame(4, collect($slow['data'])->firstWhere('kind', 'community_likes')['count']);
        $this->assertCount(3, collect($slow['data'])->firstWhere('kind', 'community_likes')['actors']);
        $this->assertSame($normal['meta'], $slow['meta']);
    }

    public function test_a_group_read_in_the_same_second_as_the_next_like_causes_no_conflict()
    {
        $this->travelTo(Carbon::parse('2026-11-22 09:00:00', 'UTC'));
        $author = $this->member('Grace Akello');
        $post = $this->newPost($author);
        $a = $this->member('Alice One');
        $b = $this->member('Brian Two');

        $this->like($a, $post);
        $this->signIn($author);
        $this->postJson('/api/v1/me/notifications/read')->assertOk();   // read in the very second of the group's start
        $this->like($b, $post);                                         // same second: the same group key

        $this->assertSame(1, UserNotification::where('user_id', $author->id)->count());   // no duplicate, no error
        $row = UserNotification::first();
        $this->assertNotNull($row->read_at);
        $this->assertSame(2, $this->list($author)['data'][0]['count']);                    // nothing is lost from the count
        $this->travel(5)->seconds();
        $this->like($this->member('Carol Three'), $post);                                  // a later like starts a fresh group
        $this->assertSame(2, UserNotification::where('user_id', $author->id)->count());
        $this->assertSame([1, 2], array_column($this->list($author)['data'], 'count'));
    }

    public function test_notifications_that_existed_before_grouping_keep_their_tags_read_state_and_links()
    {
        $user = $this->member('Grace Akello');
        $old = function (array $f) use ($user) {
            DB::table('user_notifications')->insert($f + ['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        };
        // Rows exactly as they were written before this release: no group, subject or actor
        $old(['kind' => 'messages', 'title' => 'New message', 'body' => 'Okello sent you a message.', 'url' => '/messages/c/5', 'tag' => 'chat-5']);
        $old(['kind' => 'community_likes', 'title' => 'Your post was liked', 'body' => 'Brian and 3 others liked your post.', 'url' => '/community/post/abc', 'tag' => 'community-post-abc-likes', 'read_at' => now()->subDay()]);
        $old(['kind' => 'community_follows', 'title' => 'New follower', 'body' => 'Sarah started following you on WorkMate.', 'url' => '/community/people/9', 'tag' => 'community-followers']);
        $old(['kind' => 'account', 'title' => 'A notice from WorkMate', 'body' => 'Your document was approved.', 'url' => '/account/verification', 'tag' => null]);
        $old(['kind' => 'account', 'title' => 'Another notice', 'body' => 'Second notice.', 'url' => '/account', 'tag' => null]);   // many rows without a tag or group: no unique conflict

        $res = $this->list($user);
        $this->assertCount(5, $res['data']);
        $this->assertSame(4, $res['meta']['unread']);                        // the one read stays read
        $byKind = collect($res['data'])->keyBy('kind');
        $this->assertSame('/messages/c/5', $byKind['messages']['url']);
        $this->assertSame('Brian and 3 others liked your post.', $byKind['community_likes']['body']);   // old wording shown as written
        $this->assertTrue($byKind['community_likes']['read']);
        $this->assertFalse($byKind['community_likes']['grouped']);
        $this->assertSame([], $byKind['community_follows']['actors']);

        // The old rule still merges an unread notice with the same tag, as before
        app(UserNotifications::class)->send('messages', [$user->id], ['title' => 'New message', 'body' => 'Okello sent you another.', 'url' => '/messages/c/5', 'tag' => 'chat-5', 'push' => false]);
        $this->assertSame(1, UserNotification::where('user_id', $user->id)->where('kind', 'messages')->count());
        $this->assertSame('Okello sent you another.', UserNotification::where('kind', 'messages')->value('body'));

        // Marking read works on old rows
        $this->signIn($user);
        $this->postJson('/api/v1/me/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);
    }

    public function test_changing_phone_or_quiet_hours_settings_never_touches_the_other_choices()
    {
        $user = $this->member('Grace Akello', ['notification_preferences' => ['community_mentions' => false, 'messages' => false, 'push:community_comments' => false]]);
        $this->signIn($user);

        $this->putJson('/api/v1/me/notification-settings', ['push' => ['community_likes' => false]])->assertOk();
        $this->putJson('/api/v1/me/notification-settings', ['quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00']])->assertOk();
        $this->putJson('/api/v1/me/notification-settings', ['community_follows' => false])->assertOk();

        $prefs = $user->fresh()->notification_preferences;
        $this->assertFalse($prefs['community_mentions']);                    // the existing mention choice is as it was
        $this->assertFalse($prefs['messages']);
        $this->assertFalse($prefs['push:community_comments']);
        $this->assertFalse($prefs['push:community_likes']);
        $this->assertFalse($prefs['community_follows']);
        $this->assertArrayNotHasKey('community_likes', $prefs);              // bell choice for likes untouched
        $res = $this->getJson('/api/v1/me/notification-settings')->json('data');
        $this->assertFalse($res['community_mentions']['on']);
        $this->assertTrue($res['community_mentions']['push']);               // phone choice defaults on, separately
        $this->assertTrue($res['community_likes']['on']);

        // Turning the phone choice back on removes only its own key
        $this->putJson('/api/v1/me/notification-settings', ['push' => ['community_likes' => true]])->assertOk();
        $this->assertArrayNotHasKey('push:community_likes', $user->fresh()->notification_preferences);
        $this->assertFalse($user->fresh()->notification_preferences['community_mentions']);
    }

    /* ---------------- Transactions ---------------- */

    public function test_a_like_rolled_back_leaves_no_notification_and_no_waiting_push()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $fan = $this->member('Brian Fan');

        try {
            DB::transaction(function () use ($post, $fan) {
                app(Community::class)->like($fan, $post->fresh(), true);
                throw new \RuntimeException('something later fails');
            });
        } catch (\RuntimeException $e) {
        }

        $this->assertSame(0, UserNotification::count());
        $this->assertSame(0, NotificationPush::count());
        $this->assertSame(0, DB::table('community_post_likes')->count());
        $this->assertSame([], $this->pushed);
    }
}
