<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReportsTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Reports and complaints from customers and providers, about each other
        // or anything else. Confidential: the reported person is never told who
        // sent it, and it changes nothing until the safety team acts on it.
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            // customer | provider: the side the reporter was on
            $table->string('reporter_role', 10)->default('customer');
            // provider | customer | general
            $table->string('subject_type', 10);
            $table->foreignId('provider_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 30);
            $table->text('details')->nullable();
            // Evidence photos on the private disk: [{path, name, mime, size}]
            $table->json('attachments')->nullable();
            // high | medium | low, worked out from the reason
            $table->string('severity', 10)->default('low');
            // open | under_review | info_requested | resolved | closed
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // The safety team's question to the reporter, and their answer
            $table->text('info_request')->nullable();
            $table->text('reporter_reply')->nullable();
            // no_violation | warned | provider_suspended | account_suspended | resolved
            $table->string('outcome', 30)->nullable();
            // Shown to the reporter
            $table->text('outcome_message')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            // Copied from provider_reports
            $table->unsignedBigInteger('legacy_provider_report_id')->nullable()->unique();
            $table->timestamps();
            $table->index('provider_profile_id');
            $table->index('subject_user_id');
            $table->index('reporter_id');
        });

        // Warnings the safety team sends someone, shown in their account
        Schema::create('account_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('account_warnings');
        Schema::dropIfExists('reports');
    }
}
