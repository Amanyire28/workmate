<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProviderReportsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Confidential reports about providers. A report is an allegation until
        // the safety team confirms it; it never penalises anyone by itself.
        Schema::create('provider_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            // scam | offline | no_show | unsafe | fake | quality | other
            $table->string('reason', 20);
            $table->text('details')->nullable();
            // open | under_review | confirmed | dismissed
            $table->string('status', 20)->default('open')->index();
            $table->timestamps();
            $table->index('provider_profile_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('provider_reports');
    }
}
