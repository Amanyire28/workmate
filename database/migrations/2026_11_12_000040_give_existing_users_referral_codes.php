<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every existing account its referral code, in batches. Safe to run
 * again: only accounts without a code get one, and nothing else changes.
 * No referrals are created: there's no record of who invited whom before.
 */
class GiveExistingUsersReferralCodes extends Migration
{
    // Letters and digits that can't be mistaken for each other (no I, O, 0 or 1)
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function up()
    {
        DB::table('users')->whereNull('referral_code')->orderBy('id')->select('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                do {
                    $code = 'WM-';
                    for ($i = 0; $i < 6; $i++) {
                        $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
                    }
                } while (DB::table('users')->where('referral_code', $code)->exists());

                DB::table('users')->where('id', $user->id)->whereNull('referral_code')->update(['referral_code' => $code]);
            }
        });
    }

    public function down()
    {
        // Codes stay: people may already have shared their links
    }
}
