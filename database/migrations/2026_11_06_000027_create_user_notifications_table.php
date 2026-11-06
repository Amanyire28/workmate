<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserNotificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // What each account was told about, shown under the bell whether or not push is on
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // requests | messages | community_comments | community_likes | community_follows | account
            $table->string('kind', 30);
            $table->string('title', 120);
            $table->string('body', 300);
            $table->string('url', 300);
            // Unread notifications with the same tag are updated rather than repeated
            $table->string('tag', 120)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'tag', 'read_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // {kind: false} for each kind of notification the account turned off
            $table->json('notification_preferences')->nullable()->after('locale');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
        Schema::dropIfExists('user_notifications');
    }
}
