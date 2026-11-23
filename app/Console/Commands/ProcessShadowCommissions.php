<?php

namespace App\Console\Commands;

use App\Services\CommissionShadow;
use Illuminate\Console\Command;

/**
 * SHADOW mode only: makes sure every customer-confirmed payment has its estimated commission (including payments
 * confirmed before shadow mode was switched on), and takes a fresh look at those excluded for a missing or ambiguous rate
 * rule when a rule has since been added or changed. Estimates only: nothing is charged and nobody is notified. Does
 * nothing while the shadow switch is off. Safe to run any number of times; an existing estimate is never changed.
 */
class ProcessShadowCommissions extends Command
{
    protected $signature = 'workmate:commission-shadow {--limit=500 : Most payments to look at in one run}';

    protected $description = 'Work out estimated (shadow) commissions for customer-confirmed payments (estimates only, nothing is collected)';

    public function handle(CommissionShadow $shadow): int
    {
        if (! $shadow->enabled()) {
            $this->info('Shadow commission calculations are off (finance.commission_shadow_enabled). Nothing done.');

            return 0;
        }
        $counts = $shadow->processPending(max(1, (int) $this->option('limit')));
        $this->info('Created: '.$counts['created'].'  Superseded: '.$counts['superseded'].'  Unchanged: '.$counts['unchanged'].'  (estimates only, not collected)');

        return 0;
    }
}
