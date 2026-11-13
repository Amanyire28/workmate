<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking reminders already sent (App\Services\BookingReminders).
 *
 * One row per person, reminder and booking ("request:12"): the unique key is
 * what makes a second run (or two at once) send nothing more.
 */
class CreateEngagementRemindersTable extends Migration
{
    public function up()
    {
        Schema::create('engagement_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('occurrence', 80)->default('');
            // in_app, push, or suppressed (switched off by the person)
            $table->json('channels')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type', 'occurrence'], 'engagement_once');
        });
    }

    public function down()
    {
        Schema::dropIfExists('engagement_reminders');
    }
}
