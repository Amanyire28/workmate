<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B4, SHADOW-mode commission rate rules. A NEW table only: no existing table is changed.
 *
 * A rule says "this percentage would apply to payments in this scope between these dates". It is used only to
 * ESTIMATE what WorkMate could earn. It never charges anyone: no provider owes anything because of a rule, and a
 * rule can't make a charge enforceable (a separate, approved phase would be needed for that).
 *
 * scope_type: global | provider | profession | category | area | district. scope_ref holds the id of the provider,
 * profession, category or area; scope_key holds a district's normalised name; scope_hash ("category:12",
 * "district:wakiso", "global") identifies the scope. A rule's rate is basis points (500 = 5%), never a float.
 *
 * Rules are versioned and never edited: replacing one saves version + 1 and ends the old one at the moment the new
 * one starts; retiring one ends it from that moment on. A payment is judged by the rule in force when the customer
 * confirmed it, so changing or ending a rule never rewrites an earlier calculation. Date-times are DATETIME columns
 * (not TIMESTAMP), so no server setting can change them behind the application's back.
 */
class CreateCommissionShadowPoliciesTable extends Migration
{
    public function up()
    {
        Schema::create('commission_shadow_policies', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 12);
            $table->unsignedBigInteger('scope_ref')->nullable();
            $table->string('scope_key', 100)->nullable();
            $table->string('scope_hash', 140);
            $table->unsignedSmallInteger('version');
            $table->unsignedSmallInteger('rate_bp');
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            // active | retired (retired: it stops applying from retired_at on)
            $table->string('status', 10);
            $table->dateTime('retired_at')->nullable();
            $table->string('retired_reason', 300)->nullable();
            $table->unsignedBigInteger('retired_by_user_id')->nullable();
            $table->unsignedBigInteger('replaces_id')->nullable();
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->string('reason', 300);
            $table->unsignedBigInteger('created_by_user_id');
            $table->string('content_hash', 64);
            $table->timestamps();

            $table->unique(['scope_hash', 'version'], 'shadow_policy_version');
            $table->index(['scope_hash', 'effective_from'], 'shadow_policy_scope');
        });
    }

    public function down()
    {
        Schema::dropIfExists('commission_shadow_policies');
    }
}
