<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CatalogueSeeder::class,
            AreaSeeder::class,
        ]);

        // Invented providers, reviews and jobs: development machines only
        if (app()->environment('local')) {
            $this->call(SampleMarketplaceSeeder::class);
        }
    }
}
