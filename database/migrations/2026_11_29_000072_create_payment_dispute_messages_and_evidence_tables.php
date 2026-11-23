<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B5. Two more NEW tables: what is said in a dispute, and the files attached to it. Both are append-only (guarded by
 * their models). Files live on the private disk under random names; this table holds the details, never a public address.
 */
class CreatePaymentDisputeMessagesAndEvidenceTables extends Migration
{
    public function up()
    {
        Schema::create('payment_dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_dispute_id')->constrained('payment_disputes')->restrictOnDelete();
            $table->unsignedBigInteger('author_id');
            // customer | provider | admin
            $table->string('author_role', 10);
            // message | info_request
            $table->string('kind', 14)->default('message');
            // for an information request: customer | provider | both
            $table->string('addressed_to', 10)->nullable();
            $table->text('body')->nullable();
            // A retry with the same token returns the first message
            $table->string('client_token', 64)->nullable();
            $table->dateTime('created_at');

            $table->index(['payment_dispute_id', 'id'], 'payment_dispute_messages_case');
            $table->unique(['payment_dispute_id', 'client_token'], 'payment_dispute_messages_once');
        });

        Schema::create('payment_dispute_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_dispute_id')->constrained('payment_disputes')->restrictOnDelete();
            $table->foreignId('message_id')->constrained('payment_dispute_messages')->restrictOnDelete();
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->string('uploader_role', 10);
            $table->char('uuid', 36)->unique();
            $table->string('path', 255);
            $table->string('original_name', 120)->nullable();
            // image/jpeg | application/pdf
            $table->string('mime', 40);
            $table->unsignedInteger('size');
            $table->dateTime('created_at');

            $table->index(['payment_dispute_id', 'id'], 'payment_dispute_evidence_case');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_dispute_evidence');
        Schema::dropIfExists('payment_dispute_messages');
    }
}
