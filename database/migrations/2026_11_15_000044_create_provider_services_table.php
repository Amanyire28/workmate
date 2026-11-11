<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services & prices: the jobs a provider does and what they usually charge,
 * shown on their profile. Prices are a guide unless fixed and agreed; there's
 * no payment here. A request can say which listed service it's about.
 */
class CreateProviderServicesTable extends Migration
{
    public function up()
    {
        Schema::create('provider_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            // One of the provider's own categories, so a request for it is always valid
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 80);
            $table->string('description', 300)->nullable();
            // fixed | from | quote
            $table->string('pricing_type', 10);
            // Whole Uganda shillings; empty for "Request a quote"
            $table->unsignedInteger('price')->nullable();
            // hour | day | visit | item | job, or empty
            $table->string('price_unit', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['provider_profile_id', 'is_active', 'position'], 'provider_services_listing');
        });

        Schema::table('service_requests', function (Blueprint $table) {
            // The listed service the customer chose, if any (the request keeps its own details)
            $table->foreignId('provider_service_id')->nullable()->after('provider_profile_id')->constrained()->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_service_id');
        });
        Schema::dropIfExists('provider_services');
    }
}
