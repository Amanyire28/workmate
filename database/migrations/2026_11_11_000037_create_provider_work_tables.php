<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's "Recent work": jobs they've done, with up to four photos each,
 * kept on their profile. Reports can point at one, so moderators see it.
 */
class CreateProviderWorkTables extends Migration
{
    public function up()
    {
        Schema::create('provider_work_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->date('done_on')->nullable();
            // published | removed (by WorkMate)
            $table->string('status', 20)->default('published');
            $table->string('removed_reason', 500)->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('community_post_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider_profile_id', 'status'], 'work_items_provider_status');
        });

        Schema::create('provider_work_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_work_item_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('path');
            $table->string('thumb_path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('provider_work_item_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_work_item_id');
        });
        Schema::dropIfExists('provider_work_photos');
        Schema::dropIfExists('provider_work_items');
    }
}
