<?php

namespace App\Console\Commands;

use App\Services\PaymentDisputes;
use Illuminate\Console\Command;

/**
 * Phase B5: gives every payment dispute made before dispute cases existed its case, so administrators can find it. A case is
 * created "open" and nothing else: it is not escalated, nobody is notified, and the payment report is not touched. Safe to run
 * again and again (a report that has a case is skipped, and the database allows one case per report).
 */
class BackfillPaymentDisputes extends Command
{
    protected $signature = 'workmate:disputes-backfill';

    protected $description = 'Create cases for payment disputes made before dispute cases existed (no escalation, no notifications)';

    public function handle(PaymentDisputes $disputes): int
    {
        $this->info('Cases created: ' . $disputes->backfill());

        return self::SUCCESS;
    }
}
