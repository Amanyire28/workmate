<?php

namespace App\Console\Commands;

use App\Models\NotificationPush;
use App\Models\PushSubscription;
use App\Services\PushDelivery;
use App\Services\PushNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Shows whether the notification pipeline is healthy: is smart push on, is the
 * scheduler really running the flush, what is waiting, and what has failed.
 * Prints counts only, never message content or addresses.
 */
class NotificationsStatus extends Command
{
    protected $signature = 'workmate:notifications-status';

    protected $description = 'Show smart push status: switch, last scheduled flush, waiting and failed pushes';

    public function handle(PushDelivery $delivery): int
    {
        $this->line('Smart push setting:   '.($delivery->smartEnabled() ? 'ON' : 'off'));
        $this->line('Web Push keys:        '.(PushNotifications::configured() ? 'set' : 'NOT set'));
        $this->line('Devices subscribed:   '.PushSubscription::count());

        $last = Cache::get('notifications:flush:last_run');
        if ($last) {
            $minutes = now()->diffInMinutes($last['at']);
            $this->line('Last flush:           '.$last['at'].' ('.$minutes.' min ago)'.($minutes > 5 ? '  <-- the scheduler may NOT be running' : ''));
        } else {
            $this->line('Last flush:           never recorded (the scheduler has not run workmate:notifications-flush since the cache was last cleared)');
        }

        $this->newLine();
        $rows = NotificationPush::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        foreach ([NotificationPush::PENDING, NotificationPush::SENDING, NotificationPush::SENT, NotificationPush::SKIPPED, NotificationPush::GAVE_UP] as $status) {
            $this->line(str_pad($status, 10).($rows[$status] ?? 0));
        }
        $overdue = NotificationPush::where('status', NotificationPush::PENDING)->where('send_after', '<', now()->subMinutes(10))->count();
        $this->line('Overdue pending (more than 10 minutes late): '.$overdue.($overdue ? '  <-- the flush is not keeping up or not running' : ''));

        return self::SUCCESS;
    }
}
