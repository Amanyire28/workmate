<?php

namespace App\Jobs;

use App\Services\PushDelivery;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Tries waiting pushes after a response has gone back: the particular ones an event just saved
 * (the immediate attempt), or a small flush of whatever is due. Runs in the same process (no
 * queue worker). The pushes are saved before this runs, so if it never does (the process dies),
 * the scheduler's next run finds them: this is a shortcut, not the safety.
 */
class FlushNotificationPushes
{
    use Dispatchable;

    /** @var int */
    private $limit;

    /** @var int[] */
    private $ids;

    public function __construct(int $limit, array $ids = [])
    {
        $this->limit = $limit;
        $this->ids = $ids;
    }

    public function handle(PushDelivery $delivery): void
    {
        try {
            if ($this->ids) {
                $delivery->processIds($this->ids);
            } else {
                $delivery->flushDue($this->limit);
            }
        } catch (\Throwable $e) {
            Log::warning('Notification push attempt failed: '.get_class($e).': '.$e->getMessage());
        }
    }
}
