<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommunityTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // What people share about their work. Public once published;
        // moderators remove posts rather than delete them.
        Schema::create('community_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The provider business the author belongs to when they posted, if any
            $table->foreignId('provider_profile_id')->nullable()->constrained()->nullOnDelete();
            // my_work | tips | experience | question | opportunity
            $table->string('category', 20);
            $table->text('body')->nullable();
            // The profession it's about, and roughly where (an area name, never a position)
            $table->foreignId('topic_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            // published | removed
            $table->string('status', 20)->default('published');
            $table->string('removed_reason', 500)->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            // The author deleted it
            $table->softDeletes();
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['category', 'created_at']);
            $table->index(['topic_category_id', 'created_at']);
            $table->index(['area_id', 'created_at']);
        });

        // Photos, re-drawn as JPEG in two sizes on the local disk and served through the API
        Schema::create('community_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('path');
            $table->string('thumb_path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });

        // Comments, with one level of replies (parent_id)
        Schema::create('community_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('community_comments')->cascadeOnDelete();
            $table->text('body');
            // published | removed
            $table->string('status', 20)->default('published');
            $table->string('removed_reason', 500)->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['community_post_id', 'parent_id', 'created_at']);
        });

        // One like and one save per person per post
        Schema::create('community_post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['community_post_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('community_post_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['community_post_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        // Reports can be about a post or a comment
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('community_post_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
            $table->foreignId('community_comment_id')->nullable()->after('community_post_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['community_comment_id']);
            $table->dropForeign(['community_post_id']);
            $table->dropColumn(['community_post_id', 'community_comment_id']);
        });
        Schema::dropIfExists('community_post_saves');
        Schema::dropIfExists('community_post_likes');
        Schema::dropIfExists('community_comments');
        Schema::dropIfExists('community_post_media');
        Schema::dropIfExists('community_posts');
    }
}
