<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateSavedProvidersAndCataloguePermission extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Providers a customer saved to come back to
        Schema::create('saved_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'provider_profile_id']);
        });

        // Managing service categories and professions from the admin. Safe to
        // run twice; the seeder does the same from config/permissions.php.
        DB::table('permissions')->insertOrIgnore([
            'key' => 'catalogue.manage',
            'description' => 'Add, rename, reorder and hide service categories and professions',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('key', 'catalogue.manage')->value('id');
        foreach (DB::table('roles')->whereIn('key', ['super_admin', 'operations'])->pluck('id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // permission_role rows go with it (cascade)
        DB::table('permissions')->where('key', 'catalogue.manage')->delete();
        Schema::dropIfExists('saved_providers');
    }
}
