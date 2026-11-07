<?php

namespace Tests\Feature\Api;

use App\Models\Area;
use App\Models\User;

class PlaceSearchTest extends ApiTestCase
{
    public function test_every_place_in_uganda_is_loaded_once_alongside_the_quick_picks()
    {
        $this->assertGreaterThan(10000, Area::whereNotNull('geonames_id')->count());
        $this->assertSame(20, Area::where('is_featured', true)->count());
        // GeoNames' own copy of a quick pick isn't loaded twice
        $this->assertSame(1, Area::where('name', 'Ntinda')->where('district', 'Kampala')->count());
        $this->assertSame('Gulu', Area::where('slug', 'gulu-gulu')->value('district'));
    }

    public function test_typing_finds_places_with_their_district_closest_first()
    {
        $names = function ($response) {
            return collect($response->assertOk()->json('data'))->map(function ($a) {
                return $a['name'].', '.$a['district'];
            })->all();
        };

        $found = $names($this->getJson('/api/v1/areas/search?q=ntin'));
        $this->assertSame('Ntinda, Kampala', $found[0]);
        $this->assertContains('Ntinda, Jinja', $found);

        // Near Kampala, Kampala's Kyebando comes before villages far away
        $near = $names($this->getJson('/api/v1/areas/search?q=kyeb&near_latitude=0.35&near_longitude=32.6'));
        $this->assertSame('Kyebando, Kampala', $near[0]);

        $this->assertCount(0, $this->getJson('/api/v1/areas/search?q=%25%25')->json('data'));
        $this->getJson('/api/v1/areas/search?q=a')->assertStatus(422);
    }

    public function test_a_position_is_named_by_the_nearest_place()
    {
        $this->getJson('/api/v1/areas/nearest?latitude=0.3725&longitude=32.5960')->assertOk()->assertJsonPath('data.slug', 'kisaasi');
        $this->getJson('/api/v1/areas/nearest?latitude=2.775&longitude=32.299')->assertOk()->assertJsonPath('data.name', 'Gulu');
        $this->getJson('/api/v1/areas/nearest?latitude=51.5&longitude=0')->assertStatus(422);
    }

    public function test_the_catalog_sends_quick_picks_and_places_in_use_not_every_place()
    {
        $owner = User::factory()->create();
        $this->signIn($owner);
        $this->putJson('/api/v1/me/provider', [
            'type' => 'individual', 'display_name' => 'Gulu Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'gulu-gulu',
        ])->assertOk();
        $this->postJson('/api/v1/me/provider/submit', ['accept_provider_terms' => true])->assertOk();
        $this->signIn($this->userWithRoles(['provider_review']));
        $this->postJson('/api/v1/admin/provider-applications/'.\App\Models\ProviderApplication::sole()->id.'/approve')->assertOk();

        $areas = collect($this->getJson('/api/v1/catalog')->assertOk()->json('data.areas'));
        $this->assertCount(21, $areas);
        $this->assertTrue($areas->contains('slug', 'gulu-gulu'));
    }

    public function test_a_provider_must_say_where_they_are_based_before_sending()
    {
        $this->signIn(User::factory()->create());
        $this->putJson('/api/v1/me/provider', ['type' => 'individual', 'display_name' => 'No Place', 'professions' => [['profession' => 'plumber']]])->assertOk();

        $this->postJson('/api/v1/me/provider/submit', ['accept_provider_terms' => true])->assertStatus(422)->assertJson(['code' => 'base_area_missing']);

        $this->putJson('/api/v1/me/provider', ['type' => 'individual', 'display_name' => 'No Place', 'professions' => [['profession' => 'plumber']], 'base_area' => 'kyebando-kampala'])->assertOk();
        $this->postJson('/api/v1/me/provider/submit', ['accept_provider_terms' => true])->assertOk();
    }

    public function test_the_admin_works_with_places_in_use_not_every_place()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        $data = $this->getJson('/api/v1/admin/data')->assertOk()->json('data');

        $this->assertCount(20, $data['areas']);
        $this->assertLessThanOrEqual(20 * 22, count($data['ops']['coverage']));
    }
}
