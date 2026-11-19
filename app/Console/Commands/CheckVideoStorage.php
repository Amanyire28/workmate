<?php

namespace App\Console\Commands;

use App\Services\R2Storage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Checks the community video storage (Cloudflare R2) from the server: which
 * keys Laravel can see (never their values) and whether R2 accepts them.
 * Nothing is uploaded or downloaded: it asks R2 about a file that doesn't exist.
 */
class CheckVideoStorage extends Command
{
    protected $signature = 'workmate:video-check';

    protected $description = 'Check that the community video storage (R2) is set up, without showing any keys';

    public function handle(R2Storage $r2): int
    {
        $c = config('videos.r2');
        $rows = [
            ['R2_ACCOUNT_ID', ! empty($c['account_id']) ? 'set' : 'missing'],
            ['R2_ACCESS_KEY_ID', ! empty($c['access_key_id']) ? 'set' : 'MISSING'],
            ['R2_SECRET_ACCESS_KEY', ! empty($c['secret_access_key']) ? 'set' : 'MISSING'],
            ['R2_VIDEO_BUCKET', ! empty($c['bucket']) ? (string) $c['bucket'] : 'MISSING'],
            ['R2_ENDPOINT', ! empty($c['endpoint']) ? (string) parse_url((string) $c['endpoint'], PHP_URL_HOST) : ($c['account_id'] ? 'from R2_ACCOUNT_ID' : 'missing')],
        ];
        $this->table(['Setting', 'Laravel sees'], $rows);

        if (app()->configurationIsCached()) {
            $this->line('Configuration is cached: after changing .env, run php artisan config:cache again.');
        }
        if (! $r2->configured()) {
            $this->error('Video storage is NOT set up: video uploads will be refused. Add the missing keys to .env, then run php artisan config:cache.');

            return 1;
        }

        try {
            $status = Http::timeout(15)->head($r2->presign('HEAD', 'health-check/'.Str::random(24).'.mp4', 120))->status();
        } catch (\Throwable $e) {
            $this->error('R2 could not be reached from this server: '.class_basename($e));

            return 1;
        }

        if ($status === 404) {
            $this->info('R2 accepted the keys and the bucket exists. Video uploads can work (browser uploads also need the bucket\'s CORS rule).');

            return 0;
        }
        $this->error('R2 answered HTTP '.$status.($status === 403 ? ': the keys were refused, or they lack access to this bucket.' : '.'));

        return 1;
    }
}
