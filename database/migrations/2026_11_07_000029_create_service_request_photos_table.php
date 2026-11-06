<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceRequestPhotosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Photos of the job (the leak, the broken door). Private: re-drawn as
        // JPEG in two sizes on the local disk and served only to the people
        // involved in the request.
        Schema::create('service_request_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('path');
            $table->string('thumb_path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('service_requests', function (Blueprint $table) {
            // Sent by the app with each new request, so a retry on a bad connection can't create it twice
            $table->uuid('client_token')->nullable()->after('customer_id');
            $table->unique(['customer_id', 'client_token']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'client_token']);
            $table->dropColumn('client_token');
        });
        Schema::dropIfExists('service_request_photos');
    }
}
