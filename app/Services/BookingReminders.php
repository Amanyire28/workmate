<?php

namespace App\Services;

use App\Models\ProviderMember;
use App\Models\PushSubscription;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;

/**
 * Reminders before confirmed bookings, run by the scheduler
 * (workmate:reminders): the day before, and shortly before. Timings live in
 * config/engagement.php.
 *
 * - Each reminder is claimed in engagement_reminders before it's sent (unique
 *   per person, reminder and booking), so a second run, or two at once,
 *   sends nothing more.
 * - Only bookings still confirmed at that moment: a cancelled or moved one
 *   gets nothing (a moved one is reminded at its new time).
 * - Delivery goes through the existing notifications (bell and, where people
 *   turned it on, push) and follows the "Requests and jobs" choice.
 *
 * Suggestions (finish your profile, add services...) are never sent: they're
 * shown as cards in the pages instead (App\Services\Suggestions).
 */
class BookingReminders
{
    /** @var UserNotifications */
    private $notifications;

    /** @var Scheduling */
    private $scheduling;

    /** @var array<string, int> what this run did */
    private $report = [];

    public function __construct(UserNotifications $notifications, Scheduling $scheduling)
    {
        $this->notifications = $notifications;
        $this->scheduling = $scheduling;
    }

    /** @return array<string, int> how many of each were sent */
    public function run(): array
    {
        $this->report = [];
        $config = config('engagement.bookings');
        $windows = [];
        if (! empty($config['day_before']['enabled'])) {
            // From the day before down to two hours before (shorter notice gets the final one only)
            $windows['booking_24h'] = [now()->addHours(2), now()->addHours((int) $config['day_before']['hours_before'])];
        }
        if (! empty($config['soon']['enabled'])) {
            $windows['booking_1h'] = [now(), now()->addMinutes((int) $config['soon']['minutes_before'])];
        }

        foreach ($windows as $type => [$from, $to]) {
            ServiceRequest::with(['provider', 'customer', 'category'])
                ->where('status', ServiceRequest::STATUS_SCHEDULED)
                ->whereNotNull('scheduled_start_at')
                ->where('scheduled_start_at', '>', $from)->where('scheduled_start_at', '<=', $to)
                ->orderBy('id')->chunkById(200, function ($requests) use ($type) {
                    foreach ($requests as $r) {
                        $this->remind($r, $type);
                    }
                });
        }

        return $this->report;
    }

    private function remind(ServiceRequest $r, string $type): void
    {
        $when = $this->scheduling->label($r->scheduled_start_at);
        $soon = $type === 'booking_1h';
        $service = $r->title ?: optional($r->category)->name;
        // Per booking time, so a booking moved to a new time is reminded again
        $occurrence = 'request:'.$r->id.'@'.$r->scheduled_start_at->timestamp;

        // The customer
        $this->deliver($r->customer_id, $type, $occurrence, [
            'title' => $soon ? 'Your booking is soon' : 'Upcoming booking',
            'body' => optional($r->provider)->display_name.' is booked for '.$service.': '.$when.'.',
            'url' => '/activity?tab=requests',
            'tag' => 'booking-'.$r->id,
            'important' => $soon,
        ]);

        // Everyone in the provider business
        foreach (ProviderMember::where('provider_profile_id', $r->provider_profile_id)->pluck('user_id') as $memberId) {
            $this->deliver((int) $memberId, $type, $occurrence, [
                'title' => $soon ? 'Your booking is soon' : 'Booking reminder',
                'body' => $service.' for '.$this->firstName(optional($r->customer)->name).': '.$when.' in '.$r->area_name.'.',
                'url' => '/activity?tab=received',
                'tag' => 'booking-'.$r->id,
                'important' => $soon,
            ]);
        }
    }

    /** Claims the reminder (once, ever, for this booking time), then sends it */
    private function deliver(int $userId, string $type, string $occurrence, array $message): void
    {
        $claimed = DB::table('engagement_reminders')->insertOrIgnore([
            'user_id' => $userId, 'type' => $type, 'occurrence' => $occurrence,
            'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $claimed) {
            return;   // already sent (another run, or earlier)
        }

        $channels = ['suppressed'];
        if ($this->notifications->wants($userId, 'requests')) {
            $this->notifications->send('requests', [$userId], $message);
            $channels = ['in_app'];
            if (PushNotifications::configured() && PushSubscription::where('user_id', $userId)->exists()) {
                $channels[] = 'push';
            }
        }
        DB::table('engagement_reminders')->where('user_id', $userId)->where('type', $type)->where('occurrence', $occurrence)
            ->update(['channels' => json_encode($channels), 'updated_at' => now()]);
        $this->report[$type] = ($this->report[$type] ?? 0) + 1;
    }

    private function firstName(?string $name): string
    {
        return $name ? explode(' ', trim($name))[0] : 'a customer';
    }
}
