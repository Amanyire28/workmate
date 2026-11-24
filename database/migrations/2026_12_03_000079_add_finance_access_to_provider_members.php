<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B9, who in a provider business may see its money. One additive column on provider_members, false for everyone by default:
 *
 *   can_view_finances   the business owner always has access; any other member (manager or not) has it only when the owner has
 *                       switched it on for them. No row is changed by this migration (existing members all read as "no").
 *
 * Nothing about prices, payments or commission is touched.
 */
class AddFinanceAccessToProviderMembers extends Migration
{
    public function up()
    {
        Schema::table('provider_members', function (Blueprint $table) {
            $table->boolean('can_view_finances')->default(false)->after('role');
        });
    }

    public function down()
    {
        Schema::table('provider_members', function (Blueprint $table) {
            $table->dropColumn('can_view_finances');
        });
    }
}
