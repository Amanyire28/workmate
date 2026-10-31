<?php

namespace App\Support;

/**
 * Normalises phone numbers to E.164 (+256772123456) so the same number
 * typed as "0772 123 456" or "+256 772 123456" is stored once.
 */
class PhoneNumber
{
    /** Returns the normalised number, or null when it isn't a valid number. */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/[\s\-().]/', '', $input);

        // Ugandan numbers: +256 / 256 / 0 / nothing, then 9 digits (mobile 7…, landline 3… or 4…)
        if (preg_match('/^(?:\+?256|0)?([347]\d{8})$/', $digits, $match)) {
            return '+256'.$match[1];
        }

        // Other countries: must be given in full international form
        if (preg_match('/^\+[1-9]\d{7,14}$/', $digits)) {
            return $digits;
        }

        return null;
    }
}
