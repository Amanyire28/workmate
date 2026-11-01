<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPlaceDetailsToAreasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('district', 60)->nullable()->after('town');
            // town | suburb | place
            $table->string('kind', 20)->nullable()->after('district');
            // Places loaded from GeoNames (geonames.org, CC BY 4.0)
            $table->unsignedInteger('geonames_id')->nullable()->unique()->after('kind');
            // Offered as quick picks before anyone types
            $table->boolean('is_featured')->default(false)->after('is_whole_town');
            $table->index('name');
            $table->index(['is_featured', 'is_active']);
        });

        // The areas WorkMate started with are the quick picks
        $districts = ['Kira' => 'Wakiso', 'Entebbe' => 'Wakiso'];
        foreach (DB::table('areas')->get(['id', 'town']) as $area) {
            DB::table('areas')->where('id', $area->id)->update([
                'is_featured' => true,
                'district' => $districts[$area->town] ?? $area->town,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropIndex(['is_featured', 'is_active']);
            $table->dropIndex(['name']);
            $table->dropUnique(['geonames_id']);
            $table->dropColumn(['district', 'kind', 'geonames_id', 'is_featured']);
        });
    }
}
