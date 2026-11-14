<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likes on community comments: one per person per comment, and the count
 * kept on the comment (existing comments start at 0).
 */
class CreateCommunityCommentLikes extends Migration
{
    public function up()
    {
        Schema::table('community_comments', function (Blueprint $table) {
            $table->unsignedInteger('likes_count')->default(0)->after('body');
        });

        Schema::create('community_comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['community_comment_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('community_comment_likes');
        Schema::table('community_comments', function (Blueprint $table) {
            $table->dropColumn('likes_count');
        });
    }
}
