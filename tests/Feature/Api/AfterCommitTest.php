<?php

namespace Tests\Feature\Api;

use App\Support\AfterCommit;
use Illuminate\Support\Facades\DB;

/** Work queued with AfterCommit runs once, only when the outermost transaction really commits */
class AfterCommitTest extends ApiTestCase
{
    private $ran = [];

    private function queue(string $name): void
    {
        $this->app->make(AfterCommit::class)->run(function () use ($name) {
            $this->ran[] = $name;
        });
    }

    public function test_it_runs_straight_away_when_no_transaction_is_open()
    {
        $this->queue('now');
        $this->assertSame(['now'], $this->ran);
    }

    public function test_it_waits_for_the_outermost_commit()
    {
        DB::transaction(function () {
            $this->queue('outer');
            DB::transaction(function () {
                $this->queue('inner');
            });
            $this->assertSame([], $this->ran, 'a nested commit is not the real commit');
        });
        $this->assertSame(['outer', 'inner'], $this->ran);
    }

    public function test_an_outer_rollback_drops_everything_queued()
    {
        try {
            DB::transaction(function () {
                $this->queue('outer');
                DB::transaction(function () {
                    $this->queue('inner');
                });
                throw new \RuntimeException('fails after the inner commit');
            });
        } catch (\RuntimeException $e) {
        }
        $this->assertSame([], $this->ran);

        // Nothing is left behind to run at some later commit
        DB::transaction(function () {
        });
        $this->assertSame([], $this->ran);
    }

    public function test_a_nested_rollback_drops_only_what_that_level_queued()
    {
        DB::transaction(function () {
            $this->queue('outer');
            try {
                DB::transaction(function () {
                    $this->queue('inner');
                    throw new \RuntimeException('inner fails');
                });
            } catch (\RuntimeException $e) {
            }
            $this->queue('after');
        });
        $this->assertSame(['outer', 'after'], $this->ran);
    }

    public function test_a_later_sibling_rolling_back_does_not_drop_an_earlier_sibling_that_committed()
    {
        DB::transaction(function () {
            DB::transaction(function () {
                $this->queue('first');
            });
            try {
                DB::transaction(function () {
                    $this->queue('second');
                    throw new \RuntimeException('second fails');
                });
            } catch (\RuntimeException $e) {
            }
        });
        $this->assertSame(['first'], $this->ran);
    }

    public function test_a_failing_callback_does_not_break_the_others_or_the_request()
    {
        DB::transaction(function () {
            $this->app->make(AfterCommit::class)->run(function () {
                throw new \RuntimeException('push service down');
            });
            $this->queue('second');
        });
        $this->assertSame(['second'], $this->ran);
    }
}
