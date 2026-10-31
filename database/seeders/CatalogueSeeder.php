<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Profession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The starting service categories and professions, matching the ten
 * categories the website shows today (slugs equal the frontend's category
 * ids). Only adds missing rows: anything an administrator has since edited
 * or disabled is left alone.
 */
class CatalogueSeeder extends Seeder
{
    private const CATALOGUE = [
        // slug => [name, description, icon, [profession slug, name, plural]]
        'construction' => ['Construction', 'Building, masonry, and general construction', 'bi-bricks', ['builder', 'Builder', 'builders']],
        'cleaning' => ['Cleaning', 'Home, office, and post-construction cleaning', 'bi-stars', ['cleaner', 'Cleaner', 'cleaners']],
        'plumbing' => ['Plumbing', 'Pipe fitting, repairs, and installations', 'bi-droplet', ['plumber', 'Plumber', 'plumbers']],
        'electrical' => ['Electrical', 'Wiring, sockets, and electrical repairs', 'bi-lightning-charge', ['electrician', 'Electrician', 'electricians']],
        'moving' => ['Moving & Loading', 'Furniture moving, loading, and offloading', 'bi-box-seam', ['mover', 'Mover', 'movers']],
        'carpentry' => ['Carpentry', 'Furniture, doors, and woodwork', 'bi-hammer', ['carpenter', 'Carpenter', 'carpenters']],
        'painting' => ['Painting', 'Interior and exterior painting', 'bi-palette', ['painter', 'Painter', 'painters']],
        'repairs' => ['Repairs', 'Appliance repair and general fixes', 'bi-tools', ['repair_technician', 'Repair technician', 'repair technicians']],
        'gardening' => ['Gardening', 'Lawn, garden, and landscaping', 'bi-flower1', ['gardener', 'Gardener', 'gardeners']],
        'driving' => ['Driving', 'Deliveries and driver services', 'bi-truck', ['driver', 'Driver', 'drivers']],
    ];

    /**
     * @return void
     */
    public function run()
    {
        DB::transaction(function () {
            $order = 0;

            foreach (self::CATALOGUE as $slug => [$name, $description, $icon, $profession]) {
                $category = Category::firstOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'description' => $description,
                    'icon' => $icon,
                    'sort_order' => $order += 10,
                ]);

                Profession::firstOrCreate(['slug' => $profession[0]], [
                    'category_id' => $category->id,
                    'name' => $profession[1],
                    'plural_name' => $profession[2],
                ]);
            }
        });
    }
}
