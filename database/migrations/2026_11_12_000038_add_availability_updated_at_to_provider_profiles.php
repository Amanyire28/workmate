<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a provider last set their availability, so recommendations only trust
 * a recent answer; and an index for finding providers around a place.
 */
class AddAvailabilityUpdatedAtToProviderProfiles extends Migration
{
    public function up()
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->timestamp('availability_updated_at')->nullable();
            $table->index(['latitude', 'longitude'], 'provider_profiles_position');
        });
    }

    public function down()
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropIndex('provider_profiles_position');
            $table->dropColumn('availability_updated_at');
        });
    }
}
