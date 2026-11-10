<?php

namespace App\Listeners;

use App\Services\Referrals;
use Illuminate\Auth\Events\Verified;

/** Confirming an email is when an invitation can count */
class QualifyReferral
{
    /** @var Referrals */
    private $referrals;

    public function __construct(Referrals $referrals)
    {
        $this->referrals = $referrals;
    }

    public function handle(Verified $event): void
    {
        $this->referrals->emailConfirmed($event->user);
    }
}
