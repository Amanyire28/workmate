<?php

namespace App\Console\Commands;

use App\Models\CommunityPostMedia;
use App\Models\ProviderWorkPhoto;
use App\Services\PhotoVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Catches up public photos added before the watermark (community posts and
 * providers' work). Safe to stop and run again: photos already done are
 * skipped, and problems are recorded on the photo so they can be retried.
 * The existing files are kept as the clean copies; nothing is deleted.
 */
class WatermarkPhotos extends Command
{
    protected $signature = 'workmate:watermark-photos
        {--dry-run : Only count what would be done}
        {--type=all : community, work or all}
        {--limit=0 : Stop after this many photos (0 = no limit)}
        {--retry-failed : Also try photos that failed before}
        {--redo : Redo photos already watermarked, from their clean copy (after changing the stamp)}
        {--pause=100 : Milliseconds to rest after every 20 photos, so the site stays quick}';

    protected $description = 'Watermark public photos added before the watermark (resumable; --dry-run first)';

    private const TYPES = [
        'community' => CommunityPostMedia::class,
        'work' => ProviderWorkPhoto::class,
    ];

    public function handle(PhotoVariants $photos): int
    {
        $type = (string) $this->option('type');
        $types = $type === 'all' ? self::TYPES : array_intersect_key(self::TYPES, [$type => true]);
        if (! $types) {
            $this->error('--type must be community, work or all.');

            return 1;
        }
        if (! config('watermark.enabled') && ! $this->option('dry-run')) {
            $this->error('The watermark is switched off (WATERMARK_ENABLED=false).');

            return 1;
        }

        $limit = max(0, (int) $this->option('limit'));
        $done = 0;
        foreach ($types as $name => $model) {
            if (! config('watermark.categories.'.$name)) {
                $this->line("{$name}: not watermarked (config/watermark.php)");
                continue;
            }
            $query = $this->query($model);

            if ($this->option('dry-run')) {
                $this->report($name, $model, (clone $query)->count());
                continue;
            }

            $counts = ['done' => 0, 'failed' => 0];
            $query->orderBy('id')->chunkById(100, function ($rows) use ($photos, &$counts, &$done, $limit, $name) {
                foreach ($rows as $media) {
                    if ($limit && $done >= $limit) {
                        return false;
                    }
                    try {
                        $photos->watermarkStored($media, (bool) $this->option('redo'));
                        $counts['done']++;
                    } catch (\Throwable $e) {
                        $media->forceFill(['watermark_error' => Str::limit($e->getMessage(), 250, '')])->save();
                        $counts['failed']++;
                        $this->warn("{$name} #{$media->id}: ".$e->getMessage());
                    }
                    $done++;
                    if ($done % 20 === 0) {
                        usleep(max(0, (int) $this->option('pause')) * 1000);
                    }
                }
            });
            $this->info("{$name}: {$counts['done']} watermarked, {$counts['failed']} failed");
        }

        return 0;
    }

    private function query(string $model)
    {
        $query = $model::query();
        if ($this->option('redo')) {
            return $query->whereNotNull('watermarked_at');
        }
        $query->whereNull('watermarked_at');
        if (! $this->option('retry-failed')) {
            $query->whereNull('watermark_error');
        }

        return $query;
    }

    private function report(string $name, string $model, int $todo): void
    {
        $this->line(sprintf(
            '%s: %d to %s, %d already watermarked, %d failed before (use --retry-failed)',
            $name,
            $todo,
            $this->option('redo') ? 'redo' : 'watermark',
            $model::whereNotNull('watermarked_at')->count(),
            $model::whereNull('watermarked_at')->whereNotNull('watermark_error')->count()
        ));
    }
}
