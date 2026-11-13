<?php

namespace App\Console\Commands;

use App\Services\BookingReminders;
use Illuminate\Console\Command;

/** Sends the booking reminders that are due (config/engagement.php). Safe to run any number of times. */
class SendBookingReminders extends Command
{
    protected $signature = 'workmate:reminders';

    protected $description = 'Send due booking reminders (the day before and shortly before, each once)';

    public function handle(BookingReminders $reminders): int
    {
        $report = $reminders->run();
        if (! $report) {
            $this->info('Nothing due.');

            return 0;
        }
        foreach ($report as $what => $n) {
            $this->line(str_pad($what, 34).$n);
        }

        return 0;
    }
}
