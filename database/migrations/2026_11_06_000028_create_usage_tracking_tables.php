<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsageTrackingTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // One row per account per day it used the app (from the presence heartbeat), for daily active users
        Schema::create('user_activity_days', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('active_on');
            $table->primary(['user_id', 'active_on']);
            $table->index('active_on');
        });

        // Anonymous daily counters, such as searches and profile views: no person, device or place is stored
        Schema::create('app_daily_counts', function (Blueprint $table) {
            $table->id();
            $table->date('counted_on');
            // search | search_nearby | search_wider | search_none | profile_view
            $table->string('metric', 40);
            $table->unsignedInteger('total')->default(0);
            $table->unique(['counted_on', 'metric']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('app_daily_counts');
        Schema::dropIfExists('user_activity_days');
    }
}
