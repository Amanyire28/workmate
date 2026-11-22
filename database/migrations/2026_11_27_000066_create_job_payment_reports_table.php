<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B3, payments a provider REPORTS having received directly from the customer. A NEW table only.
 *
 * WorkMate does not take or process these payments: a row is a statement by the provider, and it counts as
 * confirmed only after the customer explicitly confirms it (never by silence or time). A provider's report is
 * never verified on its own.
 *
 * status: pending_confirmation | confirmed | disputed | cancelled
 *
 * The amount, method, date, reference and note of a saved report never change (the model refuses): a mistake is
 * fixed by withdrawing the report (status cancelled, with a reason) and reporting a corrected one that points back
 * to it (corrects_id). Several reports per job are allowed (part payments). Whole UGX only. No card numbers, PINs or
 * account details are stored: only an optional short reference the provider chooses to give.
 */
class CreateJobPaymentReportsTable extends Migration
{
    public function up()
    {
        Schema::create('job_payment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('reported_by_user_id');
            $table->unsignedInteger('amount_ugx');
            // cash | mobile_money | bank_transfer | other
            $table->string('method', 20);
            $table->date('paid_on')->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('note', 300)->nullable();
            $table->string('status', 24);
            // The agreed price and final price this report was made against, for the trail (empty: none existed)
            $table->unsignedBigInteger('agreement_id')->nullable();
            $table->unsignedBigInteger('final_price_id')->nullable();
            // A cancelled report this one corrects
            $table->unsignedBigInteger('corrects_id')->nullable();
            $table->unsignedBigInteger('responded_by_user_id')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('response_note', 300)->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            // One per try of a report, so a retry after a dropped connection can't create a copy
            $table->string('client_token', 40)->nullable();
            $table->timestamps();

            $table->unique(['service_request_id', 'client_token'], 'payment_reports_token');
            $table->index(['service_request_id', 'status'], 'payment_reports_request');
            $table->index('provider_profile_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('job_payment_reports');
    }
}
