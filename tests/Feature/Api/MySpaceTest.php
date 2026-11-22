<?php

namespace Tests\Feature\Api;

use App\Models\CommunityPost;
use App\Models\User;
use App\Services\Community;
use App\Services\CommunityPeople;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * My Space: a member's own community numbers (GET /me/community/stats) and own posts (GET /me/community/posts).
 */
class MySpaceTest extends ApiTestCase
{
    private function member(string $name = 'Grace Akello'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    /** A published post; $kind: 'text', 'photo' or 'video' */
    private function newPost(User $author, string $kind = 'text', string $category = 'tips'): CommunityPost
    {
        $p = new CommunityPost();
        $p->forceFill(['uuid' => (string) Str::uuid(), 'user_id' => $author->id, 'category' => $category, 'body' => 'A '.$kind.' post'])->save();
        if ($kind !== 'text') {
            DB::table('community_post_media')->insert([
                'community_post_id' => $p->id, 'uuid' => (string) Str::uuid(), 'type' => $kind, 'path' => 'x', 'thumb_path' => 'y', 'width' => 1, 'height' => 1,
                'position' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $p;
    }

    private function stats(User $user, string $query = ''): array
    {
        $this->signIn($user);

        return $this->getJson('/api/v1/me/community/stats'.$query)->assertOk()->json('data');
    }

    private function mine(User $user, string $query = ''): array
    {
        $this->signIn($user);

        return $this->getJson('/api/v1/me/community/posts'.$query)->assertOk()->json();
    }

    /* ---------------- Access ---------------- */

    public function test_both_endpoints_need_signing_in_and_an_active_account()
    {
        $this->getJson('/api/v1/me/community/stats')->assertUnauthorized();
        $this->getJson('/api/v1/me/community/posts')->assertUnauthorized();

        $user = $this->member();
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->signIn($user);
        $this->assertContains($this->getJson('/api/v1/me/community/stats')->getStatusCode(), [401, 403]);
        $this->assertContains($this->getJson('/api/v1/me/community/posts')->getStatusCode(), [401, 403]);
    }

    /* ---------------- The numbers ---------------- */

    public function test_the_summary_counts_real_published_content_and_videos_are_a_subset_of_posts()
    {
        $me = $this->member();
        $this->newPost($me, 'text');
        $this->newPost($me, 'photo');
        $this->newPost($me, 'video');
        $this->newPost($me, 'video');
        $removed = $this->newPost($me, 'video');
        $removed->forceFill(['status' => CommunityPost::STATUS_REMOVED])->save();
        $this->newPost($me, 'text')->delete();                                         // deleted: gone

        $s = $this->stats($me);
        $this->assertSame(['posts', 'videos', 'likes_received', 'followers'], array_keys($s));
        $this->assertSame(4, $s['posts']);                                          // text, photo and two videos (removed and deleted are not counted)
        $this->assertSame(2, $s['videos']);                                         // a subset of those four
        $this->assertLessThanOrEqual($s['posts'], $s['videos']);
        $this->assertSame(0, $s['likes_received']);
        $this->assertSame(0, $s['followers']);
    }

    public function test_a_member_with_nothing_gets_zeros_not_missing_numbers()
    {
        $this->assertSame(['posts' => 0, 'videos' => 0, 'likes_received' => 0, 'followers' => 0], $this->stats($this->member()));
    }

    public function test_likes_received_are_other_peoples_and_only_on_posts_that_still_count()
    {
        $me = $this->member();
        $a = $this->member('Alice Person');
        $b = $this->member('Brian Person');
        $p1 = $this->newPost($me);
        $p2 = $this->newPost($me, 'video');
        $gone = $this->newPost($me);
        $c = app(Community::class);
        $c->like($a, $p1, true);
        $c->like($b, $p1, true);
        $c->like($a, $p2, true);
        $c->like($me, $p1, true);                                                    // liking your own post is activity, not audience
        $c->like($a, $gone, true);
        $gone->forceFill(['status' => CommunityPost::STATUS_REMOVED])->save();      // a removed post's likes are not counted
        $other = $this->newPost($a);
        $c->like($b, $other, true);                                                  // someone else's post

        $this->assertSame(3, $this->stats($me)['likes_received']);
        $this->assertSame(1, $this->stats($a)['likes_received']);                    // and only their own audience
    }

    public function test_followers_and_posts_match_the_public_profile_exactly()
    {
        $me = $this->member();
        $this->newPost($me);
        $this->newPost($me, 'video');
        $fans = [$this->member('Fan One'), $this->member('Fan Two'), $this->member('Fan Three')];
        foreach ($fans as $f) {
            app(CommunityPeople::class)->follow($f, $me, true);
        }
        $this->newPost($fans[0]);                                                     // (people follow members who are in the community)
        app(CommunityPeople::class)->follow($me, $fans[0], true);
        $fans[2]->delete();                                                           // an account that no longer exists stops counting

        $mine = $this->stats($me, '?detail=1');
        $public = $this->getJson('/api/v1/community/people/'.$me->id)->assertOk()->json('data');
        $this->assertSame($public['followers_count'], $mine['audience']['followers']);
        $this->assertSame($public['following_count'], $mine['activity']['following']);
        $this->assertSame($public['posts_count'], $mine['audience']['posts']);
        $this->assertSame(2, $mine['audience']['followers']);
    }

    public function test_the_detail_view_separates_audience_from_my_own_activity()
    {
        $me = $this->member();
        $a = $this->member('Alice Person');
        $post = $this->newPost($me, 'video');
        $c = app(Community::class);
        $c->comment($a, $post, 'Nice one');
        $reply = $c->comment($me, $post, 'Thanks!', null);                            // the owner replying: activity, not audience
        $hidden = $c->comment($a, $post, 'Spam');
        $c->deleteComment($a, $hidden);                                               // deleted by its writer: not counted
        $c->save($a, $post, true);
        $c->save($me, $post, true);                                             // saving your own post: not audience
        $c->like($a, $post, true);
        $theirs = $this->newPost($a);
        $c->like($me, $theirs, true);
        $c->comment($me, $theirs, 'Great');
        $c->save($me, $theirs, true);
        DB::table('saved_providers')->insert(['user_id' => $me->id, 'provider_profile_id' => $this->providerId(), 'created_at' => now()]);

        $d = $this->stats($me, '?detail=1');
        $this->assertSame(['activity', 'audience'], collect($d)->keys()->sort()->values()->all());
        $this->assertSame(['comments_received', 'followers', 'likes_received', 'posts', 'saves_received', 'videos'], collect($d['audience'])->keys()->sort()->values()->all());
        $this->assertSame(['comments_written', 'following', 'likes_given', 'posts_saved', 'providers_saved'], collect($d['activity'])->keys()->sort()->values()->all());
        $this->assertSame(1, $d['audience']['comments_received']);                    // Alice's one comment
        $this->assertSame(1, $d['audience']['saves_received']);                       // Alice's save
        $this->assertSame(1, $d['audience']['likes_received']);
        $this->assertSame(1, $d['activity']['likes_given']);
        $this->assertSame(2, $d['activity']['comments_written']);                     // "Thanks!" and "Great"
        $this->assertSame(2, $d['activity']['posts_saved']);
        $this->assertSame(1, $d['activity']['providers_saved']);
    }

    private function providerId(): int
    {
        $owner = $this->member('Okello Owner');
        $service = app(\App\Services\ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);

        return $owner->providerProfile()->first()->id;
    }

    public function test_someone_elses_content_never_changes_my_numbers()
    {
        $me = $this->member();
        $other = $this->member('Brian Other');
        $this->newPost($other, 'video');
        $this->newPost($other, 'text');
        app(Community::class)->like($me, $this->newPost($other), true);
        $this->assertSame(['posts' => 0, 'videos' => 0, 'likes_received' => 0, 'followers' => 0], $this->stats($me));
        $this->assertSame(3, $this->stats($other)['posts']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_amount_of_content()
    {
        Bus::fake();                                                                  // (the test app would otherwise run notification jobs inside the measured request)
        $me = $this->member();
        $c = app(Community::class);
        $fans = [$this->member('Alice Person'), $this->member('Brian Person')];
        $count = function (string $q) use ($me) {
            $this->signIn($me);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/me/community/stats'.$q)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $one = $this->newPost($me, 'video');
        $c->like($fans[0], $one, true);
        $few = [$count(''), $count('?detail=1')];

        foreach (range(1, 25) as $i) {
            $p = $this->newPost($me, $i % 2 ? 'video' : 'text');
            foreach ($fans as $f) {
                $c->like($f, $p, true);
                $c->comment($f, $p, 'c'.$i);
            }
        }
        $this->assertSame($few[0], $count(''));
        $this->assertSame($few[1], $count('?detail=1'));
        $this->assertLessThanOrEqual(8, $few[0]);
        $this->assertLessThanOrEqual(14, $few[1]);
    }

    /* ---------------- Own posts ---------------- */

    public function test_my_posts_are_only_mine_newest_first_and_carry_what_the_cards_need()
    {
        $me = $this->member();
        $other = $this->member('Brian Other');
        $old = $this->newPost($me);
        $this->travel(5)->seconds();
        $new = $this->newPost($me, 'video');
        $this->newPost($other);

        $r = $this->mine($me);
        $this->assertSame([$new->uuid, $old->uuid], array_column($r['data'], 'id'));
        $this->assertTrue($r['data'][0]['mine']);
        $this->assertNull($r['meta']['next_cursor']);
        $this->assertSame([], $this->mine($this->member('New Person'))['data']);
    }

    public function test_the_videos_filter_returns_the_same_posts_not_copies()
    {
        $me = $this->member();
        $text = $this->newPost($me);
        $photo = $this->newPost($me, 'photo');
        $video = $this->newPost($me, 'video');

        $all = array_column($this->mine($me)['data'], 'id');
        $videos = array_column($this->mine($me, '?type=video')['data'], 'id');
        $this->assertSame([$video->uuid], $videos);
        $this->assertCount(3, $all);                                                   // the video also appears in All, as the very same post
        $this->assertContains($video->uuid, $all);
        $this->assertCount(3, array_unique($all));
        $this->assertSame([$video->uuid, $photo->uuid, $text->uuid], $all);
    }

    public function test_removed_posts_show_to_their_owner_with_the_reason_and_never_to_anyone_else()
    {
        $me = $this->member();
        $other = $this->member('Brian Other');
        $mineRemoved = $this->newPost($me);
        $mineRemoved->forceFill(['status' => CommunityPost::STATUS_REMOVED, 'removed_reason' => 'Rules were broken', 'removed_at' => now()])->save();
        $this->newPost($me);
        $theirs = $this->newPost($other);
        $theirs->forceFill(['status' => CommunityPost::STATUS_REMOVED, 'removed_reason' => 'Their secret reason', 'removed_at' => now()])->save();

        $r = $this->mine($me);
        $this->assertCount(2, $r['data']);
        $removed = collect($r['data'])->firstWhere('id', $mineRemoved->uuid);
        $this->assertSame('Rules were broken', $removed['removed']['reason']);
        $this->assertStringNotContainsString('Their secret reason', json_encode($r));

        $this->assertSame([$mineRemoved->uuid], array_column($this->mine($me, '?status=removed')['data'], 'id'));
        $this->assertCount(1, $this->mine($me, '?status=published')['data']);
        $this->assertSame([], $this->mine($me, '?type=video&status=removed')['data']);
    }

    public function test_deleted_posts_stay_deleted_and_the_category_filter_works()
    {
        $me = $this->member();
        $tip = $this->newPost($me, 'text', 'tips');
        $work = $this->newPost($me, 'text', 'my_work');
        $this->newPost($me)->delete();

        $this->assertCount(2, $this->mine($me)['data']);
        $this->assertSame([$work->uuid], array_column($this->mine($me, '?category=my_work')['data'], 'id'));
        $this->assertSame([$tip->uuid], array_column($this->mine($me, '?category=tips')['data'], 'id'));
    }

    public function test_pages_of_ten_follow_each_other_without_repeats_or_gaps()
    {
        $me = $this->member();
        $made = [];
        foreach (range(1, 23) as $i) {
            $made[] = $this->newPost($me)->uuid;
            $this->travel(1)->seconds();
        }
        $this->signIn($me);
        $seen = [];
        $query = '';
        for ($page = 0; $page < 5; $page++) {
            $r = $this->getJson('/api/v1/me/community/posts'.$query)->assertOk()->json();
            $this->assertLessThanOrEqual(10, count($r['data']));
            $seen = array_merge($seen, array_column($r['data'], 'id'));
            if (! $r['meta']['next_cursor']) {
                break;
            }
            $query = '?cursor='.urlencode($r['meta']['next_cursor']);
        }
        $this->assertSame(array_reverse($made), $seen);                                 // every post once, newest first
        $this->assertSame(3, $page + 1);
    }

    public function test_bad_filters_are_refused()
    {
        $this->signIn($this->member());
        $this->getJson('/api/v1/me/community/posts?type=music')->assertStatus(422);
        $this->getJson('/api/v1/me/community/posts?status=secret')->assertStatus(422);
        $this->getJson('/api/v1/me/community/posts?category=nope')->assertStatus(422);
        $this->getJson('/api/v1/me/community/stats?detail=maybe')->assertStatus(422);
    }

    public function test_my_posts_costs_the_same_number_of_queries_for_two_posts_or_ten()
    {
        Bus::fake();
        $me = $this->member();
        $fan = $this->member('Alice Person');
        $count = function () use ($me) {
            $this->signIn($me);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/me/community/posts')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach ([0, 1] as $i) {
            $p = $this->newPost($me, 'video');
            app(Community::class)->like($fan, $p, true);
        }
        $two = $count();
        foreach (range(1, 8) as $i) {
            $p = $this->newPost($me, $i % 2 ? 'photo' : 'text');
            app(Community::class)->like($fan, $p, true);
        }
        $this->assertSame($two, $count());
    }

    /* ---------------- Managing only my own ---------------- */

    public function test_editing_or_deleting_someone_elses_post_is_refused()
    {
        $me = $this->member();
        $other = $this->member('Brian Other');
        $theirs = $this->newPost($other);
        $this->signIn($me);
        $this->deleteJson('/api/v1/community/posts/'.$theirs->uuid)->assertNotFound();
        $this->patchJson('/api/v1/community/posts/'.$theirs->uuid, ['body' => 'hijacked'])->assertNotFound();
        $this->assertSame('A text post', CommunityPost::find($theirs->id)->body);

        // and my own delete shows in my numbers straight away
        $mine = $this->newPost($me);
        $this->assertSame(1, $this->stats($me)['posts']);
        $this->deleteJson('/api/v1/community/posts/'.$mine->uuid)->assertOk();
        $this->assertSame(0, $this->stats($me)['posts']);
        $this->assertSame([], $this->mine($me)['data']);
    }
}
