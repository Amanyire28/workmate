<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B2, the history of every step of a price negotiation. A NEW table only.
 *
 * Append-only: rows are written when something happens and never changed or removed (the model refuses).
 * Each row keeps who did it, the status before and after, the amount at that moment and any message, so the
 * negotiation can be read back exactly as it happened. actor_id is empty for the system (an expiry, or a
 * job that was cancelled).
 */
class CreateJobPricingEventsTable extends Migration
{
    public function up()
    {
        Schema::create('job_pricing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained('job_price_agreements')->restrictOnDelete();
            $table->string('event', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            // provider | customer | system
            $table->string('actor_role', 10);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->unsignedInteger('amount_ugx')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['service_request_id', 'id'], 'pricing_events_request');
        });
    }

    public function down()
    {
        Schema::dropIfExists('job_pricing_events');
    }
}
