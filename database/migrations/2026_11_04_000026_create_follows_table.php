<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFollowsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Who follows whom in the community; one row per pair
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['follower_id', 'followed_id']);
            $table->index(['followed_id', 'created_at']);
        });

        // When the author was last told about likes, so they hear at most once an hour per post
        Schema::table('community_posts', function (Blueprint $table) {
            $table->timestamp('likes_notified_at')->nullable()->after('comments_count');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('community_posts', function (Blueprint $table) {
            $table->dropColumn('likes_notified_at');
        });
        Schema::dropIfExists('follows');
    }
}
