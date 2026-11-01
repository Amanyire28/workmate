<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDocumentVerificationTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // One per provider. Approval sets provider_profiles.document_verified_at (the grey tick).
        Schema::create('document_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->unique()->constrained()->cascadeOnDelete();
            // draft | pending_review | under_review | info_requested | approved | rejected
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            // Shown to the provider
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });

        // Extra documents an administrator asked this provider for
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_verification_id')->constrained()->cascadeOnDelete();
            $table->string('label', 80);
            $table->string('instructions', 500)->nullable();
            // open | fulfilled | cancelled
            $table->string('status', 20)->default('open');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
        });

        // Files live on the private local disk; only their paths are stored here
        Schema::create('verification_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_verification_id')->constrained()->cascadeOnDelete();
            // national_id | supporting | requested
            $table->string('kind', 20);
            $table->string('label', 80);
            $table->foreignId('document_request_id')->nullable()->constrained()->nullOnDelete();
            // [{path, side, name, mime, size}]
            $table->json('files');
            $table->date('expires_on')->nullable();
            // awaiting_review | accepted | replacement_required | replaced | removed
            $table->string('status', 30)->default('awaiting_review');
            $table->string('review_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['document_verification_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('verification_documents');
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('document_verifications');
    }
}
