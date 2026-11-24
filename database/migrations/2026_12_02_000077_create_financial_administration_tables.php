<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B8, financial administration. Additive only:
 *
 *   commission_adjustment_requests   a proposed correction to what a provider owes, with the two-step decision on it. It is a RECORD of a
 *                                    request and of the decision: nothing here writes to the ledger (posting is not built until the
 *                                    correction policy is approved), so no balance can change because of a row in this table.
 *   commission_exception_events      the append-only history of an exception being investigated (taken, noted, resolved).
 *   commission_exceptions            five NULLable columns added to the B7 table (who has it, and how and by whom it was resolved), and its status
 *                                    column widened from 10 to 16 characters so it can say "under_review".
 *
 * The B6/B7 ledger, its foreign keys and its CHECK are not touched.
 */
class CreateFinancialAdministrationTables extends Migration
{
    public function up()
    {
        Schema::create('commission_adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            // accounting_error | obligation_correction | credit_allocation_correction | reconciliation_correction
            $table->string('type', 30);
            // increase | decrease: what the proposer says should happen to the amount the provider owes
            $table->string('direction', 8);
            $table->unsignedInteger('amount_ugx');
            // What it is about (any of these; at least one is required by the service)
            $table->unsignedBigInteger('assessment_id')->nullable();
            $table->unsignedBigInteger('commission_payment_id')->nullable();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->unsignedBigInteger('exception_id')->nullable();
            $table->unsignedBigInteger('payment_report_id')->nullable();
            $table->unsignedBigInteger('dispute_id')->nullable();
            // Free-text pointers (statement lines, transaction ids, case numbers)
            $table->string('reference', 200)->nullable();
            $table->text('reason');
            // For the provider, if it is ever posted: a plain sentence with no internal detail
            $table->string('provider_reason', 300)->nullable();
            $table->string('client_token', 64)->nullable();

            // pending | approved | rejected
            $table->string('status', 10)->default('pending');
            // The same request cannot be open twice: set while pending or approved, NULL once rejected
            $table->string('dedupe_key', 64)->nullable();
            $table->unsignedBigInteger('requested_by_user_id');
            $table->dateTime('requested_at');
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            // Reserved for the posting step that is not built: always 'not_posted' for now
            $table->string('posting_status', 12)->default('not_posted');
            $table->timestamps();

            $table->unique('dedupe_key', 'commission_adjustment_open_once');
            $table->unique(['requested_by_user_id', 'client_token'], 'commission_adjustment_retry_once');
            $table->index(['status', 'id'], 'commission_adjustments_queue');
            $table->index(['provider_profile_id', 'id'], 'commission_adjustments_provider');
        });

        Schema::create('commission_exception_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exception_id')->constrained('commission_exceptions')->restrictOnDelete();
            // taken | noted | released | resolved
            $table->string('event', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->string('reference', 200)->nullable();
            $table->dateTime('created_at');

            $table->index(['exception_id', 'id'], 'commission_exception_events_case');
        });

        // B7 made status VARCHAR(10), which cannot hold "under_review". Widening only (a longer limit changes no value), on a table that is empty
        // in production because B7's switch is off.
        DB::statement("ALTER TABLE commission_exceptions MODIFY status VARCHAR(16) NOT NULL DEFAULT 'open'");
        // B7 made status varchar(10); "under_review" needs 12. Widening a text column loses nothing.
        DB::statement("ALTER TABLE commission_exceptions MODIFY status VARCHAR(16) NOT NULL DEFAULT 'open'");
        Schema::table('commission_exceptions', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_to_user_id')->nullable()->after('detail');
            // no_financial_change (the only resolution that exists: a refund, transfer or credit allocation needs an approved policy)
            $table->string('resolution', 24)->nullable()->after('assigned_to_user_id');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable()->after('resolution');
            $table->dateTime('resolved_at')->nullable()->after('resolved_by_user_id');
            $table->string('resolution_note', 500)->nullable()->after('resolved_at');
        });
    }

    public function down()
    {
        // status is left at 16 characters on purpose: shrinking it could fail on a stored value
        Schema::table('commission_exceptions', function (Blueprint $table) {
            $table->dropColumn(['assigned_to_user_id', 'resolution', 'resolved_by_user_id', 'resolved_at', 'resolution_note']);
        });
        // status is left at 16 characters on purpose: shrinking it could truncate a value
        Schema::dropIfExists('commission_exception_events');
        Schema::dropIfExists('commission_adjustment_requests');
    }
}
