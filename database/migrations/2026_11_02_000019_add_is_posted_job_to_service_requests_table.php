<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsPostedJobToServiceRequestsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // A job posted for anyone to apply to stays one after a provider is
        // hired (provider_profile_id is then set), so this marks it
        Schema::table('service_requests', function (Blueprint $table) {
            $table->boolean('is_posted_job')->default(false)->after('provider_profile_id');
            $table->index(['is_posted_job', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex(['is_posted_job', 'status']);
            $table->dropColumn('is_posted_job');
        });
    }
}
