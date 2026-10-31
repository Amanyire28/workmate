<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAccountFieldsToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('locale', 5)->default('en')->after('phone');
            // active | suspended | deactivated. Only the server changes this.
            $table->string('status', 20)->default('active')->index()->after('locale');
            $table->string('avatar_path')->nullable()->after('status');
            $table->timestamp('last_seen_at')->nullable()->index()->after('remember_token');
            $table->softDeletes();
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
            $table->dropUnique(['phone']);
            $table->dropIndex(['status']);
            $table->dropIndex(['last_seen_at']);
            $table->dropSoftDeletes();
            $table->dropColumn(['phone', 'locale', 'status', 'avatar_path', 'last_seen_at']);
        });
    }
}
