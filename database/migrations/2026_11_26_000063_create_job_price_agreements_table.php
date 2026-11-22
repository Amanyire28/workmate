<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B2, price proposals and the agreed price. A NEW table only: no existing table is changed.
 *
 * Every row is one immutable VERSION of a price proposal for a request (amount, scope and exclusions never
 * change once saved; only the decision fields move). A revised price is a new row, never an edit.
 *
 * status: pending_customer | revision_requested | agreed | rejected | expired | cancelled | superseded
 *
 * Two nullable "slot" columns let the database itself refuse an impossible state:
 *  - open_slot   = 1 while the version is open (pending_customer or revision_requested): at most one per request
 *  - agreed_slot = 1 while the version is the agreed price in force: at most one per request
 * (unique with the request; NULLs don't collide, so closed versions pile up freely.)
 *
 * category_id, area_id, area_name and the profession are a snapshot of what the job was when the price was
 * proposed, for later financial phases. They are deliberately NOT foreign keys, so the history survives
 * a later change to the catalogue. Amounts are whole UGX (integers), never fractions.
 */
class CreateJobPriceAgreementsTable extends Migration
{
    public function up()
    {
        Schema::create('job_price_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('revision_no');
            $table->unsignedInteger('amount_ugx');
            $table->text('scope_text');
            $table->text('exclusions_text')->nullable();
            $table->string('status', 20);
            // Proposed while a price was already agreed: the agreed price stays in force until this is accepted
            $table->boolean('is_amendment')->default(false);
            // Proposed after the provider had said they must inspect first
            $table->boolean('after_inspection')->default(false);
            $table->unsignedBigInteger('proposed_by_user_id');
            $table->timestamp('proposed_at');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('responded_by_user_id')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('response_note', 300)->nullable();
            // What the customer would like, said when asking for a change: a suggestion, never an offer
            $table->unsignedInteger('suggested_amount_ugx')->nullable();
            $table->string('closed_reason', 30)->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('area_id')->nullable();
            $table->string('area_name')->nullable();
            $table->unsignedBigInteger('profession_id')->nullable();
            $table->string('profession_source', 24)->nullable();
            $table->boolean('profession_review_needed')->default(false);
            // One per try of a proposal, so a retry after a dropped connection can't create a copy
            $table->string('client_token', 40)->nullable();
            $table->unsignedTinyInteger('open_slot')->nullable();
            $table->unsignedTinyInteger('agreed_slot')->nullable();
            $table->timestamps();

            $table->unique(['service_request_id', 'revision_no'], 'price_agreements_revision');
            $table->unique(['service_request_id', 'client_token'], 'price_agreements_token');
            $table->unique(['service_request_id', 'open_slot'], 'price_agreements_one_open');
            $table->unique(['service_request_id', 'agreed_slot'], 'price_agreements_one_agreed');
            $table->index(['status', 'expires_at'], 'price_agreements_expiry');
            $table->index('provider_profile_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('job_price_agreements');
    }
}
