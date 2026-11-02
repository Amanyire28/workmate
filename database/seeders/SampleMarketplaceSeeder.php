<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Category;
use App\Models\Profession;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The website's sample providers, reviews and open jobs, so pages have
 * something to show during development. Every record is invented and marked
 * is_sample; the website labels them. Sample accounts can't sign in (random
 * unknown passwords, reserved .invalid email domain).
 *
 * Runs once: if sample providers already exist, it does nothing.
 */
class SampleMarketplaceSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        if (ProviderProfile::where('is_sample', true)->exists()) {
            $this->command->info('Sample marketplace data is already present; nothing changed.');

            return;
        }

        $data = json_decode(file_get_contents(__DIR__.'/data/sample-marketplace.json'), true);
        $areas = Area::pluck('id', 'slug');
        $categories = Category::pluck('id', 'slug');
        // The site's categories each map to one profession (see CatalogueSeeder)
        $professionByCategory = Profession::with('category')->get()->mapWithKeys(function ($profession) {
            return [$profession->category->slug => $profession->id];
        });

        DB::transaction(function () use ($data, $areas, $categories, $professionByCategory) {
            $profiles = [];

            foreach ($data['providers'] as $p) {
                $owner = $this->sampleUser($p['name'], 'provider-'.$p['key']);

                $profile = new ProviderProfile([
                    'type' => $p['type'],
                    'display_name' => $p['business_name'] ?: $p['name'],
                    'bio' => $p['description'],
                    'skills' => $p['skills'],
                    'availability_status' => $p['availability_status'],
                    'availability_note' => $p['availability_note'],
                    'next_available' => $p['next_available'],
                    'price_from' => $p['price_from'],
                    'price_basis' => $p['price_basis'],
                ]);
                $profile->forceFill([
                    'owner_id' => $owner->id,
                    'status' => ProviderProfile::STATUS_APPROVED,
                    'is_sample' => true,
                    'base_area_id' => $areas[$p['base_area']] ?? null,
                    'latitude' => $p['latitude'],
                    'longitude' => $p['longitude'],
                    'approved_at' => now()->subMonths(6),
                    // Sample values until verification and the blue tick run for real (Phase 5)
                    'document_verified_at' => $p['document_verified'] ? now()->subMonths(5) : null,
                    'blue_tick_awarded_at' => $p['blue_tick'] ? now()->subMonths(2) : null,
                ])->save();

                $profile->members()->create(['user_id' => $owner->id, 'role' => ProviderMember::ROLE_OWNER]);

                $professions = [];
                foreach (array_values($p['services']) as $i => $category) {
                    $professions[$professionByCategory[$category]] = ['is_primary' => $i === 0];
                }
                $profile->professions()->sync($professions);

                $profile->serviceAreas()->sync(
                    collect($p['service_areas'])->map(function ($slug) use ($areas) {
                        return $areas[$slug] ?? null;
                    })->filter()->values()->all()
                );

                $profiles[$p['key']] = $profile;
            }

            foreach ($data['reviews'] as $r) {
                $review = new Review();
                $review->forceFill([
                    'provider_profile_id' => $profiles[$r['provider_key']]->id,
                    'reviewer_name' => $r['reviewer_name'],
                    'job_title' => $r['job_title'],
                    'area_name' => $r['area_name'],
                    'rating' => $r['rating'],
                    'would_hire_again' => $r['would_hire_again'],
                    'comment' => $r['comment'],
                    'status' => Review::STATUS_PUBLISHED,
                    'is_sample' => true,
                    'reviewed_at' => now()->subMinutes((int) round($r['days_ago'] * 1440)),
                ])->save();
            }

            $customer = $this->sampleUser('Sample customer', 'customer');

            foreach ($data['jobs'] as $j) {
                $posted = now()->subMinutes((int) round($j['days_ago'] * 1440));
                $job = new ServiceRequest([
                    'category_id' => $categories[$j['category']],
                    'title' => $j['title'],
                    'description' => $j['description'],
                    'area_id' => $areas[$j['area']] ?? null,
                    'area_name' => $j['area_name'],
                    'preferred_time' => $j['preferred_time'],
                    'workers_needed' => $j['workers_needed'],
                    'budget' => $j['budget'],
                    'budget_basis' => $j['budget_basis'],
                ]);
                $job->forceFill([
                    'customer_id' => $customer->id,
                    'status' => ServiceRequest::STATUS_OPEN,
                    'is_posted_job' => true,
                    'is_sample' => true,
                    'created_at' => $posted,
                    'updated_at' => $posted,
                ])->save();
            }
        });

        $this->command->info('Added sample providers, reviews and open jobs (all marked as samples).');
    }

    private function sampleUser(string $name, string $key): User
    {
        $user = new User(['name' => $name, 'email' => "sample+{$key}@workmate.invalid"]);
        $user->forceFill([
            // Nobody knows this password, so the account can't be signed into
            'password' => Hash::make(Str::random(40)),
            'email_verified_at' => now(),
            'is_sample' => true,
        ])->save();

        return $user;
    }
}
