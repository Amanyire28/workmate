<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Community videos, stored in Cloudflare R2 (never on this server).
 *
 * - community_post_media gets a type (existing rows stay 'photo') and the
 *   video's storage key, length and size. For a video, path/thumb_path hold
 *   its poster image ('' when the phone couldn't make one).
 * - community_video_uploads records each upload in progress, so a post is
 *   only made once the whole video has arrived and passed its checks, and
 *   abandoned uploads can be cleaned up.
 *
 * Additive only: nothing existing changes.
 */
class AddCommunityVideos extends Migration
{
    public function up()
    {
        Schema::table('community_post_media', function (Blueprint $table) {
            $table->string('type', 10)->default('photo')->after('uuid');
            $table->string('video_key', 191)->nullable()->after('thumb_path');
            $table->decimal('duration', 6, 2)->nullable()->after('height');
            $table->unsignedBigInteger('size')->nullable()->after('duration');
            $table->timestamp('video_deleted_at')->nullable()->after('size');
        });

        Schema::create('community_video_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_token');
            $table->string('object_key', 191)->unique();
            $table->string('status', 12)->default('pending'); // pending, checking, completed, failed, cancelled, expired
            // What the post will say (checked when the upload was asked for)
            $table->string('category', 20);
            $table->text('body')->nullable();
            $table->string('topic', 100)->nullable();
            // What the phone said about the video; checked again against the file itself
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('size');
            $table->decimal('duration', 6, 2);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->foreignId('community_post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error', 40)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('object_deleted_at')->nullable();
            $table->unsignedSmallInteger('cleanup_attempts')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'client_token']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('community_video_uploads');
        Schema::table('community_post_media', function (Blueprint $table) {
            $table->dropColumn(['type', 'video_key', 'duration', 'size', 'video_deleted_at']);
        });
    }
}
