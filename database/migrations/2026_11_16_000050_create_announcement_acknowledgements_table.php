<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which release announcements ("What's New") a signed-in person has seen, so
 * one they closed never comes back on any device. One row per person and
 * announcement; nothing else about them is recorded.
 */
class CreateAnnouncementAcknowledgementsTable extends Migration
{
    public function up()
    {
        Schema::create('announcement_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('announcement_id', 80);
            // dismissed | read | expired
            $table->string('how', 10);
            $table->timestamp('acknowledged_at');
            $table->unique(['user_id', 'announcement_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('announcement_acknowledgements');
    }
}
