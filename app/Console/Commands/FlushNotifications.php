<?php

namespace App\Console\Commands;

use App\Services\PushDelivery;
use Illuminate\Console\Command;

/**
 * Sends the pushes that are due (grouped likes and follows, the summary after
 * quiet hours), retries ones no device accepted, makes ready again any that a
 * dead process had claimed, and tidies old rows. Run every minute by the
 * scheduler. Safe to run by hand, twice, or overlapping: each push is claimed
 * with one atomic update before it is sent. With smart push off it only skips
 * what is waiting.
 */
class FlushNotifications extends Command
{
    protected $signature = 'workmate:notifications-flush {--limit= : Most pushes to send in this run (default from config/notification_policy.php)}';

    protected $description = 'Send due grouped pushes, retry failed ones and tidy old rows (runs every minute from the scheduler)';

    public function handle(PushDelivery $delivery): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('notification_policy.flush_batch')));
        $stats = $delivery->flushDue($limit);
        $this->line(collect($stats)->map(function ($n, $k) {
            return $k.'='.$n;
        })->implode(' '));

        return self::SUCCESS;
    }
}
