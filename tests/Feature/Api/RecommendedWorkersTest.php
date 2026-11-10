<?php

namespace Tests\Feature\Api;

use App\Models\ProviderProfile;
use App\Models\Review;
use App\Models\User;
use App\Services\ProviderApplications;

class RecommendedWorkersTest extends ApiTestCase
{
    /** An approved provider based in a place; established ones were approved long ago */
    private function provider(string $area, string $profession = 'plumber', int $approvedDaysAgo = 120, array $listing = []): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => ucfirst($profession).' '.$owner->id,
            'professions' => [['profession' => $profession]], 'base_area' => $area] + $listing);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));
        $profile = $owner->providerProfile()->first();
        $profile->forceFill(['approved_at' => now()->subDays($approvedDaysAgo)])->save();

        return $profile;
    }

    private function review(ProviderProfile $p, int $rating, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $review = new Review();
            $review->forceFill(['provider_profile_id' => $p->id, 'reviewer_name' => 'Customer', 'job_title' => 'A job', 'rating' => $rating, 'status' => 'published', 'reviewed_at' => now()])->save();
        }
    }

    private function recommended(array $query = []): array
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/providers/recommended?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_only_listed_providers_with_active_owners_are_recommended()
    {
        $listed = $this->provider('ntinda');
        $suspended = $this->provider('ntinda');
        $suspended->forceFill(['status' => ProviderProfile::STATUS_SUSPENDED])->save();
        $ownerGone = $this->provider('ntinda');
        $ownerGone->owner->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $sample = $this->provider('ntinda');
        $sample->forceFill(['is_sample' => true])->save();
        $draftOwner = User::factory()->create();
        app(ProviderApplications::class)->saveDetails($draftOwner, ['type' => 'individual', 'display_name' => 'Draft', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);

        $this->assertSame([$listed->id], $this->recommended(['area' => 'ntinda'])['ids']);
    }

    public function test_workers_in_the_place_come_first_and_the_reach_says_so()
    {
        $here = collect(range(1, 4))->map(function () {
            return $this->provider('ntinda')->id;
        });
        $far = $this->provider('entebbe');
        $this->review($far, 5, 10);

        $data = $this->recommended(['area' => 'ntinda']);
        $this->assertSame('area', $data['reach']);
        $this->assertSame('Ntinda', $data['area']['name']);
        $this->assertEqualsCanonicalizing($here->all(), $data['ids']);
        $this->assertNotContains($far->id, $data['ids']);
    }

    public function test_the_search_widens_step_by_step_when_few_are_close()
    {
        $bukoto = [$this->provider('bukoto')->id, $this->provider('kololo')->id];
        $mukono = [$this->provider('mukono')->id, $this->provider('mukono')->id];

        // Only two within 5 km of Ntinda, none more within 15 km or in Kampala: everyone, closest first
        $data = $this->recommended(['area' => 'ntinda']);
        $this->assertSame('anywhere', $data['reach']);
        $this->assertEqualsCanonicalizing($bukoto, array_slice($data['ids'], 0, 2));
        $this->assertEqualsCanonicalizing($mukono, array_slice($data['ids'], 2));

        // Enough within 5 km stops there
        $bukoto[] = $this->provider('bukoto')->id;
        $bukoto[] = $this->provider('kisaasi')->id;
        $this->app['cache']->flush();
        $data = $this->recommended(['area' => 'ntinda']);
        $this->assertSame('5km', $data['reach']);
        $this->assertEqualsCanonicalizing($bukoto, $data['ids']);
    }

    public function test_without_a_place_everyone_can_be_shown()
    {
        $a = $this->provider('ntinda');
        $b = $this->provider('entebbe');

        $data = $this->recommended([]);
        $this->assertSame('anywhere', $data['reach']);
        $this->assertNull($data['area']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $data['ids']);
        // An unknown place is treated the same way
        $this->assertSame('anywhere', $this->recommended(['area' => 'nowhere-at-all'])['reach']);
    }

    public function test_new_providers_get_a_fair_chance()
    {
        // Ten established providers with excellent reviews and checked documents
        for ($i = 0; $i < 10; $i++) {
            $p = $this->provider('ntinda');
            $this->review($p, 5, 8);
            $p->forceFill(['document_verified_at' => now()])->save();
        }
        $new = $this->provider('ntinda', 'plumber', 3);

        $ids = $this->recommended(['area' => 'ntinda'])['ids'];
        $this->assertCount(8, $ids);
        $this->assertContains($new->id, $ids);
    }

    public function test_no_reviews_is_not_a_penalty()
    {
        $unreviewed = $this->provider('ntinda');
        $poor = $this->provider('ntinda');
        $this->review($poor, 1, 6);

        $this->assertSame([$unreviewed->id, $poor->id], $this->recommended(['area' => 'ntinda'])['ids']);
    }

    public function test_availability_counts_only_when_recently_set()
    {
        $stale = $this->provider('ntinda', 'plumber', 120, ['availability_status' => 'available']);
        $stale->forceFill(['availability_updated_at' => now()->subDays(40)])->save();
        $fresh = $this->provider('ntinda', 'plumber', 120, ['availability_status' => 'available']);
        $away = $this->provider('ntinda', 'plumber', 120, ['availability_status' => 'unavailable']);

        $this->assertNotNull($fresh->fresh()->availability_updated_at);
        $this->assertSame([$fresh->id, $stale->id, $away->id], $this->recommended(['area' => 'ntinda'])['ids']);
    }

    public function test_the_service_asked_for_narrows_the_list()
    {
        $plumber = $this->provider('ntinda', 'plumber');
        $cleaner = $this->provider('ntinda', 'cleaner');

        $data = $this->recommended(['area' => 'ntinda', 'category' => 'cleaning']);
        $this->assertTrue($data['category_matched']);
        $this->assertSame([$cleaner->id], $data['ids']);

        // Nobody offers it: everyone, and the page is told
        $data = $this->recommended(['area' => 'ntinda', 'category' => 'electrical']);
        $this->assertFalse($data['category_matched']);
        $this->assertEqualsCanonicalizing([$plumber->id, $cleaner->id], $data['ids']);
    }
}
