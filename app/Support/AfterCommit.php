<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Runs something once the database work around it is really saved: straight
 * away when no transaction is open, otherwise when the outermost transaction
 * commits. If the transaction it was queued in is rolled back (even a nested
 * one), it never runs.
 *
 * Laravel 8's DB::afterCommit() does the first part, but never runs inside
 * the test suite's own wrapping transaction, so tests couldn't see what it
 * does. This listens to the connection's commit and rollback events instead
 * (AppServiceProvider), and tests mark their wrapping transaction as the
 * outermost level (treatLevelAsOutermost), so the same code runs everywhere.
 */
class AfterCommit
{
    /** @var int the transaction level that counts as "nothing open" (0, or 1 inside a test) */
    private $outermost = 0;

    /** @var array<int, array{0: int, 1: callable}> level it was queued at, and what to run */
    private $pending = [];

    public function run(callable $callback): void
    {
        $level = DB::transactionLevel();
        if ($level <= $this->outermost) {
            $callback();

            return;
        }
        $this->pending[] = [$level, $callback];
    }

    /** A transaction committed: when nothing is open any more, run what was waiting */
    public function committed(): void
    {
        $level = DB::transactionLevel();
        if ($level > $this->outermost) {
            // A nested transaction committed: what it queued now belongs to the level
            // around it, so a sibling savepoint rolling back later can't drop it
            foreach ($this->pending as &$item) {
                if ($item[0] > $level) {
                    $item[0] = $level;
                }
            }
            unset($item);

            return;
        }
        if (! $this->pending) {
            return;
        }
        $ready = $this->pending;
        $this->pending = [];
        foreach ($ready as [, $callback]) {
            // The data is already saved: a failing notice must not turn the request into an error
            try {
                $callback();
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** A transaction rolled back: what was queued inside it is dropped */
    public function rolledBack(): void
    {
        $level = DB::transactionLevel();
        $this->pending = array_values(array_filter($this->pending, function ($item) use ($level) {
            return $item[0] <= $level;
        }));
    }

    /** For tests: the test's own wrapping transaction counts as nothing being open */
    public function treatLevelAsOutermost(int $level): void
    {
        $this->outermost = $level;
        $this->pending = [];
    }
}
