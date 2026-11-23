<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B7, settlement of commission obligations. NEW tables, plus one widening of the B6 ledger so a settlement credit (which belongs to a
 * cycle and a payment, not to one assessment) can live in the SAME ledger instead of a parallel one:
 *
 *   commission_ledger_entries   assessment_id becomes NULLable and two nullable columns are added (cycle_id, commission_payment_id).
 *                               No row changes. The B6 switch is off in production and nothing can have been written yet.
 *   commission_settlement_cycles    one per stretch of obligations a provider settles together (no balance or status stored: they are derived)
 *   commission_cycle_obligations    which cycle each assessment belongs to (one each, never moved)
 *   commission_payment_reports      what a provider says it paid WorkMate, and the finance decision on it
 *   commission_exceptions           money that cannot be applied without an approved decision (an overpayment, an excess credit)
 *   commission_cycle_events         the lasting record that a trigger (three jobs) was reached, whatever a later reversal does to the live state
 *   commission_settlement_notices   one row per reminder sent, so none is sent twice
 *
 * Nothing is collected and no gateway exists: a payment report is a statement, and only a verified one creates a credit.
 */
class CreateCommissionSettlementTables extends Migration
{
    public function up()
    {
        Schema::create('commission_settlement_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            $table->dateTime('created_at');

            $table->index(['provider_profile_id', 'id'], 'commission_cycles_provider');
        });

        Schema::create('commission_cycle_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('commission_settlement_cycles')->restrictOnDelete();
            $table->foreignId('assessment_id')->constrained('commission_assessments')->restrictOnDelete();
            $table->dateTime('created_at');

            $table->unique('assessment_id', 'commission_cycle_obligation_once');
            $table->index(['cycle_id', 'id'], 'commission_cycle_obligations_cycle');
        });

        Schema::create('commission_payment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('cycle_id')->constrained('commission_settlement_cycles')->restrictOnDelete();
            $table->unsignedBigInteger('reported_by_user_id');
            $table->unsignedInteger('amount_ugx');
            // mtn_momo | airtel_money | bank_transfer | cash
            $table->string('channel', 16);
            $table->string('reference', 60)->nullable();
            // channel:reference while the report is pending or verified; NULL once rejected, so a rejected reference can be reported again
            $table->string('active_reference', 90)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('note', 300)->nullable();
            $table->string('client_token', 64)->nullable();
            // Optional proof (private disk, random name)
            $table->string('evidence_path', 255)->nullable();
            $table->string('evidence_name', 120)->nullable();
            $table->string('evidence_mime', 40)->nullable();
            $table->unsignedInteger('evidence_size')->nullable();

            // pending | verified | rejected
            $table->string('status', 10)->default('pending');
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            // What finance confirmed was actually received, what of it was credited, and what could not be applied
            $table->unsignedInteger('verified_amount_ugx')->nullable();
            $table->unsignedInteger('credited_ugx')->nullable();
            $table->unsignedInteger('excess_ugx')->nullable();
            $table->unsignedBigInteger('ledger_entry_id')->nullable();
            $table->timestamps();

            $table->unique('active_reference', 'commission_payment_reference_once');
            $table->unique(['provider_profile_id', 'client_token'], 'commission_payment_retry_once');
            $table->index(['status', 'id'], 'commission_payments_queue');
            $table->index(['provider_profile_id', 'id'], 'commission_payments_provider');
            $table->index(['cycle_id', 'id'], 'commission_payments_cycle');
        });

        Schema::create('commission_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            // overpayment | excess_credit
            $table->string('kind', 16);
            $table->unsignedBigInteger('commission_payment_id')->nullable();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->unsignedInteger('amount_ugx');
            // open: waiting for an approved reconciliation decision (none is built yet)
            $table->string('status', 10)->default('open');
            $table->string('detail', 300)->nullable();
            $table->dateTime('created_at');

            $table->index(['status', 'id'], 'commission_exceptions_queue');
            $table->index(['provider_profile_id', 'id'], 'commission_exceptions_provider');
        });

        // The durable record of a trigger being reached (a cycle's state is worked out live, so a later reversal can change what it says now,
        // but never that the trigger was reached): written once per cycle and kind, never changed or removed
        Schema::create('commission_cycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('commission_settlement_cycles')->restrictOnDelete();
            // three_jobs_reached
            $table->string('event', 24);
            $table->unsignedTinyInteger('jobs')->nullable();
            // The moment it happened (the obligation that brought the cycle to three jobs)
            $table->dateTime('happened_at');
            $table->dateTime('created_at');

            $table->unique(['cycle_id', 'event'], 'commission_cycle_event_once');
        });

        Schema::create('commission_settlement_notices', function (Blueprint $table) {
            $table->id();
            $table->string('notice_key', 80);
            $table->dateTime('created_at');

            $table->unique('notice_key', 'commission_notice_once');
        });

        // Widening only: no existing row can be changed by it
        DB::statement('ALTER TABLE commission_ledger_entries MODIFY assessment_id BIGINT UNSIGNED NULL');
        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('cycle_id')->nullable()->after('assessment_id');
            $table->unsignedBigInteger('commission_payment_id')->nullable()->after('cycle_id');
            $table->index(['cycle_id', 'id'], 'commission_ledger_cycle');
            // A settlement credit must point at a real cycle and a real payment report (and, from B6, a real provider)
            $table->foreign('cycle_id', 'commission_ledger_cycle_fk')->references('id')->on('commission_settlement_cycles')->restrictOnDelete();
            $table->foreign('commission_payment_id', 'commission_ledger_payment_fk')->references('id')->on('commission_payment_reports')->restrictOnDelete();
        });
        // The shape of every line, enforced by the database: an assessment or reversal belongs to an assessment; a settlement belongs to a
        // cycle and a payment, takes something away, and belongs to no assessment. (Every existing B6 line already fits the first kind.)
        DB::statement("ALTER TABLE commission_ledger_entries ADD CONSTRAINT commission_ledger_shape CHECK ("
            ."(entry_type IN ('assessment','reversal') AND assessment_id IS NOT NULL AND cycle_id IS NULL AND commission_payment_id IS NULL)"
            ." OR (entry_type = 'settlement' AND assessment_id IS NULL AND cycle_id IS NOT NULL AND commission_payment_id IS NOT NULL AND amount_ugx < 0))");
    }

    public function down()
    {
        DB::statement('ALTER TABLE commission_ledger_entries DROP CONSTRAINT commission_ledger_shape');
        Schema::table('commission_ledger_entries', function (Blueprint $table) {
            $table->dropForeign('commission_ledger_cycle_fk');
            $table->dropForeign('commission_ledger_payment_fk');
            $table->dropIndex('commission_ledger_cycle');
            $table->dropColumn(['cycle_id', 'commission_payment_id']);
        });
        // assessment_id is left NULLable on purpose: putting NOT NULL back would fail if a settlement credit exists, and a rollback must never destroy financial history
        Schema::dropIfExists('commission_settlement_notices');
        Schema::dropIfExists('commission_cycle_events');
        Schema::dropIfExists('commission_exceptions');
        Schema::dropIfExists('commission_payment_reports');
        Schema::dropIfExists('commission_cycle_obligations');
        Schema::dropIfExists('commission_settlement_cycles');
    }
}
