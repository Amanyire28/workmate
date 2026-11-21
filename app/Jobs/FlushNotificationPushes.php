<?php

namespace App\Jobs;

use App\Services\PushDelivery;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * A small flush of due pushes after a like or follow has been answered, so
 * grouped pushes still go out if the scheduler isn't running. Runs in the same
 * process (no queue worker); a failure is logged and affects no one.
 */
class FlushNotificationPushes
{
    use Dispatchable;

    /** @var int */
    private $limit;

    public function __construct(int $limit)
    {
        $this->limit = $limit;
    }

    public function handle(PushDelivery $delivery): void
    {
        try {
            $delivery->flushDue($this->limit);
        } catch (\Throwable $e) {
            Log::warning('Notification flush failed: '.$e->getMessage());
        }
    }
}
