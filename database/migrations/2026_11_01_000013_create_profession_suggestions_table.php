<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProfessionSuggestionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // A profession an applicant typed under "Other". Customers can't find
        // them by it until an administrator adds it to the list or matches it
        // to an existing profession.
        Schema::create('profession_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // The category the applicant thinks it belongs to, if they chose one
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            // Place in the applicant's list of skills; 0 is their main one
            $table->unsignedTinyInteger('position')->default(0);
            $table->unsignedTinyInteger('years_experience')->nullable();
            // pending | added | matched | declined
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('profession_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['provider_profile_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('profession_suggestions');
    }
}
