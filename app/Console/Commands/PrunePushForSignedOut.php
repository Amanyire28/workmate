<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes push subscriptions of accounts that are signed in nowhere (no
 * session left), so devices signed out before sign-out started removing
 * subscriptions (or whose session ran out) stop receiving that account's
 * notifications. Sessions last 90 days, so anyone still signed in somewhere
 * keeps theirs; someone who comes back through "remember me" has the
 * subscription saved again when the app opens.
 *
 * Counts only, unless --apply is given. Needs SESSION_DRIVER=database.
 */
class PrunePushForSignedOut extends Command
{
    protected $signature = 'workmate:push-prune-signed-out {--apply : Delete them (otherwise only count)}';

    protected $description = 'Remove push subscriptions of accounts that are signed in nowhere (dry run unless --apply)';

    public function handle(): int
    {
        if (config('session.driver') !== 'database') {
            $this->error('Sessions are not stored in the database (SESSION_DRIVER='.config('session.driver').'), so who is signed in can’t be told. Nothing done.');

            return 1;
        }

        $table = config('session.table', 'sessions');
        $orphaned = PushSubscription::query()->whereNotExists(function ($q) use ($table) {
            $q->select(DB::raw(1))->from($table)->whereColumn($table.'.user_id', 'push_subscriptions.user_id');
        });

        $count = (clone $orphaned)->count();
        $accounts = (clone $orphaned)->distinct()->count('user_id');
        $this->line(sprintf('Subscriptions of accounts signed in nowhere: %d (on %d accounts). Total subscriptions: %d.', $count, $accounts, PushSubscription::count()));

        if (! $this->option('apply')) {
            $this->line('Dry run: nothing deleted. Run again with --apply to remove them.');

            return 0;
        }

        $deleted = 0;
        (clone $orphaned)->select('id')->orderBy('id')->chunkById(500, function ($rows) use (&$deleted) {
            $deleted += PushSubscription::whereIn('id', $rows->pluck('id'))->delete();
        });
        $this->info("Deleted {$deleted} subscriptions.");

        return 0;
    }
}
