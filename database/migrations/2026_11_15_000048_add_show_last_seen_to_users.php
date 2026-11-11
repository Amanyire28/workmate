<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether customers see "Online now" / "Last seen …" on a provider's profile.
 * Shown by default (the owner's decision); anyone can turn it off, like on
 * WhatsApp. Only the time of their last activity is ever shown.
 */
class AddShowLastSeenToUsers extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_last_seen')->default(true)->after('last_seen_at');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('show_last_seen');
        });
    }
}
