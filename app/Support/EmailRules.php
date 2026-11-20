<?php

namespace App\Support;

/**
 * Email address rules. Laravel 8's plain "email" rule lets line breaks
 * through (a known advisory: CRLF injection into email headers), so no
 * address with control characters is ever accepted.
 */
class EmailRules
{
    // Line breaks, tabs and other control characters
    public const NO_CONTROL_CHARACTERS = 'not_regex:/[\x00-\x1F\x7F]/';

    /** A new address that will be stored and emailed: strict RFC check plus PHP's own filter */
    public static function new(int $max = 255): array
    {
        return ['string', 'email:rfc,filter_unicode', 'max:'.$max, self::NO_CONTROL_CHARACTERS];
    }

    /**
     * An address typed to sign in or reset a password: only what could be
     * dangerous is refused, so no existing account is locked out.
     */
    public static function existing(): array
    {
        return ['string', 'email', 'max:255', self::NO_CONTROL_CHARACTERS];
    }
}
