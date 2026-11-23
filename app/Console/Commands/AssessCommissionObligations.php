<?php

namespace App\Console\Commands;

use App\Services\CommissionAssessments;
use App\Services\CommissionShadow;
use Illuminate\Console\Command;

/**
 * Phase B6: records commission obligations that were missed and reverses those whose payment stopped counting. Does nothing unless
 * the obligation switch is on and a start moment is chosen. Safe to run again and again. Collects nothing.
 */
class AssessCommissionObligations extends Command
{
    protected $signature = 'workmate:commission-assess {--limit=300}';

    protected $description = 'Record missed commission obligations and reverse those whose payment stopped counting (off by default)';

    public function handle(CommissionAssessments $assessments, CommissionShadow $shadow): int
    {
        if (! $assessments->enabled()) {
            $this->info('Commission obligations are switched off: nothing to do.');

            return self::SUCCESS;
        }
        $result = $assessments->catchUp($shadow, max(1, (int) $this->option('limit')));
        $this->info('Checked: '.$result['checked'].' (obligations only; nothing is collected)');

        return self::SUCCESS;
    }
}
