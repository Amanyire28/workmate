<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a provider is willing to take, kept apart from how busy they are:
 * immediate work ("Available now") and bookings for later. Both off is
 * "Bookings paused". Filled in from each provider's current availability so
 * nothing they show changes:
 *   available   → immediate yes, bookings yes
 *   busy        → immediate no,  bookings yes   ("Busy · Accepting bookings")
 *   unavailable → immediate no,  bookings no    ("Bookings paused")
 * availability_status stays and is kept in step for older screens.
 */
class AddBookingPreferencesToProviderProfiles extends Migration
{
    public function up()
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->boolean('accepts_immediate')->default(true)->after('availability_status');
            $table->boolean('accepts_bookings')->default(true)->after('accepts_immediate');
        });

        // Safe to run again: it only reads availability_status
        DB::table('provider_profiles')->where('availability_status', 'busy')
            ->update(['accepts_immediate' => false, 'accepts_bookings' => true]);
        DB::table('provider_profiles')->where('availability_status', 'unavailable')
            ->update(['accepts_immediate' => false, 'accepts_bookings' => false]);
    }

    public function down()
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropColumn(['accepts_immediate', 'accepts_bookings']);
        });
    }
}
