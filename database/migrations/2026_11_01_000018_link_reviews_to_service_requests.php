<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class LinkReviewsToServiceRequests extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // One review per finished request, written by the customer who sent it
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('service_request_id')->nullable()->unique()->after('reviewer_id')->constrained()->nullOnDelete();
        });

        // When the customer was asked to rate (and rated or chose not to now)
        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('review_prompted_at')->nullable()->after('provider_note');
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
            $table->dropColumn('review_prompted_at');
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_request_id');
        });
    }
}
