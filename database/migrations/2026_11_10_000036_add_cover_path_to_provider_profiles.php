<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The cover photo across the top of a provider's profile (optional) */
class AddCoverPathToProviderProfiles extends Migration
{
    public function up()
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->string('cover_path')->nullable();
        });
    }

    public function down()
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropColumn('cover_path');
        });
    }
}
