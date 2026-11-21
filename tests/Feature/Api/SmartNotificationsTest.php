<?php

namespace Tests\Feature\Api;

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

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.webpush.public_key' => 'test-public-key', 'services.webpush.private_key' => 'test-private-key']);
        $this->pushed = $this->failing = $this->gone = [];
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
                    $request = new PsrRequest('POST', $endpoint);
                    if (in_array($endpoint, $this->gone, true)) {
                        yield new MessageSentReport($request, new PsrResponse(410), false, 'Gone');
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

    public function test_a_like_during_quiet_hours_waits_for_them_to_end_then_goes_once()
    {
        $this->smart();
        $author = $this->member('Grace Akello', ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->device($author);
        $this->travelTo(Carbon::parse('2026-11-22 20:30:00', 'UTC'));       // 23:30 in Kampala
        $post = $this->newPost($author);
        $this->likers($post, 1);

        $this->travel(20)->minutes();
        $this->assertSame(1, $this->flush()['held']);
        $this->assertSame([], $this->pushed);
        $row = NotificationPush::first();
        $this->assertSame('2026-11-23 04:00:00', $row->send_after->format('Y-m-d H:i:s'));
        $this->assertSame(0, $row->attempts);                                // waiting isn't an attempt
        $this->assertCount(1, $this->list($author)['data']);               // the bell has it all along

        $this->travelTo(Carbon::parse('2026-11-23 04:01:00', 'UTC'));
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertCount(1, $this->pushed);
        $this->assertSame('Alice P. liked your post.', $this->pushed[0]['payload']['body']);
        $this->assertSame('low', $this->pushed[0]['options']['urgency']);
        $this->flush();
        $this->assertCount(1, $this->pushed);
    }

    public function test_medium_priority_in_quiet_hours_becomes_one_summary_and_high_priority_is_never_held()
    {
        $this->smart();
        $user = $this->member('Grace Akello', ['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00']);
        $this->device($user);
        $this->travelTo(Carbon::parse('2026-11-22 21:00:00', 'UTC'));       // 00:00 in Kampala
        $notices = app(UserNotifications::class);

        Bus::fake();
        $notices->send('community_comments', [$user->id], ['title' => 'New comment', 'body' => 'Brian commented.', 'url' => '/community', 'tag' => 'a']);
        $notices->send('community_mentions', [$user->id], ['title' => 'You were mentioned', 'body' => 'Carol mentioned you.', 'url' => '/community', 'tag' => 'b']);
        Bus::assertNotDispatchedAfterResponse(SendUserNotification::class);
        $this->assertSame(2, UserNotification::where('user_id', $user->id)->count());
        $this->assertSame(1, NotificationPush::where('type', 'digest')->count());   // two notices, one summary

        // Bookings, messages and account notices go at once
        $notices->send('requests', [$user->id], ['title' => 'New request', 'body' => 'A customer asked for you.', 'url' => '/requests', 'tag' => 'c']);
        Bus::assertDispatchedAfterResponse(SendUserNotification::class, 1);
        $this->assertSame(3, UserNotification::where('user_id', $user->id)->count());

        $this->travelTo(Carbon::parse('2026-11-23 04:05:00', 'UTC'));
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertSame('You have 3 new notifications.', $this->pushed[0]['payload']['body']);
        $this->assertSame('/notifications', $this->pushed[0]['payload']['url']);
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

    public function test_a_single_like_is_pushed_after_the_window_not_at_once()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likers($this->newPost($author), 1);

        $this->assertSame([], $this->pushed);
        $this->assertSame(0, $this->flush()['sent']);                    // not due yet
        $this->travel(16)->minutes();
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertCount(1, $this->pushed);
        $this->assertSame('Your post was liked', $this->pushed[0]['payload']['title']);
        $this->assertSame('/community/post/'.CommunityPost::first()->uuid, $this->pushed[0]['payload']['url']);
        $this->assertSame(1, NotificationPush::where('status', 'sent')->count());
    }

    public function test_three_different_people_push_early_and_a_fourth_does_not_push_again()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->likers($post, 3);                                         // the third like pushes, without waiting

        $this->assertCount(1, $this->pushed);
        $this->assertSame('Carol P., Brian P. and 1 other liked your post.', $this->pushed[0]['payload']['body']);

        $this->likers($post, 1, 4);
        $this->travel(20)->minutes();
        $this->flush();
        $this->assertCount(1, $this->pushed);                            // within the three-hour cooldown
    }

    public function test_after_the_cooldown_more_likes_are_pushed_with_the_whole_count_and_a_day_has_a_limit()
    {
        $this->smart();
        $this->travelTo(Carbon::parse('2026-11-22 09:00:00', 'UTC'));
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->likers($post, 3);
        $this->assertCount(1, $this->pushed);

        $this->likers($post, 3, 4);                                      // more people, but too soon
        $this->flush();
        $this->assertCount(1, $this->pushed);
        $this->travel(3)->hours();
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertCount(2, $this->pushed);
        $this->assertStringContainsString('4 others', $this->pushed[1]['payload']['body']);   // 6 people: two named, four others

        // Two like pushes a day: a third, about another post, waits for tomorrow
        $other = $this->newPost($author);
        $this->likers($other, 3, 7);
        $this->assertCount(2, $this->pushed);
        $row = NotificationPush::where('status', 'pending')->first();
        $this->assertSame('daily limit', $row->note);
        $tomorrow = QuietHours::nextDayStart($author, now());
        $this->assertTrue($row->send_after->equalTo($tomorrow));
        $this->travelTo($tomorrow->copy()->addMinutes(1));
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertCount(3, $this->pushed);
    }

    public function test_nothing_is_pushed_for_what_was_read_or_taken_back_or_for_nobody_with_a_device()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $readPost = $this->newPost($author);
        $this->likers($readPost, 1);
        $this->signIn($author);
        $this->postJson('/api/v1/me/notifications/read')->assertOk();

        $gone = $this->newPost($author);
        $a = $this->likers($gone, 1, 2)[0];
        $this->unlike($a, $gone);

        $this->travel(20)->minutes();
        $stats = $this->flush();
        $this->assertSame(2, $stats['skipped']);
        $this->assertSame([], $this->pushed);
        $this->assertEqualsCanonicalizing(['already read', 'no one left'], NotificationPush::pluck('note')->all());

        $quiet = $this->member('No Device');
        $this->likers($this->newPost($quiet), 1, 3);
        $this->travel(20)->minutes();
        $this->flush();
        $this->assertSame('no devices', NotificationPush::where('user_id', $quiet->id)->value('note'));
    }

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

    public function test_a_stale_push_is_dropped_not_sent_a_day_late()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likers($this->newPost($author), 1);
        $this->travel(26)->hours();
        $this->assertSame(1, $this->flush()['skipped']);
        $this->assertSame([], $this->pushed);
    }

    /* ---------------- Claims, duplicates, retries ---------------- */

    public function test_the_same_push_cannot_be_made_or_claimed_twice()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $post = $this->newPost($author);
        $this->likers($post, 2);

        $row = UserNotification::first();
        app(PushDelivery::class)->scheduleGroup($row, NotificationPush::TYPE_LIKES, 2);
        app(PushDelivery::class)->scheduleGroup($row, NotificationPush::TYPE_LIKES, 2);
        $this->assertSame(1, NotificationPush::count());

        $this->travel(20)->minutes();
        $push = NotificationPush::first();
        $delivery = app(PushDelivery::class);
        // Another process holds it
        NotificationPush::whereKey($push->id)->update(['status' => 'sending', 'claimed_at' => now()]);
        $this->assertSame('busy', $delivery->process($push->id));
        NotificationPush::whereKey($push->id)->update(['status' => 'pending', 'claimed_at' => null]);

        $this->assertSame('sent', $delivery->process($push->id));
        $this->assertSame('busy', $delivery->process($push->id));          // already sent: nothing more
        $this->assertCount(1, $this->pushed);
    }

    public function test_a_push_no_device_accepts_is_retried_with_growing_waits_then_given_up()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $sub = $this->device($author);
        $this->failing = [$sub->endpoint];
        $this->likers($this->newPost($author), 1);
        $this->travel(16)->minutes();

        $this->assertSame(1, $this->flush()['retry']);                    // try 1, again in 1 minute
        $push = NotificationPush::first();
        $this->assertSame([1, 'pending'], [$push->attempts, $push->status]);
        $this->assertSame(0, $this->flush()['retry'] + $this->flush()['sent']);
        $this->travel(61)->seconds();
        $this->assertSame(1, $this->flush()['retry']);                    // try 2, in 5 minutes
        $this->travel(4)->minutes();
        $this->assertSame(0, $this->flush()['retry']);
        $this->travel(61)->seconds();
        $this->assertSame(1, $this->flush()['retry']);                    // try 3, in 30 minutes
        $this->travel(31)->minutes();
        $this->assertSame(1, $this->flush()['gave_up']);                  // try 4: no more
        $this->assertSame('gave_up', NotificationPush::first()->status);
        $this->assertCount(4, $this->pushed);
        $this->assertSame(4, $sub->fresh()->failure_count);
        $this->travel(1)->hours();
        $this->flush();
        $this->assertCount(4, $this->pushed);
    }

    public function test_a_retry_that_works_sends_exactly_once()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $sub = $this->device($author);
        $this->failing = [$sub->endpoint];
        $this->likers($this->newPost($author), 1);
        $this->travel(16)->minutes();
        $this->flush();

        $this->failing = [];
        $this->travel(61)->seconds();
        $this->assertSame(1, $this->flush()['sent']);
        $this->travel(1)->hours();
        $this->flush();
        $this->assertCount(2, $this->pushed);                              // the failed try and the one that arrived
        $this->assertSame(0, $sub->fresh()->failure_count);                // success clears the failures
    }

    public function test_one_device_accepting_it_is_enough_and_a_gone_device_is_removed()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $phone = $this->device($author, 'https://push.test/phone');
        $old = $this->device($author, 'https://push.test/old');
        $this->gone = [$old->endpoint];
        $this->likers($this->newPost($author), 1);
        $this->travel(16)->minutes();

        $this->assertSame(1, $this->flush()['sent']);
        $this->assertNull(PushSubscription::find($old->id));
        $this->assertNotNull(PushSubscription::find($phone->id));
        $this->assertSame(1, NotificationPush::where('status', 'sent')->value('devices_delivered'));
    }

    public function test_a_device_that_keeps_failing_is_forgotten()
    {
        $author = $this->member('Grace Akello');
        $sub = $this->device($author);
        $sub->forceFill(['failure_count' => 7])->save();
        $this->failing = [$sub->endpoint];

        app(\App\Services\PushNotifications::class)->deliverNow([$author->id], ['title' => 't', 'body' => 'b', 'url' => '/', 'tag' => 'x']);
        $this->assertNull(PushSubscription::find($sub->id));
    }

    public function test_a_claim_left_by_a_dead_process_is_made_ready_again()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likers($this->newPost($author), 1);
        $this->travel(16)->minutes();
        NotificationPush::query()->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(11), 'attempts' => 1]);

        $stats = $this->flush();
        $this->assertSame(1, $stats['recovered']);
        $this->assertSame(1, $stats['sent']);
        $this->assertCount(1, $this->pushed);

        // A claim that is only a minute old is left alone
        $this->likers($this->newPost($author), 1, 3);
        $this->travel(16)->minutes();
        NotificationPush::where('status', 'pending')->update(['status' => 'sending', 'claimed_at' => now()->subMinute()]);
        $this->assertSame(0, $this->flush()['recovered']);
    }

    public function test_one_push_per_person_per_run_so_a_long_night_is_not_a_burst()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likers($this->newPost($author), 1);
        $this->likers($this->newPost($author), 1, 2);
        $this->travel(20)->minutes();

        $this->assertSame(1, $this->flush()['sent']);
        $this->assertSame(1, $this->flush()['sent']);
        $this->assertCount(2, $this->pushed);
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

    public function test_turning_smart_push_off_drops_what_was_waiting_and_the_flush_command_runs()
    {
        $this->smart();
        $author = $this->member('Grace Akello');
        $this->device($author);
        $this->likers($this->newPost($author), 1);
        $this->smart(false);
        $this->travel(20)->minutes();

        $this->artisan('workmate:notifications-flush')->assertExitCode(0);
        $this->assertSame([], $this->pushed);
        $this->assertSame('smart push off', NotificationPush::first()->note);
        $this->assertNotNull(\Cache::get('notifications:flush:last_run'));
        $this->artisan('workmate:notifications-status')->assertExitCode(0);
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
