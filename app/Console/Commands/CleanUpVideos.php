<?php

namespace App\Console\Commands;

use App\Services\CommunityVideos;
use Illuminate\Console\Command;

/**
 * Hourly (app/Console/Kernel.php): unfinished video uploads expire and their
 * files are removed from R2, and so are the videos of posts deleted more
 * than videos.delete_after_days ago. Nothing is downloaded; whatever fails is
 * tried again next time.
 */
class CleanUpVideos extends Command
{
    protected $signature = 'workmate:video-cleanup {--limit=200 : Most items of each kind handled in one run}';

    protected $description = 'Expire abandoned video uploads and remove unused video files from R2';

    public function handle(CommunityVideos $videos): int
    {
        $done = $videos->cleanup(max(1, (int) $this->option('limit')));
        $this->line(sprintf(
            'Expired uploads: %d. Upload files removed: %d. Deleted posts\' videos removed: %d. Failures (retried next run): %d.',
            $done['expired'], $done['uploads_deleted'], $done['videos_deleted'], $done['failures']
        ));

        return 0;
    }
}
