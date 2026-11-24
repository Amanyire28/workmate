<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission collection (additive only; nothing existing is changed or removed):
 *
 *   commission_payment_attempts        a payment a provider STARTED through a payment gateway (pending, processing, successful, failed,
 *                                      expired, cancelled). An attempt is never a credit: only a verified payment report is, and a
 *                                      successful attempt becomes exactly one verified report through the same ledger path as a manual one.
 *   commission_payment_attempt_events  what happened to an attempt, append-only; the unique event key makes a repeated gateway callback a no-op
 *   commission_payment_reports         + source (manual | gateway), attempt_id, receipt_number (unique, set when a payment is verified)
 *   provider_members.can_pay_commission  the owner always may; any other member only if the owner allows it (default off)
 *
 * No gateway secret, PIN or full phone number is stored.
 */
class AddCommissionCollection extends Migration
{
    public function up()
    {
        Schema::create('commission_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('initiated_by_user_id');
            $table->unsignedInteger('amount_ugx');
            // mtn_momo | airtel_money
            $table->string('method', 16);
            // The number is only shown masked ("0772 *** 123"); the gateway gets the full one at the moment of the request
            $table->string('phone_masked', 24);
            // The unique WorkMate reference the gateway echoes back
            $table->string('reference', 40);
            $table->string('gateway', 16);
            $table->string('gateway_reference', 80)->nullable();
            // pending | processing | successful | failed | expired | cancelled
            $table->string('status', 12)->default('pending');
            $table->string('client_token', 64)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('completed_at')->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->unsignedBigInteger('payment_report_id')->nullable();
            $table->timestamps();

            $table->unique('reference', 'commission_attempt_reference_once');
            $table->unique('gateway_reference', 'commission_attempt_gateway_reference_once');
            $table->unique(['provider_profile_id', 'client_token'], 'commission_attempt_retry_once');
            $table->index(['status', 'id'], 'commission_attempts_queue');
            $table->index(['provider_profile_id', 'id'], 'commission_attempts_provider');
        });

        Schema::create('commission_payment_attempt_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('commission_payment_attempts')->restrictOnDelete();
            $table->string('event', 24);
            $table->string('detail', 200)->nullable();
            $table->string('event_key', 100)->nullable();
            $table->dateTime('created_at');

            $table->unique(['attempt_id', 'event_key'], 'commission_attempt_event_once');
        });

        Schema::table('commission_payment_reports', function (Blueprint $table) {
            $table->string('source', 8)->default('manual')->after('channel');
            $table->unsignedBigInteger('attempt_id')->nullable()->after('source');
            $table->string('receipt_number', 24)->nullable()->after('ledger_entry_id');
            $table->unique('receipt_number', 'commission_payment_receipt_once');
        });

        Schema::table('provider_members', function (Blueprint $table) {
            $table->boolean('can_pay_commission')->default(false)->after('can_view_finances');
        });
    }

    public function down()
    {
        Schema::table('provider_members', function (Blueprint $table) {
            $table->dropColumn('can_pay_commission');
        });
        Schema::table('commission_payment_reports', function (Blueprint $table) {
            $table->dropUnique('commission_payment_receipt_once');
            $table->dropColumn(['source', 'attempt_id', 'receipt_number']);
        });
        Schema::dropIfExists('commission_payment_attempt_events');
        Schema::dropIfExists('commission_payment_attempts');
    }
}
