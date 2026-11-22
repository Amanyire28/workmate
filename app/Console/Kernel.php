<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Booking reminders (config/engagement.php): each is sent once, so overlapping or repeated runs are harmless
        $schedule->command('workmate:reminders')->everyFifteenMinutes()->withoutOverlapping(30);
        // Unfinished video uploads and deleted posts' videos (Cloudflare R2)
        $schedule->command('workmate:video-cleanup')->hourly()->withoutOverlapping(60);
        // Price proposals nobody answered in time are expired (never agreed); each is expired once, so repeated runs are harmless
        $schedule->command('workmate:expire-price-proposals')->hourly()->withoutOverlapping(30);
        // Grouped like and follow pushes, quiet-hours summaries and push retries (only while notifications.smart_push is on).
        // Without this running, a like or follow anywhere on the site still nudges a small flush, just later.
        $schedule->command('workmate:notifications-flush')->everyMinute()->withoutOverlapping(5);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
