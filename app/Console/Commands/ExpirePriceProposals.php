<?php

namespace App\Console\Commands;

use App\Services\PriceAgreements;
use Illuminate\Console\Command;

/**
 * Expires price proposals the customer didn't answer in time (finance.agreement_expiry_days). An expired
 * proposal is never an agreement. Safe to run any number of times: each one is expired, and the provider told, once.
 */
class ExpirePriceProposals extends Command
{
    protected $signature = 'workmate:expire-price-proposals';

    protected $description = 'Expire price proposals nobody answered in time (never treated as agreed)';

    public function handle(PriceAgreements $agreements): int
    {
        $this->info('Expired: '.$agreements->expireDue());

        return 0;
    }
}
