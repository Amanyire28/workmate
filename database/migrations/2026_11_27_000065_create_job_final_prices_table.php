<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B3, the final price of a job. A NEW table only: no existing table is changed.
 *
 * A final price is never typed in by anyone. It is a snapshot of the price the customer ACCEPTED in the B2
 * agreement workflow (source = "agreement"), locked when the job is finished or the first payment is reported.
 * If a later amendment is accepted, a new version is saved and the old one becomes "superseded"; nothing is
 * overwritten, so the agreed amount of every version stays on record. A job with no agreement has no final price.
 *
 * Amounts are whole UGX (integers). The database refuses a second current version per request (unique slot).
 */
class CreateJobFinalPricesTable extends Migration
{
    public function up()
    {
        Schema::create('job_final_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->constrained('job_price_agreements')->restrictOnDelete();
            $table->unsignedSmallInteger('revision_no');
            $table->unsignedInteger('amount_ugx');
            // Only "agreement" exists: a price the customer accepted
            $table->string('source', 20)->default('agreement');
            // current | superseded
            $table->string('status', 12);
            $table->unsignedTinyInteger('current_slot')->nullable();
            // Empty when the system locked it (a job finished)
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['service_request_id', 'revision_no'], 'final_prices_revision');
            $table->unique(['service_request_id', 'current_slot'], 'final_prices_one_current');
        });
    }

    public function down()
    {
        Schema::dropIfExists('job_final_prices');
    }
}
