<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which booking system a request belongs to, stated explicitly:
 *   1  legacy: made before scheduling existed. Kept exactly as it is and
 *      handled by the original workflow (accept → in progress → done).
 *   2  the booking system: times, confirmations, double-booking checks,
 *      suggested times, cancellations and chat cards.
 *
 * The default makes every existing row legacy without rewriting it; the
 * application stamps 2 on every request it creates from this release on.
 * No dates, lengths or statuses are added to existing requests.
 */
class AddBookingVersionToServiceRequests extends Migration
{
    public function up()
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('booking_version')->default(1)->after('status');
        });
    }

    public function down()
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn('booking_version');
        });
    }
}
