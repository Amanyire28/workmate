<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads every populated place in Uganda (towns, trading centres, villages and
 * suburbs, about 11,000) from database/seeders/data/places-ug.csv, made from
 * GeoNames (geonames.org, CC BY 4.0). Only adds places that aren't there yet,
 * so running it again changes nothing and administrators' edits are kept.
 */
class LoadUgandaPlaces extends Migration
{
    private const CHUNK = 500;

    public function up()
    {
        $now = now();
        $this->ensureStartingAreas($now);

        // Places already known, to skip GeoNames' copy of them
        $known = DB::table('areas')->get(['name', 'latitude', 'longitude'])->groupBy(function ($a) {
            return mb_strtolower($a->name);
        });
        $loaded = DB::table('areas')->whereNotNull('geonames_id')->pluck('geonames_id')->flip();
        $slugs = DB::table('areas')->pluck('slug')->flip();

        $file = new SplFileObject(database_path('seeders/data/places-ug.csv'));
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $batch = [];

        foreach ($file as $index => $row) {
            if ($index === 0 || count($row) < 7) {
                continue;
            }
            [$id, $name, $district, $lat, $lng, $kind] = $row;
            if (isset($loaded[(int) $id]) || $this->isKnown($known->get(mb_strtolower($name)), (float) $lat, (float) $lng)) {
                continue;
            }

            $slug = Str::limit(Str::slug($name.' '.$district), 48, '');
            if (isset($slugs[$slug])) {
                $slug = Str::limit($slug, 48, '').'-'.$id;
            }
            $slugs[$slug] = true;

            $batch[] = [
                'slug' => $slug, 'name' => $name, 'town' => $district, 'district' => $district, 'kind' => $kind,
                'geonames_id' => (int) $id, 'latitude' => $lat, 'longitude' => $lng,
                'is_whole_town' => false, 'is_featured' => false, 'sort_order' => 1000, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($batch) === self::CHUNK) {
                DB::table('areas')->insertOrIgnore($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('areas')->insertOrIgnore($batch);
        }
    }

    /** Removes only loaded places that nothing refers to */
    public function down()
    {
        DB::table('areas')
            ->whereNotNull('geonames_id')
            ->whereNotIn('id', DB::table('provider_profiles')->whereNotNull('base_area_id')->select('base_area_id'))
            ->whereNotIn('id', DB::table('provider_service_areas')->select('area_id'))
            ->whereNotIn('id', DB::table('service_requests')->whereNotNull('area_id')->select('area_id'))
            ->delete();
    }

    /** The 20 areas WorkMate started with, as quick picks, on a new database */
    private function ensureStartingAreas($now): void
    {
        $data = json_decode(file_get_contents(database_path('seeders/data/areas.json')), true);
        $districts = ['Kira' => 'Wakiso', 'Entebbe' => 'Wakiso'];
        $order = 0;

        foreach ($data['areas'] as $area) {
            DB::table('areas')->insertOrIgnore([
                'slug' => $area['slug'], 'name' => $area['name'], 'town' => $area['town'],
                'district' => $districts[$area['town']] ?? $area['town'], 'latitude' => $area['latitude'], 'longitude' => $area['longitude'],
                'is_whole_town' => $area['is_whole_town'], 'is_featured' => true, 'sort_order' => $order += 10, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** The same name within 3 km of a place already listed */
    private function isKnown($sameName, float $lat, float $lng): bool
    {
        foreach ($sameName ?: [] as $area) {
            if ($area->latitude === null) {
                continue;
            }
            $dLat = deg2rad($lat - $area->latitude);
            $dLng = deg2rad($lng - $area->longitude);
            $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($area->latitude)) * sin($dLng / 2) ** 2;
            if (12742 * asin(sqrt($h)) < 3) {
                return true;
            }
        }

        return false;
    }
}
