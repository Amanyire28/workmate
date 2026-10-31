<?php

namespace Tests\Feature\Api;

use App\Models\AppInstallEvent;
use Illuminate\Support\Facades\Cache;

class AppEventTest extends ApiTestCase
{
    private const DEVICE = '3f0b6c1e-8d2a-4a7b-9c11-2b5e7f9a0d42';

    private function report(array $body)
    {
        return $this->fromWebsite()->postJson('/api/v1/app-events', $body + ['device_id' => self::DEVICE, 'platform' => 'android']);
    }

    public function test_anyone_can_report_install_events_without_signing_in()
    {
        $this->report(['event' => 'prompt_shown'])->assertStatus(202);
        $this->report(['event' => 'installed', 'source' => 'button'])->assertStatus(202);
        $this->report(['event' => 'first_launch'])->assertStatus(202);

        $this->assertSame(3, AppInstallEvent::where('device_id', self::DEVICE)->count());
        $this->assertSame('button', AppInstallEvent::where('event', 'installed')->value('source'));
        $this->assertNull(AppInstallEvent::where('event', 'first_launch')->value('source'));
    }

    public function test_repeats_from_the_same_device_are_counted_once()
    {
        $this->report(['event' => 'installed'])->assertStatus(202);
        $this->report(['event' => 'installed'])->assertStatus(202);
        $this->report(['event' => 'prompt_shown'])->assertStatus(202);
        $this->report(['event' => 'prompt_shown'])->assertStatus(202);

        // A later day: prompts count again, an install never does
        $this->travel(2)->days();
        $this->report(['event' => 'prompt_shown'])->assertStatus(202);
        $this->report(['event' => 'installed'])->assertStatus(202);

        $this->assertSame(1, AppInstallEvent::where('event', 'installed')->count());
        $this->assertSame(2, AppInstallEvent::where('event', 'prompt_shown')->count());
        $this->assertSame('browser', AppInstallEvent::where('event', 'installed')->value('source'), 'no source means the browser’s own menu');
    }

    public function test_invalid_events_are_rejected()
    {
        $this->report(['event' => 'downloaded'])->assertStatus(422)->assertJsonValidationErrors('event');
        $this->report(['event' => 'installed', 'platform' => 'windows-phone'])->assertStatus(422)->assertJsonValidationErrors('platform');
        $this->report(['event' => 'installed', 'device_id' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors('device_id');
        $this->report(['event' => 'installed', 'source' => 'ads'])->assertStatus(422)->assertJsonValidationErrors('source');

        $this->assertSame(0, AppInstallEvent::count());
    }

    public function test_reporting_is_rate_limited()
    {
        Cache::flush();
        for ($i = 0; $i < 10; $i++) {
            $this->report(['event' => 'prompt_shown'])->assertStatus(202);
        }

        $this->report(['event' => 'prompt_shown'])->assertStatus(429);
    }

    public function test_admins_with_usage_access_see_install_figures()
    {
        $this->report(['event' => 'prompt_shown']);
        $this->report(['event' => 'installed', 'source' => 'button']);
        $this->report(['event' => 'first_launch']);
        // An iPhone never confirms the install, but is counted when opened from its icon
        $this->report(['event' => 'first_launch', 'device_id' => '9a1c2d3e-4f5a-4b6c-8d7e-0f1a2b3c4d5e', 'platform' => 'ios']);

        $this->signIn($this->userWithRoles(['super_admin']));
        $installs = $this->getJson('/api/v1/admin/data')->assertOk()->json('data.installs');

        $this->assertSame(2, $installs['devicesWithApp']);
        $this->assertSame(now()->toDateString(), $installs['trackingSince']);
        $this->assertCount(180, $installs['daily']);
        $today = end($installs['daily']);
        $this->assertSame(now()->toDateString(), $today['date']);
        $this->assertSame(1, $today['installPrompts']);
        $this->assertSame(1, $today['installs']);
        $this->assertSame(1, $today['installsButton']);
        $this->assertSame(2, $today['firstLaunches']);
        $this->assertSame(1, $today['appAndroid']);
        $this->assertSame(1, $today['appIos']);
    }

    public function test_install_figures_are_hidden_without_usage_access()
    {
        $this->report(['event' => 'installed']);

        $this->signIn($this->userWithRoles(['finance']));
        $this->assertNull($this->getJson('/api/v1/admin/data')->assertOk()->json('data.installs'));
    }

    public function test_nothing_personal_is_stored()
    {
        $this->report(['event' => 'installed']);

        $columns = array_keys(AppInstallEvent::first()->getAttributes());
        sort($columns);
        $this->assertSame(['created_at', 'device_id', 'event', 'id', 'occurred_on', 'platform', 'source'], $columns);
    }
}
