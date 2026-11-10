<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Featured projects: a provider's flagship jobs (1 to 6 photos each, up to
 * three) shown at the top of their profile. Existing work stays as it is.
 */
class AddIsFeaturedToProviderWorkItems extends Migration
{
    public function up()
    {
        Schema::table('provider_work_items', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('done_on');
            $table->index(['provider_profile_id', 'is_featured'], 'work_items_provider_featured');
        });
    }

    public function down()
    {
        Schema::table('provider_work_items', function (Blueprint $table) {
            $table->dropIndex('work_items_provider_featured');
            $table->dropColumn('is_featured');
        });
    }
}
