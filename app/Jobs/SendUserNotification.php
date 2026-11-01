<?php

namespace App\Jobs;

use App\Services\PushNotifications;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;

/**
 * Sends a notification to some accounts after the response has gone back,
 * in the same process, so it works without a queue worker. A failure is
 * logged and never affects what the person was doing.
 */
class SendUserNotification
{
    use Dispatchable;

    /** @var int[] */
    private $userIds;

    /** @var array */
    private $message;

    public function __construct(array $userIds, array $message)
    {
        $this->userIds = $userIds;
        $this->message = $message;
    }

    public function handle(PushNotifications $push, WebPush $client): void
    {
        try {
            $push->deliverToUsers($this->userIds, $this->message, $client);
        } catch (\Throwable $e) {
            Log::warning('Push notification not sent: '.$e->getMessage(), ['tag' => $this->message['tag'] ?? null]);
        }
    }
}
