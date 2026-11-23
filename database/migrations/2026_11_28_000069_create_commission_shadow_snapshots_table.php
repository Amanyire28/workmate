<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B4, the SHADOW commission calculated for one customer-confirmed payment. A NEW table only.
 *
 * Every row is an ESTIMATE ("Estimated, not collected"): mode is always "shadow". It records what the rate rules
 * would have produced, so WorkMate can see what it might earn. It is not an invoice, not a balance, not a debt and
 * not revenue, and nothing here is shown to providers or customers.
 *
 * One current row per confirmed payment report (current_slot is unique with the report), so a payment can never be
 * counted twice, however many times it is processed. status is "calculated" or "excluded" (with the reason). A row is
 * never edited: if a payment was excluded because no rule applied and a rule that covers it exists later, a NEW
 * revision supersedes the old one and the old row stays as it was. The rate, the rule and its version, the inputs and
 * the way the rule was chosen are all kept on the row, so a later change to a rule never rewrites it.
 *
 * Amounts are whole UGX (integers); the commission is amount x rate_bp / 10,000 rounded half up (documented in code).
 */
class CreateCommissionShadowSnapshotsTable extends Migration
{
    public function up()
    {
        Schema::create('commission_shadow_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_report_id')->constrained('job_payment_reports')->restrictOnDelete();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('revision_no');
            // calculated | excluded
            $table->string('status', 12);
            $table->string('exclusion_reason', 30)->nullable();
            // Always "shadow": an estimate, never a charge
            $table->string('mode', 10)->default('shadow');
            $table->unsignedInteger('amount_ugx');
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->unsignedSmallInteger('policy_version')->nullable();
            $table->string('policy_scope', 140)->nullable();
            // provider | profession | category | area | district | global
            $table->string('tier', 12)->nullable();
            $table->unsignedSmallInteger('rate_bp')->nullable();
            $table->unsignedInteger('commission_ugx')->nullable();
            $table->unsignedInteger('provider_net_ugx')->nullable();
            $table->string('rounding', 24)->default('half_up_per_payment');
            $table->unsignedSmallInteger('engine_version')->default(1);
            // What the job was when the payment was confirmed (recorded, so history survives later catalogue changes)
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('profession_id')->nullable();
            $table->string('profession_source', 24)->nullable();
            $table->unsignedBigInteger('area_id')->nullable();
            $table->string('district_key', 100)->nullable();
            // The moment the customer confirmed the payment: the instant the rules were judged at
            $table->dateTime('basis_at');
            // How the rule was chosen: every tier looked at and why (for audit)
            $table->json('trace')->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->unsignedTinyInteger('current_slot')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_report_id', 'revision_no'], 'shadow_snapshot_revision');
            $table->unique(['payment_report_id', 'current_slot'], 'shadow_snapshot_one_current');
            $table->index(['status', 'basis_at'], 'shadow_snapshot_period');
            $table->index('provider_profile_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('commission_shadow_snapshots');
    }
}
