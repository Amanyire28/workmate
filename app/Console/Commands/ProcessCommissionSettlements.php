<?php

namespace App\Console\Commands;

use App\Services\CommissionSettlements;
use Illuminate\Console\Command;

/** Phase B7: puts recorded obligations into their settlement cycles and sends each due reminder once. Does nothing while switched off. Collects nothing. */
class ProcessCommissionSettlements extends Command
{
    protected $signature = 'workmate:commission-settlements {--limit=300}';

    protected $description = 'Allocate commission obligations to settlement cycles and send due reminders (off by default)';

    public function handle(CommissionSettlements $settlements): int
    {
        if (! $settlements->enabled()) {
            $this->info('Commission settlement is switched off: nothing to do.');

            return self::SUCCESS;
        }
        $r = $settlements->catchUp(max(1, (int) $this->option('limit')));
        $this->info('Allocated: ' . $r['allocated'] . '  Reminders sent: ' . $r['reminders'] . ' (nothing is collected)');

        return self::SUCCESS;
    }
}
