<?php

namespace Tests\Feature\Api;

use App\Models\CommunityPost;
use App\Models\User;
use App\Services\EventNotifications;
use Illuminate\Support\Facades\Storage;

class CommunityPeopleTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function member(string $name = 'Aisha Nakato', array $attributes = []): User
    {
        return $this->signIn(User::factory()->create($attributes + ['name' => $name]));
    }

    /** An approved provider whose main skill is $profession */
    private function provider(string $name = 'Okello Peter', string $profession = 'plumber', string $display = 'Okello Plumbing'): User
    {
        $user = $this->member($name);
        $this->putJson('/api/v1/me/provider', [
            'type' => 'individual', 'display_name' => $display, 'professions' => [['profession' => $profession]],
        ])->assertOk();
        $user->providerProfile->forceFill(['status' => 'approved'])->save();

        return $user;
    }

    private function publish(string $body, array $extra = []): string
    {
        $this->travel(21)->seconds();
        $this->postJson('/api/v1/community/posts', $extra + ['category' => 'tips', 'body' => $body])->assertCreated();

        return CommunityPost::latest('id')->value('uuid');
    }

    public function test_members_follow_and_unfollow_once()
    {
        $okello = $this->provider();
        $fan = $this->member('Grace Achieng');

        $this->postJson("/api/v1/community/people/{$okello->id}/follow")->assertOk()->assertJson(['data' => ['following' => true, 'followers_count' => 1]]);
        $this->postJson("/api/v1/community/people/{$okello->id}/follow")->assertOk()->assertJsonPath('data.followers_count', 1);
        $this->getJson("/api/v1/community/people/{$okello->id}")->assertOk()
            ->assertJsonPath('data.name', 'Okello Plumbing')->assertJsonPath('data.title', 'Plumber')
            ->assertJsonPath('data.following', true)->assertJsonPath('data.followers_count', 1);
        $this->getJson('/api/v1/community/me')->assertJsonPath('data.following_count', 1)->assertJsonPath('data.linkable', false);

        $this->deleteJson("/api/v1/community/people/{$okello->id}/follow")->assertOk()->assertJsonPath('data.followers_count', 0);
        $this->deleteJson("/api/v1/community/people/{$okello->id}/follow")->assertOk();
        $this->postJson("/api/v1/community/people/{$fan->id}/follow")->assertStatus(422)->assertJson(['code' => 'own_account']);
    }

    public function test_only_people_in_the_community_can_be_found_or_followed()
    {
        $quiet = User::factory()->create(['name' => 'Never Posted']);
        $this->member();

        $this->getJson("/api/v1/community/people/{$quiet->id}")->assertNotFound();
        $this->postJson("/api/v1/community/people/{$quiet->id}/follow")->assertNotFound();
        $this->assertStringNotContainsString('Never', $this->getJson('/api/v1/community/people?q=Never')->getContent());

        // Signed out, or without a confirmed email, nobody follows
        $poster = $this->member('Moses Kato');
        $this->publish('A tip');
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/community/people/{$poster->id}/follow")->assertUnauthorized();
        $this->member('Unconfirmed', ['email_verified_at' => null]);
        $this->postJson("/api/v1/community/people/{$poster->id}/follow")->assertStatus(403);
    }

    public function test_the_following_feed_and_for_you_include_people_followed()
    {
        $okello = $this->provider();
        $this->publish('From Okello');
        $other = $this->member('Moses Kato');
        $this->publish('From Moses');

        $fan = $this->member('Grace Achieng');
        $this->getJson('/api/v1/community/posts?tab=following')->assertJsonCount(0, 'data')->assertJsonPath('meta.can_personalise', false);
        $this->getJson('/api/v1/community/posts?tab=for_you')->assertJsonCount(2, 'data')->assertJsonPath('meta.personalised', false);

        $this->postJson("/api/v1/community/people/{$okello->id}/follow")->assertOk();
        $this->getJson('/api/v1/community/posts?tab=following')->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'From Okello')
            ->assertJsonPath('data.0.author.following', true)->assertJsonPath('data.0.author.id', $okello->id);
        $this->getJson('/api/v1/community/posts?tab=for_you')->assertJsonPath('meta.personalised', true)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/community/posts?tab=latest')->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.author.following', false);
        $this->getJson("/api/v1/community/posts?author={$other->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'From Moses');
        $this->assertNotNull($fan);
    }

    public function test_discovering_people_by_name_and_profession()
    {
        $okello = $this->provider();
        $this->provider('Sarah Namuli', 'cleaner', 'Sparkle Cleaners');
        $poster = $this->member('Moses Kato');
        $this->publish('Fridge question', ['topic' => 'repairs']);

        $viewer = $this->member('Grace Achieng');
        $names = collect($this->getJson('/api/v1/community/people')->assertOk()->json('data'))->pluck('name');
        $this->assertEqualsCanonicalizing(['Okello Plumbing', 'Sparkle Cleaners', 'Moses K.'], $names->all());
        $this->assertSame('Moses K.', $names->first(), 'Recently active first');

        $this->getJson('/api/v1/community/people?topic=cleaning')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sparkle Cleaners');
        $this->getJson('/api/v1/community/people?topic=repairs')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $poster->id);

        // Followed people leave the suggestions, but a search still finds them
        $this->postJson("/api/v1/community/people/{$okello->id}/follow")->assertOk();
        $this->getJson('/api/v1/community/people')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/community/people?q=okello')->assertJsonCount(1, 'data')->assertJsonPath('data.0.following', true);
        $this->getJson('/api/v1/community/people/suggested')->assertOk()->assertJsonCount(2, 'data');

        // Nothing private in a card
        $json = $this->getJson('/api/v1/community/people?q=moses')->getContent();
        $this->assertStringNotContainsString('@', $json);
        $this->assertStringNotContainsString('Kato', $json);
        $this->assertNotNull($viewer);
    }

    public function test_followers_and_following_lists()
    {
        $okello = $this->provider();
        $quiet = $this->member('Quiet Follower');
        $this->postJson("/api/v1/community/people/{$okello->id}/follow")->assertOk();
        $poster = $this->member('Moses Kato');
        $this->publish('Hello');
        $this->postJson("/api/v1/community/people/{$okello->id}/follow")->assertOk();

        $this->app['auth']->forgetGuards();
        $rows = $this->getJson("/api/v1/community/people/{$okello->id}/followers")->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame([$poster->id, $quiet->id], array_column($rows, 'id'));
        $this->assertSame([true, false], array_column($rows, 'linkable'));
        $this->getJson("/api/v1/community/people/{$poster->id}/following")->assertJsonPath('data.0.name', 'Okello Plumbing');
        $this->getJson("/api/v1/community/people/{$quiet->id}/following")->assertNotFound();

        // A provider profile page finds its owner's card
        $this->getJson('/api/v1/community/providers/'.$okello->providerProfile->id.'/person')->assertOk()
            ->assertJsonPath('data.id', $okello->id)->assertJsonPath('data.followers_count', 2);
    }

    public function test_active_topics_count_real_posts_from_the_last_week()
    {
        $this->provider();
        $this->publish('One');
        $this->publish('Two');
        $this->member('Moses Kato');
        $this->publish('Three', ['topic' => 'cleaning']);
        CommunityPost::where('body', 'Three')->update(['created_at' => now()->subDays(8)]);

        $this->getJson('/api/v1/community/topics')->assertOk()->assertExactJson(['data' => [['slug' => 'plumbing', 'name' => 'Plumbing', 'posts' => 2]]]);
    }

    public function test_followers_hear_about_new_posts_without_being_flooded()
    {
        $author = $this->provider();
        $fan = $this->member('Grace Achieng');
        $this->postJson("/api/v1/community/people/{$author->id}/follow")->assertOk();
        $stranger = $this->member('Not Following');

        $this->signIn($author);
        $this->publish('First tip');
        $this->publish('Second tip, an hour later');

        $told = \App\Models\UserNotification::where('user_id', $fan->id)->where('kind', 'community_posts');
        $this->assertSame(['New post from Okello Plumbing'], (clone $told)->pluck('title')->all());
        $this->assertStringStartsWith('/community/post/', (clone $told)->value('url'));
        $this->assertSame(0, \App\Models\UserNotification::where('user_id', $stranger->id)->count());

        // Some hours later the next post is news again (the earlier one was read)
        \App\Models\UserNotification::where('user_id', $fan->id)->update(['read_at' => now()]);
        $this->travel(4)->hours();
        $this->publish('Third tip');
        $this->assertSame(2, (clone $told)->count());

        // Turned off, nothing more arrives
        $this->signIn($fan);
        $this->putJson('/api/v1/me/notification-settings', ['community_posts' => false])->assertOk();
        $this->signIn($author);
        $this->travel(4)->hours();
        $this->publish('Fourth tip');
        $this->assertSame(2, (clone $told)->count());
    }

    public function test_notifications_are_sent_without_repeating()
    {
        $notify = $this->spy(EventNotifications::class);

        $author = $this->provider();
        $post = $this->publish('My work');

        $fan = $this->member('Grace Achieng');
        // Follow, unfollow and follow again: told once
        $this->postJson("/api/v1/community/people/{$author->id}/follow");
        $this->deleteJson("/api/v1/community/people/{$author->id}/follow");
        $this->postJson("/api/v1/community/people/{$author->id}/follow");
        $notify->shouldHaveReceived('newFollower')->once();

        // Likes: at most once an hour per post
        $this->postJson("/api/v1/community/posts/{$post}/like");
        $this->member('Moses Kato');
        $this->postJson("/api/v1/community/posts/{$post}/like");
        $notify->shouldHaveReceived('liked')->once();
        $this->travel(61)->minutes();
        $this->member('Third Fan');
        $this->postJson("/api/v1/community/posts/{$post}/like");
        $notify->shouldHaveReceived('liked')->twice();

        // Every comment and reply
        $this->signIn($fan);
        $this->postJson("/api/v1/community/posts/{$post}/comments", ['body' => 'Nice!'])->assertCreated();
        $notify->shouldHaveReceived('commented')->once();

        // The author liking their own post isn't news
        $this->signIn($author);
        $this->travel(2)->hours();
        $this->postJson("/api/v1/community/posts/{$post}/like");
        $notify->shouldHaveReceived('liked')->twice();
    }
}
