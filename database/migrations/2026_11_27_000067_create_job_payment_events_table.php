<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B3, the history of every step of a payment report and of the final price. A NEW table only.
 *
 * Append-only: rows are written when something happens and never changed or removed (the model refuses).
 * payment_id is empty for a final-price step. actor_id is empty for the system (a job finished).
 */
class CreateJobPaymentEventsTable extends Migration
{
    public function up()
    {
        Schema::create('job_payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('job_payment_reports')->restrictOnDelete();
            $table->string('event', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            // provider | customer | system
            $table->string('actor_role', 10);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->unsignedInteger('amount_ugx')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['service_request_id', 'id'], 'payment_events_request');
        });
    }

    public function down()
    {
        Schema::dropIfExists('job_payment_events');
    }
}
