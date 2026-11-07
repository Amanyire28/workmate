<?php

namespace Tests\Feature\Api;

use App\Models\ProviderProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ProviderApplications;
use App\Services\UserNotifications;

class NotificationListTest extends ApiTestCase
{
    /** An approved plumber */
    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create(['name' => 'Okello Mukasa']);
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function titles(User $user): array
    {
        return UserNotification::where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->pluck('title')->all();
    }

    public function test_what_happens_is_listed_even_without_push()
    {
        $provider = $this->provider();
        $customer = $this->signIn(User::factory()->create(['name' => 'Aisha Nakato']));
        $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow',
        ])->assertCreated();

        $owner = $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/heartbeat')->assertOk()->assertJsonPath('data.unread_notifications', 2);
        $list = $this->getJson('/api/v1/me/notifications')->assertOk()
            ->assertJsonPath('meta.unread', 2)
            ->assertJsonPath('data.0.title', 'New request')
            ->assertJsonPath('data.0.body', 'Aisha needs plumbing. Reply in WorkMate.')
            ->assertJsonPath('data.0.url', '/activity?tab=received')
            ->assertJsonPath('data.0.kind', 'requests')
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('data.1.title', 'You’re live on WorkMate')
            ->json('data');

        $this->postJson('/api/v1/me/notifications/'.$list[0]['id'].'/read')->assertOk()->assertJsonPath('data.unread', 1);
        $this->postJson('/api/v1/me/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);
        $this->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.read', true);

        // The customer heard nothing about their own request
        $this->assertSame([], $this->titles($customer));
        $this->assertNotNull($owner);
    }

    public function test_messages_in_one_chat_share_one_unread_entry()
    {
        $provider = $this->provider();
        $customer = $this->signIn(User::factory()->create(['name' => 'Aisha Nakato']));
        $chat = $this->postJson("/api/v1/me/conversations/provider/{$provider->id}")->assertOk()->json('data.id');
        $this->postJson("/api/v1/me/conversations/{$chat}/messages", ['body' => 'Hello'])->assertCreated();
        $this->travel(5)->minutes();
        $this->postJson("/api/v1/me/conversations/{$chat}/messages", ['body' => 'Are you free?'])->assertCreated();

        $this->assertSame(['New message', 'You’re live on WorkMate'], $this->titles($provider->owner));

        // Once read, the next message is a new entry at the top
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/notifications/read')->assertOk();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/conversations/{$chat}/messages", ['body' => 'Hello again'])->assertCreated();
        $this->assertSame(['New message', 'New message', 'You’re live on WorkMate'], $this->titles($provider->owner));
    }

    public function test_people_choose_what_they_are_told_about_except_account_notices()
    {
        $user = $this->signIn(User::factory()->create());
        $choices = $this->getJson('/api/v1/me/notification-settings')->assertOk()
            ->assertJsonPath('data.community_likes.on', true)
            ->assertJsonPath('data.community_likes.label', 'Likes')
            ->json('data');
        $this->assertArrayNotHasKey('account', $choices);

        $this->putJson('/api/v1/me/notification-settings', ['community_likes' => false, 'messages' => false, 'account' => false])->assertOk()
            ->assertJsonPath('data.community_likes.on', false)->assertJsonPath('data.messages.on', false)->assertJsonPath('data.requests.on', true);
        $this->putJson('/api/v1/me/notification-settings', ['community_likes' => 'maybe'])->assertStatus(422);

        $service = app(UserNotifications::class);
        $message = ['title' => 'Your post was liked', 'body' => 'Someone liked your post.', 'url' => '/community', 'tag' => 'x'];
        $service->send('community_likes', [$user->id], $message);
        $service->send('account', [$user->id], ['title' => 'A notice from WorkMate'] + $message);
        $this->assertSame(['A notice from WorkMate'], $this->titles($user));

        // Turned back on
        $this->putJson('/api/v1/me/notification-settings', ['community_likes' => true])->assertOk();
        $this->assertNull($user->fresh()->notification_preferences['community_likes'] ?? null);
        $service->send('community_likes', [$user->id], ['tag' => 'y'] + $message);
        $this->assertSame(['Your post was liked', 'A notice from WorkMate'], $this->titles($user));
    }

    public function test_notifications_are_private()
    {
        $owner = User::factory()->create();
        app(UserNotifications::class)->send('account', [$owner->id], ['title' => 'Documents approved', 'body' => 'Approved.', 'url' => '/account', 'tag' => null]);
        $id = UserNotification::value('id');

        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/me/notifications/{$id}/read")->assertOk();
        $this->postJson('/api/v1/me/notifications/read')->assertOk();
        $this->assertNull(UserNotification::find($id)->read_at);
    }
}
