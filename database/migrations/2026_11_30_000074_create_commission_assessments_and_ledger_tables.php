<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B6, commission obligations. Two NEW tables; nothing existing is altered.
 *
 *   commission_assessments     one per customer-confirmed payment report (unique), written once: what was assessed, at which rate,
 *                              under which policy version. It points at the shadow snapshot it came from; it never copies money
 *                              into any existing table.
 *   commission_ledger_entries  the append-only record of what a provider owes because of it: the assessment is a positive entry, and a
 *                              reversal (the payment stopped counting) is a negative one. The outstanding amount is the SUM of a
 *                              provider's entries; no balance is stored anywhere, and history is never rewritten.
 *
 * Nothing is written to either table unless the commission assessment switch is on (off by default). No payment, gateway or
 * collection exists here: settlement is a later phase.
 */
class CreateCommissionAssessmentsAndLedgerTables extends Migration
{
    public function up()
    {
        Schema::create('commission_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_report_id')->constrained('job_payment_reports')->restrictOnDelete();
            $table->foreignId('snapshot_id')->constrained('commission_shadow_snapshots')->restrictOnDelete();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            // The confirmed payment the commission is on
            $table->unsignedInteger('amount_ugx');
            $table->unsignedBigInteger('policy_id');
            $table->unsignedInteger('policy_version');
            $table->string('policy_scope', 60)->nullable();
            $table->unsignedSmallInteger('rate_bp');
            $table->unsignedInteger('commission_ugx');
            $table->dateTime('assessed_at');

            $table->unique('payment_report_id', 'commission_assessments_one_per_payment');
            $table->unique('snapshot_id', 'commission_assessments_one_per_snapshot');
            $table->index(['provider_profile_id', 'id'], 'commission_assessments_provider');
        });

        Schema::create('commission_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('commission_assessments')->restrictOnDelete();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            // assessment | reversal
            $table->string('entry_type', 12);
            // Whole UGX, signed: positive = owed to WorkMate, negative = takes some of it away
            $table->integer('amount_ugx');
            $table->string('reason', 40)->nullable();
            // One entry per event: the database refuses a second one with the same reference
            $table->string('reference', 80);
            $table->unsignedBigInteger('dispute_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->dateTime('created_at');

            $table->unique('reference', 'commission_ledger_once');
            $table->index(['provider_profile_id', 'id'], 'commission_ledger_provider');
            $table->index(['assessment_id', 'id'], 'commission_ledger_assessment');
        });
    }

    public function down()
    {
        Schema::dropIfExists('commission_ledger_entries');
        Schema::dropIfExists('commission_assessments');
    }
}
