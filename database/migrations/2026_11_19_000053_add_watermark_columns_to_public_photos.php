<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public photos (community posts, providers' work) get the WorkMate
 * watermark. Each photo records its private clean copy, when it was
 * watermarked, and the last problem watermarking an older photo (so the
 * catch-up command can report and retry). Existing rows are untouched.
 */
class AddWatermarkColumnsToPublicPhotos extends Migration
{
    private const TABLES = ['community_post_media', 'provider_work_photos'];

    public function up()
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('original_path')->nullable()->after('thumb_path');
                $table->timestamp('watermarked_at')->nullable()->after('original_path');
                $table->string('watermark_error', 255)->nullable()->after('watermarked_at');
                $table->index('watermarked_at');
            });
        }
    }

    public function down()
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['watermarked_at']);
                $table->dropColumn(['original_path', 'watermarked_at', 'watermark_error']);
            });
        }
    }
}
