<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProviderReplyToServiceRequestsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // The provider's answer to a request sent to them
        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('responded_at')->nullable()->after('status');
            // Why they declined, shown to the customer
            $table->string('provider_note', 500)->nullable()->after('responded_at');
            $table->index(['provider_profile_id', 'status']);
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
            $table->dropIndex(['provider_profile_id', 'status']);
            $table->dropColumn(['responded_at', 'provider_note']);
        });
    }
}
