<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Jobs\SendPushAnnouncement;
use App\Jobs\SendUserNotification;
use App\Models\PushAnnouncement;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Push notifications to the installed app and browsers: devices turning them
 * on and off, administrators' announcements to every subscribed device, and
 * notifications to particular accounts when something happens to them.
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

    /**
     * Saves this device's subscription for the user; a device signed in as
     * someone else moves to them (and stops receiving the other person's).
     * The sign-in session is remembered (hashed) so signing out can remove it.
     */
    public function subscribe(User $user, array $data, ?string $sessionId = null): PushSubscription
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
        $subscription->forceFill([
            'endpoint_hash' => $hash,
            'user_id' => $user->getKey(),
            'session_hash' => self::sessionHash($sessionId),
        ])->save();

        return $subscription;
    }

    /**
     * Signing out: removes this device's subscription, found by its address
     * (sent by the page) or by the session it was saved in. Only the user's
     * own; their other devices keep theirs.
     *
     * @return int how many were removed
     */
    public function forgetDevice(User $user, ?string $endpoint, ?string $sessionId): int
    {
        $endpointHash = $endpoint ? PushSubscription::hashEndpoint($endpoint) : null;
        $sessionHash = self::sessionHash($sessionId);
        if (! $endpointHash && ! $sessionHash) {
            return 0;
        }

        return PushSubscription::where('user_id', $user->getKey())
            ->where(function ($q) use ($endpointHash, $sessionHash) {
                if ($endpointHash) {
                    $q->orWhere('endpoint_hash', $endpointHash);
                }
                if ($sessionHash) {
                    $q->orWhere('session_hash', $sessionHash);
                }
            })
            ->delete();
    }

    private static function sessionHash(?string $sessionId): ?string
    {
        return $sessionId ? hash('sha256', $sessionId) : null;
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
        [$delivered, $failed, $expired] = $this->send(PushSubscription::query(), [
            'title' => $announcement->title,
            'body' => $announcement->body,
            'url' => $announcement->url ?: '/',
            'tag' => 'announcement-'.$announcement->id,
        ], $client);

        $announcement->forceFill([
            'status' => 'sent',
            'delivered' => $delivered,
            'failed' => $failed,
            'expired' => $expired,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Notifies these accounts on every device where they turned notifications
     * on, after the response has gone back. Nothing happens when push isn't
     * set up. Call it as the last step of a change, so it isn't sent for a
     * change that then fails.
     *
     * @param  iterable<int>  $userIds
     * @param  array{title: string, body: string, url: string, tag: string}  $message
     * @param  string  $urgency  very-low | low | normal | high (Web Push Urgency: how hard the phone tries to wake up for it)
     */
    public function toUsers(iterable $userIds, array $message, string $urgency = 'normal'): void
    {
        $ids = collect($userIds)->filter()->map(function ($id) {
            return (int) $id;
        })->unique()->values()->all();

        if (! $ids || ! self::configured()) {
            return;
        }

        // Sent once the response has gone back, by which time the change is saved
        SendUserNotification::dispatchAfterResponse($ids, $message, $urgency);
    }

    /**
     * @param  int[]  $userIds
     * @return int[] [delivered, failed, expired]
     */
    public function deliverToUsers(array $userIds, array $message, WebPush $client, string $urgency = 'normal'): array
    {
        return $this->send(PushSubscription::whereIn('user_id', $userIds), $message, $client, $urgency);
    }

    /** Sends right now, in this process (for the scheduled flush). [0, 0, 0] when push isn't set up. */
    public function deliverNow(array $userIds, array $message, string $urgency = 'normal'): array
    {
        if (! self::configured()) {
            return [0, 0, 0];
        }

        return $this->deliverToUsers($userIds, $message, app(WebPush::class), $urgency);
    }

    /** @return int[] [delivered, failed, expired] */
    private function send($subscriptions, array $message, WebPush $client, string $urgency = 'normal'): array
    {
        $payload = json_encode($message);
        $delivered = $failed = $expired = 0;
        $forgetAfter = (int) config('notification_policy.forget_device_after_failures', 8);

        $subscriptions->chunkById(self::BATCH, function ($batch) use ($client, $payload, $urgency, $forgetAfter, &$delivered, &$failed, &$expired) {
            $byEndpoint = [];
            foreach ($batch as $s) {
                $byEndpoint[$s->endpoint] = $s;
                $client->queueNotification(new Subscription($s->endpoint, $s->public_key, $s->auth_token, $s->content_encoding), $payload, ['TTL' => 86400, 'urgency' => $urgency]);
            }

            foreach ($client->flush() as $report) {
                $subscription = $byEndpoint[$report->getEndpoint()] ?? null;
                if ($report->isSuccess()) {
                    $delivered++;
                    if ($subscription) {
                        $subscription->forceFill(['last_success_at' => now(), 'failure_count' => 0])->save();
                    }
                } elseif ($report->isSubscriptionExpired()) {
                    $expired++;
                    if ($subscription) {
                        $subscription->delete();
                    }
                } else {
                    $failed++;
                    // A device that keeps failing is forgotten (it can turn notifications on again)
                    if ($subscription) {
                        if ($subscription->failure_count + 1 >= $forgetAfter) {
                            $subscription->delete();
                        } else {
                            $subscription->forceFill(['failure_count' => $subscription->failure_count + 1])->save();
                        }
                    }
                }
            }
        });

        return [$delivered, $failed, $expired];
    }
}
