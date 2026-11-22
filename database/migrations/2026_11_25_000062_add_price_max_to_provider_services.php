<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B1, a price range on "Services & Prices": `price` is the bottom and `price_max` the top when
 * pricing_type is "range" (the column is a short string, so the new type needs no change to it).
 * Empty for every other service. A range is a guide, like every listed price.
 */
class AddPriceMaxToProviderServices extends Migration
{
    public function up()
    {
        Schema::table('provider_services', function (Blueprint $table) {
            $table->unsignedInteger('price_max')->nullable();
        });
    }

    public function down()
    {
        Schema::table('provider_services', function (Blueprint $table) {
            $table->dropColumn('price_max');
        });
    }
}
