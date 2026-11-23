<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B5, payment dispute resolution. Two NEW tables; nothing existing is altered.
 *
 *   payment_disputes        one case per disputed payment report (unique), with its status and decision fields. The payment
 *                           report itself is not duplicated: the case points at it, and its status stays the one source of truth.
 *   payment_dispute_events  the append-only timeline of the case (never changed or removed, by the model's own guards).
 *
 * No money moves and nothing is owed because of either table.
 */
class CreatePaymentDisputesTables extends Migration
{
    public function up()
    {
        Schema::create('payment_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_report_id')->constrained('job_payment_reports')->restrictOnDelete();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('customer_id');
            // open | discussion | escalated | under_review | resolved
            $table->string('status', 16)->default('open');
            $table->unsignedBigInteger('opened_by_user_id')->nullable();
            $table->dateTime('opened_at');

            $table->dateTime('escalated_at')->nullable();
            $table->unsignedBigInteger('escalated_by_user_id')->nullable();
            // customer | provider
            $table->string('escalated_by_role', 10)->nullable();
            $table->string('escalation_reason', 30)->nullable();
            $table->string('escalation_note', 500)->nullable();

            $table->dateTime('review_started_at')->nullable();
            $table->unsignedBigInteger('reviewer_id')->nullable();

            // An outcome one side proposed and the other has not yet agreed to
            $table->string('proposal_outcome', 30)->nullable();
            $table->unsignedBigInteger('proposal_by_user_id')->nullable();
            $table->string('proposal_by_role', 10)->nullable();
            $table->dateTime('proposal_at')->nullable();

            $table->dateTime('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->string('resolution_type', 30)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->unsignedInteger('resolution_amount_ugx')->nullable();
            // confirmed | withdrawn | none: what happened to the payment report itself
            $table->string('resolution_effect', 12)->nullable();
            // A decision the existing payment rules cannot record by themselves: flagged for a separate, explicit correction
            $table->boolean('financial_correction_required')->default(false);
            $table->dateTime('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique('payment_report_id', 'payment_disputes_one_per_payment');
            $table->index(['status', 'escalated_at'], 'payment_disputes_queue');
            $table->index(['customer_id', 'id'], 'payment_disputes_customer');
            $table->index(['provider_profile_id', 'id'], 'payment_disputes_provider');
        });

        Schema::create('payment_dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_dispute_id')->constrained('payment_disputes')->restrictOnDelete();
            $table->string('event', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            // customer | provider | admin | system
            $table->string('actor_role', 10);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('created_at');

            $table->index(['payment_dispute_id', 'id'], 'payment_dispute_events_case');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_dispute_events');
        Schema::dropIfExists('payment_disputes');
    }
}
