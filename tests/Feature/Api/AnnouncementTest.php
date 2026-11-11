<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** "What's New on WorkMate": shown once to everyone after a release, never again once seen */
class AnnouncementTest extends ApiTestCase
{
    private const ID = 'workmate-major-booking-update-2026-11';

    private function enable(array $overrides = []): void
    {
        config(['announcements.current' => array_merge(config('announcements.current'), ['enabled' => true], $overrides)]);
    }

    public function test_it_shows_only_while_switched_on()
    {
        // Switched off: nothing, whatever the date
        config(['announcements.current.enabled' => false]);
        $this->getJson('/api/v1/announcements/current')->assertOk()->assertExactJson(['data' => null]);

        $this->enable();
        $this->getJson('/api/v1/announcements/current')->assertOk()
            ->assertJsonPath('data.id', self::ID)
            ->assertJsonPath('data.title', 'Finding and booking services just got easier!')
            ->assertJsonPath('data.display_seconds', 20)
            ->assertJsonPath('data.delay_seconds', 2)
            ->assertJsonCount(6, 'data.items')
            ->assertJsonPath('data.acknowledged', false);
    }

    public function test_a_start_time_holds_it_back_but_never_switches_it_on()
    {
        $this->enable(['starts_at' => now()->addDay()->toIso8601String()]);
        $this->getJson('/api/v1/announcements/current')->assertExactJson(['data' => null]);

        $this->enable(['starts_at' => now()->subMinute()->toIso8601String()]);
        $this->getJson('/api/v1/announcements/current')->assertJsonPath('data.id', self::ID);

        // A past start date with the switch off: still off
        config(['announcements.current.enabled' => false]);
        $this->getJson('/api/v1/announcements/current')->assertExactJson(['data' => null]);
    }

    public function test_once_seen_it_stays_seen_on_every_device_and_isnt_stored_twice()
    {
        $this->enable();
        $user = $this->signIn(User::factory()->create());

        $this->postJson('/api/v1/me/announcements/'.self::ID.'/acknowledge', ['how' => 'dismissed'])->assertOk()->assertJsonPath('data.acknowledged', true);
        // Another device, or a retry: still one record
        $this->postJson('/api/v1/me/announcements/'.self::ID.'/acknowledge', ['how' => 'expired'])->assertOk();
        $this->assertSame(1, DB::table('announcement_acknowledgements')->where('user_id', $user->id)->count());
        $this->assertSame('dismissed', DB::table('announcement_acknowledgements')->value('how'));

        // Signing in again elsewhere: the server remembers
        $this->app['auth']->forgetGuards();
        $this->signIn($user->fresh());
        $this->getJson('/api/v1/announcements/current')->assertJsonPath('data.acknowledged', true);

        // Someone else still gets it
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/announcements/current')->assertJsonPath('data.acknowledged', false);
    }

    public function test_a_new_announcement_shows_even_after_an_earlier_one_was_dismissed()
    {
        $this->enable();
        $user = $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/me/announcements/'.self::ID.'/acknowledge')->assertOk();

        config(['announcements.known' => ['workmate-next-release-2027-01', self::ID]]);
        $this->enable(['id' => 'workmate-next-release-2027-01']);
        $this->getJson('/api/v1/announcements/current')->assertJsonPath('data.id', 'workmate-next-release-2027-01')->assertJsonPath('data.acknowledged', false);
        // The earlier dismissal is kept
        $this->assertTrue(DB::table('announcement_acknowledgements')->where('user_id', $user->id)->where('announcement_id', self::ID)->exists());
    }

    public function test_only_known_announcements_can_be_acknowledged_and_only_when_signed_in()
    {
        $this->enable();
        $this->postJson('/api/v1/me/announcements/'.self::ID.'/acknowledge')->assertUnauthorized();

        $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/me/announcements/something-made-up/acknowledge')->assertNotFound();
        $this->postJson('/api/v1/me/announcements/'.self::ID.'/acknowledge', ['how' => 'liked'])->assertStatus(422);
        $this->assertSame(0, DB::table('announcement_acknowledgements')->count());
    }

    public function test_the_audience_decides_who_sees_it()
    {
        $this->enable(['audience' => 'signed_in']);
        $this->getJson('/api/v1/announcements/current')->assertExactJson(['data' => null]);
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/announcements/current')->assertJsonPath('data.id', self::ID);

        $this->enable(['audience' => 'guests']);
        $this->getJson('/api/v1/announcements/current')->assertExactJson(['data' => null]);
    }
}
