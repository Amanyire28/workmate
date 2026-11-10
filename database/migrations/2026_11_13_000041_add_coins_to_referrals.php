<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite Friends coins: the coins a referral earned, fixed when it counted so
 * a later change to the rate doesn't rewrite what people already earned.
 * A balance is the coins of someone's referrals that still count.
 */
class AddCoinsToReferrals extends Migration
{
    public function up()
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->unsignedInteger('coins')->nullable()->after('status');
        });

        // Referrals that already count earn the standard 200. Safe to run again:
        // only counted referrals without coins are touched.
        DB::table('referrals')->where('status', 'qualified')->whereNull('coins')->orderBy('id')->select('id')
            ->chunkById(500, function ($rows) {
                DB::table('referrals')->whereIn('id', $rows->pluck('id'))->whereNull('coins')->update(['coins' => 200]);
            });
    }

    public function down()
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropColumn('coins');
        });
    }
}
