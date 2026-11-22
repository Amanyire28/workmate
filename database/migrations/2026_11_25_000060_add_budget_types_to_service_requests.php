<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B1, customer budgets and the "inspection needed" flag. Only adds nullable columns, so every
 * existing request stays exactly as it is:
 *
 * - budget_type: fixed | range | negotiable | none. Empty on requests made before this: they are read
 *   from the older budget and budget_basis columns, which keep being written (older apps use them).
 * - budget_min / budget_max: a range budget. `budget` stays empty for a range.
 * - inspection_required_at / inspection_note: the provider says they must see the job before they can
 *   give a price. It is only a flag; no price, payment or commission is involved.
 *
 * No column is placed "after" another, so MariaDB can add them without rebuilding the table.
 */
class AddBudgetTypesToServiceRequests extends Migration
{
    public function up()
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('budget_type', 12)->nullable();
            $table->unsignedInteger('budget_min')->nullable();
            $table->unsignedInteger('budget_max')->nullable();
            $table->timestamp('inspection_required_at')->nullable();
            $table->string('inspection_note', 300)->nullable();
        });
    }

    public function down()
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['budget_type', 'budget_min', 'budget_max', 'inspection_required_at', 'inspection_note']);
        });
    }
}
