<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;

/**
 * Starting list of areas people search from and providers serve. Centre
 * points are approximate. Only adds missing areas; administrator changes
 * are kept.
 */
class AreaSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/areas.json'), true);
        $order = 0;

        foreach ($data['areas'] as $area) {
            Area::firstOrCreate(['slug' => $area['slug']], [
                'name' => $area['name'],
                'town' => $area['town'],
                'latitude' => $area['latitude'],
                'longitude' => $area['longitude'],
                'is_whole_town' => $area['is_whole_town'],
                'sort_order' => $order += 10,
            ]);
        }
    }
}
