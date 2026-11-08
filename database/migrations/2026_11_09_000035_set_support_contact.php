<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sets WorkMate's support email and phone, replacing whatever was entered in
 * the admin settings, and keeps asking people to accept the legal documents,
 * so the final legal documents, footer and Account page show the same on
 * every site. Administrators can still change them afterwards.
 */
class SetSupportContact extends Migration
{
    private const VALUES = [
        'platform.support_email' => 'resnetsystems@gmail.com',
        'platform.support_phone' => '0787320647',
        'legal.ask_acceptance' => true,
    ];

    public function up()
    {
        $now = now();
        foreach (self::VALUES as $key => $value) {
            DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => $now]);
            DB::table('platform_settings')->where('key', $key)->whereNull('created_at')->update(['created_at' => $now]);
        }

        Cache::forget('platform_settings.overrides');
    }

    public function down()
    {
        // The defaults in config/workmate.php give the same values; nothing to undo
    }
}
