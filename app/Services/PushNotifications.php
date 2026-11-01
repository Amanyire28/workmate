<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Jobs\SendPushAnnouncement;
use App\Models\PushAnnouncement;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Push notifications to the installed app and browsers: devices turning them
 * on and off, and administrators' announcements to every subscribed device.
 */
class PushNotifications
{
    private const BATCH = 200;

    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /** True when the server has its VAPID keys (see php artisan workmate:push-keys) */
    public static function configured(): bool
    {
        return (bool) config('services.webpush.public_key') && (bool) config('services.webpush.private_key');
    }

    /** Saves this device's subscription for the user; a device signed in as someone else moves to them */
    public function subscribe(User $user, array $data): PushSubscription
    {
        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        $subscription = PushSubscription::where('endpoint_hash', $hash)->first() ?: new PushSubscription();
        $subscription->fill([
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'platform' => $data['platform'] ?? null,
        ]);
        $subscription->forceFill(['endpoint_hash' => $hash, 'user_id' => $user->getKey()])->save();

        return $subscription;
    }

    /** Removes this device's subscription, if it belongs to the user */
    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /** Records the announcement and sends it once the administrator's request has finished */
    public function announce(User $admin, array $data, string $reason): PushAnnouncement
    {
        if (! self::configured()) {
            throw new BusinessRuleException('push_not_configured', 'Push notifications aren’t set up on the server yet. Add the VAPID keys to .env first.', 503);
        }

        $recipients = PushSubscription::count();
        if ($recipients === 0) {
            throw new BusinessRuleException('no_subscribers', 'Nobody has turned on notifications yet, so there is no one to send to.');
        }

        $announcement = DB::transaction(function () use ($admin, $data, $reason, $recipients) {
            $announcement = new PushAnnouncement($data);
            $announcement->forceFill([
                'sent_by' => $admin->getKey(),
                'status' => 'sending',
                'recipients' => $recipients,
            ])->save();

            $this->audit->record('comms.push_announcement_sent', $announcement, [], [
                'title' => $announcement->title,
                'body' => $announcement->body,
                'url' => $announcement->url,
                'recipients' => $recipients,
            ], $reason, $admin);

            return $announcement;
        });

        SendPushAnnouncement::dispatchAfterResponse($announcement->id);

        return $announcement;
    }

    /**
     * Sends the announcement to every subscribed device in batches. Devices
     * the push service no longer knows are removed.
     */
    public function deliver(PushAnnouncement $announcement, WebPush $client): void
    {
        $payload = json_encode([
            'title' => $announcement->title,
            'body' => $announcement->body,
            'url' => $announcement->url ?: '/',
            'tag' => 'announcement-'.$announcement->id,
        ]);
        $delivered = $failed = $expired = 0;

        PushSubscription::query()->chunkById(self::BATCH, function ($subscriptions) use ($client, $payload, &$delivered, &$failed, &$expired) {
            $byEndpoint = [];
            foreach ($subscriptions as $s) {
                $byEndpoint[$s->endpoint] = $s;
                $client->queueNotification(new Subscription($s->endpoint, $s->public_key, $s->auth_token, $s->content_encoding), $payload, ['TTL' => 86400]);
            }

            foreach ($client->flush() as $report) {
                $subscription = $byEndpoint[$report->getEndpoint()] ?? null;
                if ($report->isSuccess()) {
                    $delivered++;
                    if ($subscription) {
                        $subscription->forceFill(['last_success_at' => now()])->save();
                    }
                } elseif ($report->isSubscriptionExpired()) {
                    $expired++;
                    if ($subscription) {
                        $subscription->delete();
                    }
                } else {
                    $failed++;
                }
            }
        });

        $announcement->forceFill([
            'status' => 'sent',
            'delivered' => $delivered,
            'failed' => $failed,
            'expired' => $expired,
            'completed_at' => now(),
        ])->save();
    }
}
