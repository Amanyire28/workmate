<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each push subscription remembers (as a hash) the sign-in session it was
 * saved in, so signing out removes this device's subscription even when the
 * page can't tell which one it is. Existing rows stay as they are (null).
 */
class AddSessionHashToPushSubscriptions extends Migration
{
    public function up()
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->char('session_hash', 64)->nullable()->after('platform');
            $table->index(['user_id', 'session_hash']);
        });
    }

    public function down()
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'session_hash']);
            $table->dropColumn('session_hash');
        });
    }
}
