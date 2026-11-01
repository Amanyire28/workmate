<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the full list of service categories and professions. Only rows whose
 * slug doesn't exist yet are added, so anything an administrator has edited,
 * hidden or added is left alone, and running it again changes nothing.
 */
class ExpandServiceCatalogue extends Migration
{
    // category slug => [name, description, icon, [[profession slug, name, plural], ...]]
    private const CATALOGUE = [
        'construction' => ['Construction', 'Building, masonry, and general construction', 'bi-bricks', [
            ['builder', 'Builder', 'builders'],
            ['mason', 'Mason', 'masons'],
            ['tiler', 'Tiler', 'tilers'],
            ['roofer', 'Roofer', 'roofers'],
            ['plasterer', 'Plasterer', 'plasterers'],
            ['welder', 'Welder and fabricator', 'welders and fabricators'],
            ['steel_fixer', 'Steel fixer', 'steel fixers'],
            ['interior_designer', 'Interior designer', 'interior designers'],
        ]],
        'cleaning' => ['Cleaning', 'Home, office, and post-construction cleaning', 'bi-stars', [
            ['cleaner', 'Cleaner', 'cleaners'],
            ['laundry_attendant', 'Laundry attendant', 'laundry attendants'],
            ['pest_control', 'Pest control specialist', 'pest control specialists'],
            ['car_washer', 'Car washer', 'car washers'],
            ['carpet_cleaner', 'Carpet and sofa cleaner', 'carpet and sofa cleaners'],
        ]],
        'plumbing' => ['Plumbing', 'Pipe fitting, repairs, and installations', 'bi-droplet', [
            ['plumber', 'Plumber', 'plumbers'],
            ['pump_technician', 'Water pump and tank technician', 'water pump and tank technicians'],
            ['drainage_specialist', 'Drainage specialist', 'drainage specialists'],
        ]],
        'electrical' => ['Electrical', 'Wiring, sockets, and electrical repairs', 'bi-lightning-charge', [
            ['electrician', 'Electrician', 'electricians'],
            ['solar_installer', 'Solar installer', 'solar installers'],
            ['generator_technician', 'Generator technician', 'generator technicians'],
            ['satellite_installer', 'TV and satellite installer', 'TV and satellite installers'],
        ]],
        'moving' => ['Moving & Loading', 'Furniture moving, loading, and offloading', 'bi-box-seam', [
            ['mover', 'Mover', 'movers'],
            ['porter', 'Porter and loader', 'porters and loaders'],
        ]],
        'carpentry' => ['Carpentry', 'Furniture, doors, and woodwork', 'bi-hammer', [
            ['carpenter', 'Carpenter', 'carpenters'],
            ['furniture_maker', 'Furniture maker', 'furniture makers'],
            ['upholsterer', 'Upholsterer', 'upholsterers'],
        ]],
        'painting' => ['Painting', 'Interior and exterior painting', 'bi-palette', [
            ['painter', 'Painter', 'painters'],
            ['sign_writer', 'Sign writer', 'sign writers'],
        ]],
        'repairs' => ['Repairs', 'Appliance repair and general fixes', 'bi-tools', [
            ['repair_technician', 'Repair technician', 'repair technicians'],
            ['appliance_technician', 'Appliance repair technician', 'appliance repair technicians'],
            ['phone_technician', 'Phone repair technician', 'phone repair technicians'],
            ['fridge_ac_technician', 'Fridge and air conditioning technician', 'fridge and air conditioning technicians'],
            ['locksmith', 'Locksmith', 'locksmiths'],
        ]],
        'gardening' => ['Gardening', 'Lawn, garden, and landscaping', 'bi-flower1', [
            ['gardener', 'Gardener', 'gardeners'],
            ['landscaper', 'Landscaper', 'landscapers'],
            ['tree_cutter', 'Tree cutter', 'tree cutters'],
        ]],
        'driving' => ['Driving', 'Deliveries and driver services', 'bi-truck', [
            ['driver', 'Driver', 'drivers'],
            ['boda_rider', 'Boda boda rider', 'boda boda riders'],
            ['delivery_rider', 'Delivery rider', 'delivery riders'],
            ['truck_driver', 'Truck driver', 'truck drivers'],
        ]],
        'vehicles' => ['Vehicle Care', 'Car and motorcycle repair and servicing', 'bi-car-front', [
            ['mechanic', 'Mechanic', 'mechanics'],
            ['auto_electrician', 'Auto electrician', 'auto electricians'],
            ['panel_beater', 'Panel beater and spray painter', 'panel beaters and spray painters'],
            ['tyre_technician', 'Tyre technician', 'tyre technicians'],
            ['motorcycle_mechanic', 'Motorcycle mechanic', 'motorcycle mechanics'],
        ]],
        'beauty' => ['Beauty & Grooming', 'Hair, makeup, nails, and grooming', 'bi-scissors', [
            ['hairdresser', 'Hairdresser', 'hairdressers'],
            ['barber', 'Barber', 'barbers'],
            ['braider', 'Hair braider', 'hair braiders'],
            ['makeup_artist', 'Makeup artist', 'makeup artists'],
            ['nail_technician', 'Nail technician', 'nail technicians'],
        ]],
        'tailoring' => ['Tailoring & Fashion', 'Clothes making, alterations, and shoe repair', 'bi-rulers', [
            ['tailor', 'Tailor', 'tailors'],
            ['fashion_designer', 'Fashion designer', 'fashion designers'],
            ['shoe_repairer', 'Shoe maker and repairer', 'shoe makers and repairers'],
        ]],
        'home_help' => ['Home Help & Care', 'House help, childcare, and caring for others', 'bi-house-heart', [
            ['housekeeper', 'Housekeeper', 'housekeepers'],
            ['nanny', 'Nanny', 'nannies'],
            ['caregiver', 'Caregiver', 'caregivers'],
            ['cook', 'Cook', 'cooks'],
        ]],
        'events' => ['Events', 'Catering, decoration, and entertainment', 'bi-balloon', [
            ['caterer', 'Caterer', 'caterers'],
            ['event_decorator', 'Event decorator', 'event decorators'],
            ['event_planner', 'Event planner', 'event planners'],
            ['dj', 'DJ', 'DJs'],
            ['mc', 'MC', 'MCs'],
            ['baker', 'Baker and cake maker', 'bakers and cake makers'],
        ]],
        'media' => ['Photography & Media', 'Photos, video, and design', 'bi-camera', [
            ['photographer', 'Photographer', 'photographers'],
            ['videographer', 'Videographer', 'videographers'],
            ['graphic_designer', 'Graphic designer', 'graphic designers'],
            ['printer', 'Printing and branding', 'printing and branding services'],
        ]],
        'lessons' => ['Tutoring & Lessons', 'School subjects, music, and driving lessons', 'bi-book', [
            ['tutor', 'Tutor', 'tutors'],
            ['music_teacher', 'Music teacher', 'music teachers'],
            ['driving_instructor', 'Driving instructor', 'driving instructors'],
            ['language_teacher', 'Language teacher', 'language teachers'],
        ]],
        'tech' => ['IT & Tech', 'Computers, networks, and software', 'bi-laptop', [
            ['computer_technician', 'Computer technician', 'computer technicians'],
            ['network_technician', 'Network and Wi-Fi technician', 'network and Wi-Fi technicians'],
            ['web_developer', 'Web and app developer', 'web and app developers'],
        ]],
        'security' => ['Security', 'Guarding and security systems', 'bi-shield-lock', [
            ['security_guard', 'Security guard', 'security guards'],
            ['cctv_installer', 'CCTV and alarm installer', 'CCTV and alarm installers'],
        ]],
        'wellness' => ['Health & Fitness', 'Fitness training and massage', 'bi-heart-pulse', [
            ['fitness_trainer', 'Fitness trainer', 'fitness trainers'],
            ['massage_therapist', 'Massage therapist', 'massage therapists'],
        ]],
        'farming' => ['Farming & Animals', 'Farm work, animal care, and agronomy', 'bi-tree', [
            ['farm_worker', 'Farm worker', 'farm workers'],
            ['agronomist', 'Agronomist', 'agronomists'],
            ['animal_keeper', 'Livestock and poultry keeper', 'livestock and poultry keepers'],
        ]],
        'office' => ['Office & Business', 'Bookkeeping, admin, and translation', 'bi-briefcase', [
            ['bookkeeper', 'Accountant and bookkeeper', 'accountants and bookkeepers'],
            ['secretary', 'Secretary and data entry', 'secretaries and data entry clerks'],
            ['translator', 'Translator', 'translators'],
        ]],
    ];

    public function up()
    {
        DB::transaction(function () {
            $now = now();
            $order = 0;

            foreach (self::CATALOGUE as $slug => [$name, $description, $icon, $professions]) {
                $order += 10;
                DB::table('categories')->insertOrIgnore([
                    'slug' => $slug, 'name' => $name, 'description' => $description, 'icon' => $icon,
                    'sort_order' => $order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $categoryId = DB::table('categories')->where('slug', $slug)->value('id');

                foreach ($professions as $position => [$professionSlug, $professionName, $plural]) {
                    DB::table('professions')->insertOrIgnore([
                        'category_id' => $categoryId, 'slug' => $professionSlug, 'name' => $professionName, 'plural_name' => $plural,
                        'sort_order' => $position * 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    /** Removes only the professions and categories added here that nobody uses yet */
    public function down()
    {
        $original = ['builder', 'cleaner', 'plumber', 'electrician', 'mover', 'carpenter', 'painter', 'repair_technician', 'gardener', 'driver'];

        foreach (self::CATALOGUE as $slug => $category) {
            foreach ($category[3] as [$professionSlug]) {
                if (in_array($professionSlug, $original, true)) {
                    continue;
                }
                $id = DB::table('professions')->where('slug', $professionSlug)->value('id');
                if ($id && ! DB::table('provider_professions')->where('profession_id', $id)->exists()) {
                    DB::table('professions')->where('id', $id)->delete();
                }
            }

            $categoryId = DB::table('categories')->where('slug', $slug)->value('id');
            if (! in_array($slug, ['construction', 'cleaning', 'plumbing', 'electrical', 'moving', 'carpentry', 'painting', 'repairs', 'gardening', 'driving'], true)
                && $categoryId
                && ! DB::table('professions')->where('category_id', $categoryId)->exists()
                && ! DB::table('service_requests')->where('category_id', $categoryId)->exists()) {
                DB::table('categories')->where('id', $categoryId)->delete();
            }
        }
    }
}
