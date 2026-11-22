<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B1, what a provider says about price when applying for a posted job. All nullable, so earlier
 * applications stay as they are (`amount` is still the price they offered).
 *
 * - estimate_type: fixed | range | inspection_required. An estimate is a guide for the customer, never
 *   the price of the job; the price is agreed separately and confirmed by the customer.
 * - amount_max: the top of a range estimate (`amount` is the bottom).
 * - profession_id: the provider's own profession they applied as, checked by the server against the
 *   professions they hold in the job's category.
 */
class AddEstimatesToQuotations extends Migration
{
    public function up()
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('estimate_type', 20)->nullable();
            $table->unsignedInteger('amount_max')->nullable();
            $table->foreignId('profession_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profession_id');
            $table->dropColumn(['estimate_type', 'amount_max']);
        });
    }
}
