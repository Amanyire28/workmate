<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ProviderProfile;
use App\Models\ProviderWorkItem;
use App\Models\Report;
use App\Models\User;
use App\Services\ProviderApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProviderWorkTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function provider(?User $owner = null, bool $approve = true): ProviderProfile
    {
        $owner = $owner ?: User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Plumbing '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        if ($approve) {
            $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));
        }

        return $owner->providerProfile()->first();
    }

    private function add(array $extra = [], int $photos = 2)
    {
        $files = [];
        for ($i = 0; $i < $photos; $i++) {
            $files[] = UploadedFile::fake()->image("job{$i}.jpg", 1200, 900);
        }

        return $this->post('/api/v1/me/provider/work', $extra + ['title' => 'New bathroom pipes in Ntinda', 'photos' => $files], ['Accept' => 'application/json']);
    }

    public function test_an_approved_provider_adds_work_that_shows_on_their_profile()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);

        $item = $this->add(['description' => 'Replaced old pipes', 'done_on' => now()->subMonth()->format('Y-m')])
            ->assertCreated()
            ->assertJsonPath('data.title', 'New bathroom pipes in Ntinda')
            // Defaults to their main profession and base area
            ->assertJsonPath('data.category', 'plumbing')
            ->assertJsonPath('data.area', 'ntinda')
            ->assertJsonPath('share_error', null)
            ->json('data');
        $this->assertCount(2, $item['photos']);

        // Photos are re-drawn: no hidden data, and served to anyone while it's published
        $stored = ProviderWorkItem::first()->photos()->first();
        $this->assertStringNotContainsString('Exif', Storage::disk('local')->get($stored->path));
        $this->app['auth']->forgetGuards();
        $this->get(parse_url($item['photos'][0]['thumb_url'], PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->getJson("/api/v1/providers/{$provider->id}/work")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $item['id']);
        $this->getJson("/api/v1/providers/{$provider->id}")->assertJsonPath('data.work_count', 1);
        $this->assertSame(1, AuditLog::where('action', 'provider.work_added')->count());
    }

    public function test_work_can_also_be_shared_to_the_community()
    {
        $this->signIn($this->provider()->owner);

        $this->add(['share' => '1'])->assertCreated()->assertJsonPath('data.in_community', true);
        $this->getJson('/api/v1/community/posts?tab=latest')->assertJsonPath('data.0.category', 'my_work');
    }

    public function test_only_approved_providers_can_add_work()
    {
        $this->add()->assertUnauthorized();

        $this->signIn(User::factory()->create());
        $this->add()->assertStatus(409)->assertJson(['code' => 'no_provider_profile']);

        $this->signIn($this->provider(null, false)->owner);
        $this->add()->assertStatus(409)->assertJson(['code' => 'provider_not_approved']);
        $this->assertSame(0, ProviderWorkItem::count());
    }

    public function test_photos_and_details_are_checked()
    {
        $this->signIn($this->provider()->owner);

        $this->add([], 0)->assertStatus(422)->assertJsonPath('errors.photos.0', 'Add at least one photo of the work.');
        $this->add([], 5)->assertStatus(422)->assertJsonPath('errors.photos.0', 'Add up to 4 photos.');
        $this->add(['title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->add(['done_on' => now()->addMonths(2)->format('Y-m')])->assertStatus(422)->assertJsonPath('errors.done_on.0', 'Choose a month that has already happened.');
        $this->post('/api/v1/me/provider/work', ['title' => 'CV', 'photos' => [UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['photos.0' => 'Use JPG, PNG or WebP photos.']);
        $this->assertSame(0, ProviderWorkItem::count());
    }

    public function test_a_provider_can_show_up_to_thirty_jobs()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $this->add([], 1)->assertCreated();
        $first = ProviderWorkItem::first();
        for ($i = 1; $i < ProviderWorkItem::MAX_ITEMS; $i++) {
            $copy = $first->replicate();
            $copy->uuid = (string) \Illuminate\Support\Str::uuid();
            $copy->save();
        }

        $this->add([], 1)->assertStatus(422)->assertJson(['code' => 'work_limit']);
        // Deleting one makes room
        $this->deleteJson('/api/v1/me/provider/work/'.$first->uuid)->assertOk();
        $this->add([], 1)->assertCreated();
    }

    public function test_the_owner_edits_and_deletes_their_work()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $id = $this->add()->json('data.id');

        $this->patchJson("/api/v1/me/provider/work/{$id}", ['title' => 'Kitchen sink and pipes', 'description' => null, 'area' => 'kisaasi'])
            ->assertOk()->assertJsonPath('data.title', 'Kitchen sink and pipes')->assertJsonPath('data.area', 'kisaasi')->assertJsonPath('data.category', 'plumbing');

        $this->deleteJson("/api/v1/me/provider/work/{$id}")->assertOk();
        $this->getJson('/api/v1/me/provider/work')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/providers/{$provider->id}/work")->assertJsonCount(0, 'data');
        $this->assertSame(1, AuditLog::where('action', 'provider.work_deleted')->count());
    }

    public function test_nobody_else_can_change_a_providers_work()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $id = $this->add()->json('data.id');

        // Another provider
        $this->signIn($this->provider()->owner);
        $this->patchJson("/api/v1/me/provider/work/{$id}", ['title' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/v1/me/provider/work/{$id}")->assertNotFound();
        $this->getJson('/api/v1/me/provider/work')->assertJsonCount(0, 'data');

        // A customer
        $this->signIn(User::factory()->create());
        $this->deleteJson("/api/v1/me/provider/work/{$id}")->assertStatus(409);

        $this->assertSame('New bathroom pipes in Ntinda', ProviderWorkItem::where('uuid', $id)->value('title'));
    }

    public function test_work_from_a_provider_customers_cant_find_stays_hidden()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $photo = $this->add()->json('data.photos.0.url');

        $provider->forceFill(['status' => ProviderProfile::STATUS_SUSPENDED])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/providers/{$provider->id}/work")->assertNotFound();
        $this->get(parse_url($photo, PHP_URL_PATH))->assertNotFound();
        $this->get('/api/v1/work/media/..%2F..%2F.env')->assertNotFound();
    }

    public function test_customers_report_work_and_moderators_remove_and_restore_it()
    {
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $id = $this->add()->json('data.id');
        $photo = $this->getJson('/api/v1/me/provider/work')->json('data.0.photos.0.url');

        // The owner can't report their own work
        $this->postJson('/api/v1/reports', ['about' => 'work', 'work' => $id, 'reason' => 'misleading'])->assertStatus(422)->assertJson(['code' => 'own_content']);

        $customer = $this->signIn(User::factory()->create());
        $this->postJson('/api/v1/reports', ['about' => 'work', 'work' => $id, 'reason' => 'misleading'])->assertCreated()
            ->assertJsonPath('data.about_name', 'Work on '.$provider->display_name);
        $report = Report::first();
        $this->assertSame($provider->id, $report->provider_profile_id);
        $this->assertSame($provider->owner_id, $report->subject_user_id);

        // Removing needs content moderation
        $this->postJson("/api/v1/admin/work/{$id}/remove", ['reason' => 'These photos are not your work'])->assertForbidden();
        $this->signIn($this->userWithRoles(['trust_safety']));
        $this->postJson("/api/v1/admin/work/{$id}/remove", ['reason' => 'These photos are not your work'])->assertOk()->assertJsonPath('data.status', 'removed');
        // Moderators still see the photos; the public doesn't
        $this->get(parse_url($photo, PHP_URL_PATH))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/providers/{$provider->id}/work")->assertJsonCount(0, 'data');
        $this->get(parse_url($photo, PHP_URL_PATH))->assertNotFound();

        // The owner sees why, and can't edit it
        $this->signIn($provider->owner);
        $this->getJson('/api/v1/me/provider/work')->assertJsonPath('data.0.status', 'removed')->assertJsonPath('data.0.removed_reason', 'These photos are not your work');
        $this->patchJson("/api/v1/me/provider/work/{$id}", ['title' => 'Again'])->assertStatus(409)->assertJson(['code' => 'work_removed']);

        $this->signIn($this->userWithRoles(['trust_safety']));
        $this->postJson("/api/v1/admin/work/{$id}/restore", ['reason' => 'Checked with the provider'])->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame(1, AuditLog::where('action', 'provider.work_removed')->count());
        $this->assertSame(1, AuditLog::where('action', 'provider.work_restored')->count());
    }
}
