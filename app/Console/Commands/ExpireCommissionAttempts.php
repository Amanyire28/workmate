<?php

namespace App\Console\Commands;

use App\Services\CommissionCollections;
use Illuminate\Console\Command;

/** Phase B10: gateway payment requests nobody approved in time are expired (never credited). Each is expired once. */
class ExpireCommissionAttempts extends Command
{
    protected $signature = 'workmate:expire-commission-attempts';

    protected $description = 'Expire commission payment requests that were not approved in time';

    public function handle(CommissionCollections $collections): int
    {
        $this->info('Expired: '.$collections->expireDue());

        return self::SUCCESS;
    }
}
