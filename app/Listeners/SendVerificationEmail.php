<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Throwable;

/**
 * Sends the verification email after registration. If email can't be sent
 * (for example mail isn't configured yet), the account is still created and
 * the failure is logged; the user can ask for another email later.
 */
class SendVerificationEmail
{
    public function handle(Registered $event)
    {
        if (! $event->user instanceof MustVerifyEmail || $event->user->hasVerifiedEmail()) {
            return;
        }

        try {
            $event->user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
