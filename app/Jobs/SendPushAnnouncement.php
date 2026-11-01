<?php

namespace App\Jobs;

use App\Models\PushAnnouncement;
use App\Services\PushNotifications;
use Illuminate\Foundation\Bus\Dispatchable;
use Minishlink\WebPush\WebPush;

/**
 * Sends an announcement after the administrator's request has been answered,
 * in the same process, so it works without a queue worker.
 */
class SendPushAnnouncement
{
    use Dispatchable;

    /** @var int */
    private $announcementId;

    public function __construct(int $announcementId)
    {
        $this->announcementId = $announcementId;
    }

    public function handle(PushNotifications $push, WebPush $client): void
    {
        $announcement = PushAnnouncement::find($this->announcementId);
        if ($announcement && $announcement->status === 'sending') {
            $push->deliver($announcement, $client);
        }
    }
}
