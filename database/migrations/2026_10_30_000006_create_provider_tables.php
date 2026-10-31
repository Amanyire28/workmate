<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProviderTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // A customer account gains provider capabilities through this profile.
        // No profile means "not applied".
        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->restrictOnDelete();
            // individual | group | company
            $table->string('type', 20);
            $table->string('display_name');
            $table->text('bio')->nullable();
            $table->string('business_registration_number', 50)->nullable();
            // draft | pending_review | more_info_required | approved | rejected | suspended
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Groups and companies can later have several members with their own roles
        Schema::create('provider_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // owner | manager | member
            $table->string('role', 20);
            $table->timestamps();
            $table->unique(['provider_profile_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('provider_professions', function (Blueprint $table) {
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profession_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('years_experience')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->primary(['provider_profile_id', 'profession_id']);
            $table->index('profession_id');
        });

        // One row per review round. The snapshot keeps what was submitted,
        // even if the profile changes afterwards.
        Schema::create('provider_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            // pending_review | more_info_required | approved | rejected
            $table->string('status', 30)->index();
            $table->json('snapshot');
            $table->text('applicant_note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            // Shown to the applicant
            $table->text('decision_reason')->nullable();
            // Administrators only
            $table->text('internal_note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('provider_applications');
        Schema::dropIfExists('provider_professions');
        Schema::dropIfExists('provider_members');
        Schema::dropIfExists('provider_profiles');
    }
}
