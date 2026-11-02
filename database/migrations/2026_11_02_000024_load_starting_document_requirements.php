<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loads starting document requirements for the first ten professions as
 * version 1, for administrators to review in Admin → Document Requirements.
 * The National ID isn't listed: everyone always needs it. Safe to run again:
 * a profession that already has a version is left alone.
 */
class LoadStartingDocumentRequirements extends Migration
{
    private const LIBRARY = [
        'driving_permit' => [
            'name' => 'Driving permit', 'instructions' => 'Both sides of your driving permit, showing the classes you are allowed to drive.',
            'maxSizeMb' => 5, 'frontBack' => true, 'multiPage' => false, 'expiry' => ['required' => true, 'minMonthsValid' => 3],
        ],
        'vehicle_registration' => [
            'name' => 'Vehicle registration', 'instructions' => 'The registration document (logbook) for the vehicle you use for work. Include every page.',
            'maxSizeMb' => 8, 'frontBack' => false, 'multiPage' => true, 'expiry' => ['required' => false, 'minMonthsValid' => 0],
        ],
        'vehicle_insurance' => [
            'name' => 'Vehicle insurance', 'instructions' => 'Your current insurance certificate or sticker for the same vehicle.',
            'maxSizeMb' => 5, 'frontBack' => false, 'multiPage' => false, 'expiry' => ['required' => true, 'minMonthsValid' => 1],
        ],
        'trade_certificate' => [
            'name' => 'Trade or skills certificate', 'instructions' => 'A certificate showing your training or qualification in this trade.',
            'maxSizeMb' => 8, 'frontBack' => false, 'multiPage' => true, 'expiry' => ['required' => false, 'minMonthsValid' => 0],
        ],
        'business_registration' => [
            'name' => 'Business registration', 'instructions' => 'Your business registration certificate, in the name shown on your WorkMate profile.',
            'maxSizeMb' => 8, 'frontBack' => false, 'multiPage' => true, 'expiry' => ['required' => false, 'minMonthsValid' => 0],
        ],
        'reference' => [
            'name' => 'Reference or past work', 'instructions' => 'A reference letter from a past client or employer, or photos of work you have done.',
            'maxSizeMb' => 8, 'count' => 2, 'frontBack' => false, 'multiPage' => true, 'expiry' => ['required' => false, 'minMonthsValid' => 0],
        ],
    ];

    public function up()
    {
        $base = [['business_registration', 'conditional', ['condition' => 'business']], ['reference', 'optional']];
        $sets = [
            'driving' => [['driving_permit', 'mandatory'], ['vehicle_registration', 'conditional', ['condition' => 'own_vehicle']], ['vehicle_insurance', 'conditional', ['condition' => 'own_vehicle']]],
            'electrical' => [['trade_certificate', 'mandatory', ['name' => 'Electrical trade or wiring certificate', 'instructions' => 'Your electrical installation certificate or trade test certificate.']], $base[0], $base[1]],
            'plumbing' => [['trade_certificate', 'optional'], $base[0], $base[1]],
            'construction' => [['trade_certificate', 'optional'], $base[0], $base[1]],
            'moving' => [['vehicle_registration', 'conditional', ['condition' => 'own_vehicle']], $base[0], $base[1]],
            'cleaning' => $base, 'carpentry' => $base, 'painting' => $base, 'repairs' => $base, 'gardening' => $base,
        ];

        $now = now();
        foreach ($sets as $slug => $entries) {
            $category = DB::table('categories')->where('slug', $slug)->value('id');
            if (! $category || DB::table('document_requirement_versions')->where('category_id', $category)->exists()) {
                continue;
            }

            $documents = array_map(function ($entry) {
                [$key, $level] = $entry;

                return array_merge(['id' => $key, 'type' => $key, 'formats' => ['jpg', 'png', 'pdf'], 'count' => 1], self::LIBRARY[$key], [
                    'requirement' => $level, 'condition' => null, 'enabled' => true,
                ], $entry[2] ?? []);
            }, $entries);

            DB::table('document_requirement_versions')->insert([
                'category_id' => $category, 'version' => 1, 'documents' => json_encode($documents),
                'note' => 'Starting requirements loaded by WorkMate. Review them before relying on them.',
                'published_by' => null, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** Removes only the starting versions nobody has built on */
    public function down()
    {
        $kept = DB::table('document_requirement_versions')->where('version', '>', 1)->pluck('category_id');
        $used = DB::table('document_verifications')->whereNotNull('requirement_version_id')->pluck('requirement_version_id');

        DB::table('document_requirement_versions')->where('version', 1)->whereNull('published_by')
            ->whereNotIn('category_id', $kept)->whereNotIn('id', $used)->delete();
    }
}
