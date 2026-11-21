<?php

namespace Tests\Feature\Api;

use App\Models\CommunityComment;
use App\Models\CommunityMention;
use App\Models\CommunityPost;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AuditLogger;
use App\Services\CommunityMentions;
use App\Services\UserNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CommunityMentionsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /* ---------------- Helpers ---------------- */

    /** A member who has taken part (a published post), so they can be mentioned */
    private function participant(string $name, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['name' => $name]);
        $post = new CommunityPost();
        $post->forceFill(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'category' => 'tips', 'body' => 'Hello from '.$name])->save();

        return $user;
    }

    /** An approved provider listed as $business */
    private function provider(string $name, string $business): User
    {
        $user = $this->signIn(User::factory()->create(['name' => $name]));
        $this->putJson('/api/v1/me/provider', [
            'type' => 'individual', 'display_name' => $business, 'professions' => [['profession' => 'electrician']],
        ])->assertOk();
        $user->providerProfile->forceFill(['status' => 'approved', 'document_verified_at' => now()])->save();

        return $user->fresh();
    }

    private static function token(User $user): string
    {
        return '@[u:'.$user->id.']';
    }

    private function publish(string $body, array $extra = [])
    {
        $this->travel(21)->seconds(); // past the three-a-minute posting limit

        return $this->postJson('/api/v1/community/posts', $extra + ['category' => 'tips', 'body' => $body]);
    }

    private function mentionNotices(User $user)
    {
        return UserNotification::where('user_id', $user->id)->where('kind', 'community_mentions')->get();
    }

    /** The text of each mention, as the app cuts it out (UTF-16 positions) */
    private static function cut(string $body, array $mentions): array
    {
        $utf16 = mb_convert_encoding($body, 'UTF-16LE', 'UTF-8');

        return array_map(function ($m) use ($utf16) {
            return mb_convert_encoding(substr($utf16, $m['start'] * 2, $m['length'] * 2), 'UTF-8', 'UTF-16LE');
        }, $mentions);
    }

    /* ---------------- Posts and captions ---------------- */

    public function test_a_post_mentions_people_by_account_and_they_are_told()
    {
        $brian = $this->provider('Brian Kato', 'Kato Electricals');
        $sarah = $this->participant('Sarah Namuli');
        $author = $this->signIn($this->participant('Grace Akello'));

        $data = $this->publish(self::token($brian).', can you help? Great work by '.self::token($sarah).'.')
            ->assertCreated()->json('data');

        // Names filled in by the server; members show as "Sarah N."
        $this->assertSame('@Kato Electricals, can you help? Great work by @Sarah N..', $data['body']);
        $this->assertSame(['@Kato Electricals', '@Sarah N.'], self::cut($data['body'], $data['mentions']));
        $this->assertSame($brian->id, $data['mentions'][0]['user_id']);
        $this->assertSame($brian->providerProfile->id, $data['mentions'][0]['provider_id']);
        $this->assertNull($data['mentions'][1]['provider_id']);
        $this->assertTrue($data['mentions'][1]['linkable']);
        $this->assertSame(2, CommunityMention::count());

        $notice = $this->mentionNotices($sarah)->first();
        $this->assertSame('You were mentioned', $notice->title);
        $this->assertSame('Grace A. mentioned you in a post.', $notice->body);
        $this->assertSame('/community/post/'.$data['id'], $notice->url);
        $this->assertCount(1, $this->mentionNotices($brian));
        $this->assertCount(0, $this->mentionNotices($author));

        // Everyone reading sees the mentions, with no account details
        $this->app['auth']->forgetGuards();
        $json = $this->getJson('/api/v1/community/posts/'.$data['id'])->assertOk()->json('data');
        $this->assertSame($data['mentions'], $json['mentions']);
        $this->assertStringNotContainsString($sarah->email, json_encode($json));
        $this->assertSame(2, count($this->getJson('/api/v1/community/posts?tab=latest')->json('data.0.mentions')));
    }

    public function test_a_mention_keeps_pointing_at_the_same_account_after_a_change_of_name()
    {
        $sarah = $this->participant('Sarah Namuli');
        $this->signIn($this->participant('Grace Akello'));
        $id = $this->publish('Thanks '.self::token($sarah))->assertCreated()->json('data.id');

        $sarah->forceFill(['name' => 'Sarah Kintu'])->save();
        $json = $this->getJson('/api/v1/community/posts/'.$id)->json('data');
        $this->assertSame('Thanks @Sarah N.', $json['body']);
        $this->assertSame($sarah->id, $json['mentions'][0]['user_id']);
    }

    public function test_the_same_person_twice_counts_once_and_is_told_once()
    {
        $sarah = $this->participant('Sarah Namuli');
        $this->signIn($this->participant('Grace Akello'));
        $data = $this->publish(self::token($sarah).' and again '.self::token($sarah))->assertCreated()->json('data');

        $this->assertCount(2, $data['mentions']);
        $this->assertCount(1, $this->mentionNotices($sarah));
    }

    public function test_up_to_five_people_and_no_more()
    {
        $people = collect(range(1, 6))->map(function ($i) {
            return $this->participant('Person '.$i);
        });
        $this->signIn($this->participant('Grace Akello'));

        $five = $people->take(5)->map(function ($p) {
            return self::token($p);
        })->implode(' ');
        $this->publish($five.' '.self::token($people[0]))->assertCreated();

        $this->publish($five.' '.self::token($people[5]))->assertStatus(422)->assertJsonPath('code', 'too_many_mentions');
        $this->assertSame(1 + 7, CommunityPost::count());
    }

    public function test_any_active_account_can_be_mentioned_but_not_missing_suspended_or_deleted_ones()
    {
        $this->signIn($this->participant('Grace Akello'));
        $quiet = User::factory()->create(['name' => 'Never Posted']);
        $this->publish('Welcome '.self::token($quiet))->assertCreated()->assertJsonCount(1, 'data.mentions');
        $this->assertCount(1, $this->mentionNotices($quiet));

        $suspended = $this->participant('Suspended Person', ['status' => User::STATUS_SUSPENDED]);
        $deleted = $this->participant('Deleted Person');
        $deleted->delete();
        $before = CommunityPost::count();
        $mentionsBefore = CommunityMention::count();

        foreach ([999999, $suspended->id, $deleted->id] as $id) {
            $this->publish('Hello @[u:'.$id.']')->assertStatus(422)->assertJsonPath('code', 'mention_not_allowed');
        }
        $this->assertSame($before, CommunityPost::count());
        $this->assertSame($mentionsBefore, CommunityMention::count());
        $this->assertSame(1, UserNotification::where('kind', 'community_mentions')->count());
    }

    public function test_mentioning_yourself_is_allowed_but_not_notified()
    {
        $me = $this->signIn($this->participant('Grace Akello'));
        $data = $this->publish('Note to '.self::token($me))->assertCreated()->json('data');

        $this->assertSame('Note to @Grace A.', $data['body']);
        $this->assertCount(1, $data['mentions']);
        $this->assertCount(0, $this->mentionNotices($me));
    }

    public function test_plain_text_with_at_signs_is_just_text()
    {
        $sarah = $this->participant('Sarah Namuli');
        $this->signIn($this->participant('Grace Akello'));

        // Typed, not chosen; an email address; broken and oversized tokens; a forged list of ids
        $data = $this->publish('@Sarah N. thanks. Write to me@example.com. @[u:] @[u:abc] @[u:12345678901]', ['mentions' => [$sarah->id]])
            ->assertCreated()->json('data');

        $this->assertSame([], $data['mentions']);
        $this->assertSame('@Sarah N. thanks. Write to me@example.com. @[u:] @[u:abc] @[u:12345678901]', $data['body']);
        $this->assertCount(0, $this->mentionNotices($sarah));
    }

    public function test_markup_stays_text_and_positions_count_like_browsers_do()
    {
        $sarah = $this->participant('Sarah Namuli');
        $this->signIn($this->participant('Grace Akello'));

        // An emoji with a skin tone is 4 UTF-16 units; markup is stored as typed and never turned into HTML
        $data = $this->publish("👍🏽 <script>alert(1)</script>\n".self::token($sarah).' <a href="javascript:x">ok</a>')->assertCreated()->json('data');
        $this->assertSame("👍🏽 <script>alert(1)</script>\n@Sarah N. <a href=\"javascript:x\">ok</a>", $data['body']);
        $this->assertSame(5 + 25 + 1, $data['mentions'][0]['start']);
        $this->assertSame(['@Sarah N.'], self::cut($data['body'], $data['mentions']));
    }

    public function test_names_filled_in_still_have_to_fit()
    {
        $long = $this->provider('Long Name', str_repeat('Kampala Electrical Works ', 2).'Ltd');
        $this->signIn($this->participant('Grace Akello'));
        $text = str_repeat('a', CommunityPost::MAX_BODY - 10).self::token($long);

        $this->publish($text)->assertStatus(422)->assertJsonPath('code', 'text_too_long');
    }

    /* ---------------- Editing ---------------- */

    public function test_editing_tells_only_people_newly_mentioned_and_never_twice()
    {
        $sarah = $this->participant('Sarah Namuli');
        $brian = $this->participant('Brian Kato');
        $this->signIn($this->participant('Grace Akello'));
        $id = $this->publish('Thanks '.self::token($sarah))->assertCreated()->json('data.id');
        $this->assertCount(1, $this->mentionNotices($sarah));

        // Sarah kept, Brian added: only Brian is told
        $data = $this->patchJson('/api/v1/community/posts/'.$id, ['body' => 'Thanks '.self::token($sarah).' and '.self::token($brian)])->assertOk()->json('data');
        $this->assertSame(['@Sarah N.', '@Brian K.'], self::cut($data['body'], $data['mentions']));
        $this->assertCount(1, $this->mentionNotices($sarah));
        $this->assertCount(1, $this->mentionNotices($brian));

        // Removing a mention removes it; adding it back doesn't tell them again
        $data = $this->patchJson('/api/v1/community/posts/'.$id, ['body' => 'Thanks Sarah'])->assertOk()->json('data');
        $this->assertSame([], $data['mentions']);
        $this->assertSame(0, CommunityMention::count());
        $this->patchJson('/api/v1/community/posts/'.$id, ['body' => 'Thanks '.self::token($sarah)])->assertOk();
        $this->assertCount(1, $this->mentionNotices($sarah));
        $this->assertSame(1, CommunityMention::count());

        // Changing only the kind of post leaves the mentions alone
        $this->patchJson('/api/v1/community/posts/'.$id, ['category' => 'experience'])->assertOk()->assertJsonCount(1, 'data.mentions');
    }

    public function test_no_one_else_can_change_a_posts_mentions()
    {
        $sarah = $this->participant('Sarah Namuli');
        $brian = $this->participant('Brian Kato');
        $this->signIn($this->participant('Grace Akello'));
        $id = $this->publish('Thanks '.self::token($sarah))->assertCreated()->json('data.id');

        $this->signIn($this->participant('Someone Else'));
        $this->patchJson('/api/v1/community/posts/'.$id, ['body' => self::token($brian)])->assertNotFound();
        $this->assertSame([$sarah->id], CommunityMention::pluck('user_id')->all());
        $this->assertCount(0, $this->mentionNotices($brian));
    }

    /* ---------------- Comments and replies ---------------- */

    public function test_comments_and_replies_mention_people_and_open_at_that_comment()
    {
        $owner = $this->participant('Post Owner');
        $sarah = $this->participant('Sarah Namuli');
        $brian = $this->participant('Brian Kato');
        $post = CommunityPost::where('user_id', $owner->id)->first();

        $grace = $this->signIn($this->participant('Grace Akello'));
        $comment = $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'Ask '.self::token($sarah).', she knows'])
            ->assertCreated()->json('data');
        $this->assertSame('Ask @Sarah N., she knows', $comment['body']);
        $this->assertSame(['@Sarah N.'], self::cut($comment['body'], $comment['mentions']));
        $notice = $this->mentionNotices($sarah)->first();
        $this->assertSame('Grace A. mentioned you in a comment.', $notice->body);
        $this->assertSame('/community/post/'.$post->uuid.'#comment-'.$comment['id'], $notice->url);

        // A reply mentioning Brian, and Grace (whose comment it answers: she hears about the reply only)
        $this->signIn($sarah);
        $reply = $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => self::token($brian).' '.self::token($grace).' agreed', 'parent' => $comment['id']])
            ->assertCreated()->json('data');
        $this->assertSame('Sarah N. mentioned you in a reply.', $this->mentionNotices($brian)->first()->body);
        $this->assertSame('/community/post/'.$post->uuid.'#comment-'.$reply['id'], $this->mentionNotices($brian)->first()->url);
        $this->assertCount(0, $this->mentionNotices($grace));
        $this->assertSame(1, UserNotification::where('user_id', $grace->id)->where('title', 'New reply')->count());

        // The post's owner, mentioned in a comment on their post, gets just the comment notice
        $this->signIn($brian);
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => 'Nice one '.self::token($owner)])->assertCreated();
        $this->assertCount(0, $this->mentionNotices($owner));
        $this->assertSame(1, UserNotification::where('user_id', $owner->id)->where('kind', 'community_comments')->where('body', 'like', 'Brian K.%')->count());

        // The comments list carries the mentions
        $list = $this->getJson("/api/v1/community/posts/{$post->uuid}/comments")->assertOk()->json('data');
        $this->assertSame(['@Sarah N.'], self::cut($list[0]['body'], $list[0]['mentions']));
        $this->assertSame(['@Brian K.', '@Grace A.'], self::cut($list[0]['replies'][0]['body'], $list[0]['replies'][0]['mentions']));
    }

    public function test_a_comment_with_too_many_or_invalid_mentions_is_refused()
    {
        $post = CommunityPost::where('user_id', $this->participant('Post Owner')->id)->first();
        $people = collect(range(1, 6))->map(function ($i) {
            return self::token($this->participant('Person '.$i));
        });
        $this->signIn($this->participant('Grace Akello'));

        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => $people->implode(' ')])->assertStatus(422)->assertJsonPath('code', 'too_many_mentions');
        $this->postJson("/api/v1/community/posts/{$post->uuid}/comments", ['body' => '@[u:999999] hi'])->assertStatus(422)->assertJsonPath('code', 'mention_not_allowed');
        $this->assertSame(0, CommunityComment::count());
    }

    /* ---------------- Opening one comment from a notification ---------------- */

    private function addComment(CommunityPost $post, User $by, string $body, ?int $parent = null): CommunityComment
    {
        $c = new CommunityComment();
        $c->forceFill(['community_post_id' => $post->id, 'user_id' => $by->id, 'parent_id' => $parent, 'body' => $body])->save();

        return $c;
    }

    public function test_a_comment_far_down_a_long_list_is_found_with_its_conversation_only()
    {
        $owner = $this->participant('Post Owner');
        $post = CommunityPost::where('user_id', $owner->id)->first();
        $other = $this->participant('Other Person');
        foreach (range(1, 40) as $i) {
            $this->addComment($post, $other, 'Filler '.$i);
        }
        $top = $this->addComment($post, $other, 'The one');
        $reply = $this->addComment($post, $other, 'A reply', $top->id);
        $this->addComment($post, $other, 'Later filler');
        $hidden = $this->addComment($post, $other, 'Hidden reply', $top->id);
        $hidden->forceFill(['status' => CommunityComment::STATUS_REMOVED])->save();

        $this->signIn($this->participant('Reader'));
        foreach ([$top->id, $reply->id] as $id) {
            $data = $this->getJson("/api/v1/community/comments/{$id}/thread")->assertOk()
                ->assertJsonPath('data.id', $top->id)->assertJsonPath('meta.post', $post->uuid)->json('data');
            $this->assertSame(['A reply'], array_column($data['replies'], 'body'));
        }
    }

    public function test_a_thread_is_not_shown_for_hidden_deleted_or_unreadable_content()
    {
        $owner = $this->participant('Post Owner');
        $post = CommunityPost::where('user_id', $owner->id)->first();
        $other = $this->participant('Other Person');
        $comment = $this->addComment($post, $other, 'Hello');
        $this->signIn($this->participant('Reader'));

        $this->getJson('/api/v1/community/comments/999999/thread')->assertNotFound();

        $comment->forceFill(['status' => CommunityComment::STATUS_REMOVED])->save();
        $this->getJson("/api/v1/community/comments/{$comment->id}/thread")->assertNotFound();
        $comment->forceFill(['status' => CommunityComment::STATUS_PUBLISHED])->save();
        $this->getJson("/api/v1/community/comments/{$comment->id}/thread")->assertOk();

        $comment->delete();
        $this->getJson("/api/v1/community/comments/{$comment->id}/thread")->assertNotFound();
        $comment->restore();

        // The post itself is removed or its author is no longer active: no one else can open it
        $post->forceFill(['status' => CommunityPost::STATUS_REMOVED])->save();
        $this->getJson("/api/v1/community/comments/{$comment->id}/thread")->assertNotFound();
        $post->forceFill(['status' => CommunityPost::STATUS_PUBLISHED])->save();
        $owner->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->getJson("/api/v1/community/comments/{$comment->id}/thread")->assertNotFound();
    }

    /* ---------------- Who is told, and when ---------------- */

    public function test_nothing_is_kept_or_sent_when_saving_fails()
    {
        $sarah = $this->participant('Sarah Namuli');
        $this->signIn($this->participant('Grace Akello'));
        $before = CommunityPost::count();

        // The audit log fails as the post is saved: everything is rolled back
        $audit = \Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('audit down'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->publish('Thanks '.self::token($sarah))->assertStatus(500);
        $this->assertSame($before, CommunityPost::count());
        $this->assertSame(0, CommunityMention::count());
        $this->assertSame(0, DB::table('community_mention_alerts')->count());
        $this->assertCount(0, $this->mentionNotices($sarah));
    }

    public function test_nothing_is_sent_about_content_that_can_no_longer_be_seen()
    {
        $sarah = $this->participant('Sarah Namuli');
        $author = $this->participant('Grace Akello');
        $post = CommunityPost::where('user_id', $author->id)->first();
        $post->forceFill(['status' => CommunityPost::STATUS_REMOVED])->save();

        app(CommunityMentions::class)->deliver(['type' => 'post', 'id' => $post->id, 'post_id' => $post->id, 'author_id' => $author->id, 'user_ids' => [$sarah->id]]);
        $this->assertCount(0, $this->mentionNotices($sarah));
    }

    public function test_people_who_turned_mentions_off_are_not_told()
    {
        $sarah = $this->participant('Sarah Namuli');
        app(UserNotifications::class)->choose($sarah, ['community_mentions' => false]);
        $this->signIn($this->participant('Grace Akello'));

        $this->publish('Thanks '.self::token($sarah))->assertCreated()->assertJsonCount(1, 'data.mentions');
        $this->assertCount(0, $this->mentionNotices($sarah));
        $this->assertArrayHasKey('community_mentions', app(UserNotifications::class)->choices($sarah));
    }

    public function test_mentioning_the_same_person_over_and_over_stops_notifying()
    {
        $sarah = $this->participant('Sarah Namuli');
        $this->signIn($this->participant('Grace Akello'));
        foreach (range(1, 5) as $i) {
            $this->publish('Post '.$i.' for '.self::token($sarah))->assertCreated();
        }

        // Three an hour from the same person; the mentions still show
        $this->assertCount(3, $this->mentionNotices($sarah));
        $this->assertSame(5, CommunityMention::where('user_id', $sarah->id)->count());
    }

    public function test_followers_who_are_mentioned_hear_about_the_mention_only()
    {
        $sarah = $this->participant('Sarah Namuli');
        $grace = $this->participant('Grace Akello');
        DB::table('follows')->insert(['follower_id' => $sarah->id, 'followed_id' => $grace->id, 'created_at' => now()]);
        $this->signIn($grace);

        $this->publish('Thanks '.self::token($sarah))->assertCreated();
        $this->assertCount(1, $this->mentionNotices($sarah));
        $this->assertSame(0, UserNotification::where('user_id', $sarah->id)->where('kind', 'community_posts')->count());
    }

    /* ---------------- Suggestions while typing ---------------- */

    public function test_suggestions_match_the_names_people_see()
    {
        $this->provider('Brian Kato', 'Kato Electricals');
        $sarah = $this->participant('Sarah Namuli');
        $this->participant('Sarah Nakato');
        User::factory()->create(['name' => 'Sarah Quiet']);                 // never posted: still mentionable
        $this->participant('Sarah Away', ['status' => User::STATUS_SUSPENDED]);
        $me = $this->signIn($this->participant('Sarah Me'));

        $found = $this->getJson('/api/v1/community/mention-suggestions?q=sar')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Sarah N.', 'Sarah N.', 'Sarah Q.'], array_column($found, 'name'));
        $this->assertNotContains($me->id, array_column($found, 'id'));

        // Two people with the same name: the one they follow comes first, and says so
        DB::table('follows')->insert(['follower_id' => $me->id, 'followed_id' => $sarah->id, 'created_at' => now()]);
        $found = $this->getJson('/api/v1/community/mention-suggestions?q=sarah')->json('data');
        $this->assertSame([$sarah->id, true], [$found[0]['id'], $found[0]['following']]);
        $this->assertFalse($found[1]['following']);

        // Case and spaces; providers by their business name, with their profession and tick
        $kato = $this->getJson('/api/v1/community/mention-suggestions?q='.urlencode('KATO elec'))->json('data');
        $this->assertSame('Kato Electricals', $kato[0]['name']);
        $this->assertSame('Electrician', $kato[0]['title']);
        $this->assertTrue($kato[0]['document_verified']);

        // A member's surname isn't shown, so it can't find them
        $this->assertSame([], $this->getJson('/api/v1/community/mention-suggestions?q=Namuli')->json('data'));
        $this->assertContains($sarah->id, array_column($this->getJson('/api/v1/community/mention-suggestions?q='.urlencode('sarah n.'))->json('data'), 'id'));

        // Only what the community already shows
        $payload = json_encode($found);
        foreach (['email', 'phone', 'status'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $payload);
        }
        $this->assertStringNotContainsString($sarah->email, $payload);
    }

    public function test_suggestions_need_a_signed_in_member_and_are_rate_limited()
    {
        $this->getJson('/api/v1/community/mention-suggestions?q=sar')->assertUnauthorized();

        $this->signIn($this->participant('Grace Akello'));
        $this->getJson('/api/v1/community/mention-suggestions')->assertStatus(422);
        $this->getJson('/api/v1/community/mention-suggestions?q='.str_repeat('a', 41))->assertStatus(422);
        foreach (range(1, 58) as $i) {
            $this->getJson('/api/v1/community/mention-suggestions?q=a'.$i)->assertOk();
        }
        $this->getJson('/api/v1/community/mention-suggestions?q=last')->assertStatus(429);
    }

    public function test_mentions_of_someone_no_longer_active_are_plain_text()
    {
        $sarah = $this->participant('Sarah Namuli');
        $this->signIn($this->participant('Grace Akello'));
        $id = $this->publish('Thanks '.self::token($sarah))->assertCreated()->json('data.id');

        $sarah->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $mention = $this->getJson('/api/v1/community/posts/'.$id)->json('data.mentions.0');
        $this->assertFalse($mention['linkable']);
        $this->assertNull($mention['provider_id']);
    }

    public function test_deleting_a_post_for_good_removes_its_mentions()
    {
        $sarah = $this->participant('Sarah Namuli');
        $this->signIn($this->participant('Grace Akello'));
        $id = $this->publish('Thanks '.self::token($sarah))->assertCreated()->json('data.id');

        CommunityPost::where('uuid', $id)->first()->forceDelete();
        $this->assertSame(0, CommunityMention::count());
        $this->assertSame(0, DB::table('community_mention_alerts')->count());
    }
}
