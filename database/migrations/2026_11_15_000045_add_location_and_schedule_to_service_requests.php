<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where and when a job is. All optional, so existing requests stay as they are:
 *
 * - Location: the exact point the customer confirmed (a fixed job destination,
 *   never live tracking), how accurate it was, a landmark and directions. The
 *   point is private: only the customer, and the provider once they accept.
 * - Schedule: "as soon as possible" or a chosen start, the expected duration
 *   and the end it gives. Only confirmed bookings (scheduled, in progress)
 *   reserve the provider's time. A time the provider suggests waits in the
 *   proposed_* columns until the customer accepts it.
 */
class AddLocationAndScheduleToServiceRequests extends Migration
{
    public function up()
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->decimal('latitude', 9, 6)->nullable()->after('area_name');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
            $table->unsignedInteger('location_accuracy_m')->nullable()->after('longitude');
            $table->string('landmark', 150)->nullable()->after('location_accuracy_m');
            $table->string('directions', 300)->nullable()->after('landmark');

            // asap | scheduled; empty on requests made before scheduling existed
            $table->string('timing', 10)->nullable()->after('preferred_time');
            $table->dateTime('scheduled_start_at')->nullable()->after('timing');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('scheduled_start_at');
            $table->dateTime('scheduled_end_at')->nullable()->after('duration_minutes');
            $table->dateTime('proposed_start_at')->nullable()->after('scheduled_end_at');
            $table->unsignedSmallInteger('proposed_duration_minutes')->nullable()->after('proposed_start_at');
            $table->timestamp('started_at')->nullable()->after('responded_at');
            // customer | provider, for a cancelled booking
            $table->string('cancelled_by', 10)->nullable()->after('started_at');

            $table->index(['provider_profile_id', 'status', 'scheduled_start_at'], 'requests_provider_schedule');
        });

        // Times a provider can't take bookings (a day off, a trip)
        Schema::create('provider_unavailable_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('note', 120)->nullable();
            $table->timestamps();
            $table->index(['provider_profile_id', 'starts_at', 'ends_at'], 'unavailable_provider_range');
        });
    }

    public function down()
    {
        Schema::dropIfExists('provider_unavailable_periods');
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex('requests_provider_schedule');
            $table->dropColumn([
                'latitude', 'longitude', 'location_accuracy_m', 'landmark', 'directions',
                'timing', 'scheduled_start_at', 'duration_minutes', 'scheduled_end_at',
                'proposed_start_at', 'proposed_duration_minutes', 'started_at', 'cancelled_by',
            ]);
        });
    }
}
