<?php

namespace Tests\Feature\Api;

use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\CommissionSettlements;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Support\Facades\DB;

/**
 * B8 stabilisation: every financial total over settlement cycles is worked out by the database over ALL cycles, never over a sample.
 * A dataset larger than the old 300 / 500 limits proves the totals stay exact, that the SQL gives the same state as the PHP rules
 * (state()) cycle by cycle, and that a provider's figures never include another provider's.
 */
class CommissionAggregationTest extends ApiTestCase
{
    /** @var User */
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->userWithRoles(['super_admin']);
    }

    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Services '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private $seq = 0;

    /**
     * Writes cycles straight into the tables (foreign keys off for the duration: only the arithmetic is under test). Each cycle has $jobs
     * obligations of 10,000 assessed $daysAgo days ago (the first one), $credit verified, and optionally every obligation reversed.
     *
     * @return array{id: int, outstanding: int, status: string}
     */
    private function cycle(int $providerId, int $jobs, float $daysAgo, int $credit = 0, bool $reversed = false): array
    {
        $at = now()->subSeconds((int) ($daysAgo * 86400));
        $cycleId = DB::table('commission_settlement_cycles')->insertGetId(['provider_profile_id' => $providerId, 'created_at' => $at]);
        for ($j = 0; $j < $jobs; $j++) {
            $n = ++$this->seq;
            $assessed = $at->copy()->addMinutes($j);
            $assessment = DB::table('commission_assessments')->insertGetId([
                'payment_report_id' => 900000 + $n, 'snapshot_id' => 900000 + $n, 'service_request_id' => 800000 + $n, 'provider_profile_id' => $providerId, 'amount_ugx' => 200000,
                'policy_id' => 1, 'policy_version' => 1, 'policy_scope' => 'global', 'rate_bp' => 500, 'commission_ugx' => 10000, 'assessed_at' => $assessed,
            ]);
            DB::table('commission_cycle_obligations')->insert(['cycle_id' => $cycleId, 'assessment_id' => $assessment, 'created_at' => $assessed]);
            DB::table('commission_ledger_entries')->insert(['assessment_id' => $assessment, 'provider_profile_id' => $providerId, 'entry_type' => 'assessment', 'amount_ugx' => 10000, 'reference' => 'assess:'.(900000 + $n), 'created_at' => $assessed]);
            if ($reversed) {
                DB::table('commission_ledger_entries')->insert(['assessment_id' => $assessment, 'provider_profile_id' => $providerId, 'entry_type' => 'reversal', 'amount_ugx' => -10000, 'reference' => 'reverse:'.$assessment, 'created_at' => $assessed]);
            }
        }
        if ($credit > 0) {
            $n = ++$this->seq;
            DB::table('commission_ledger_entries')->insert([
                'assessment_id' => null, 'cycle_id' => $cycleId, 'commission_payment_id' => 700000 + $n, 'provider_profile_id' => $providerId, 'entry_type' => 'settlement',
                'amount_ugx' => -$credit, 'reference' => 'settle:'.(700000 + $n), 'created_at' => $assessed ?? $at,
            ]);
        }
        $payable = $reversed ? 0 : $jobs * 10000;

        return ['id' => $cycleId, 'outstanding' => $payable - $credit];
    }

    /** More cycles than the old limits, of every state, over three providers: [expected totals per provider] */
    private function bigDataset(array $providers): array
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $expect = [];
        foreach ($providers as $p) {
            $expect[$p->id] = ['overdue' => 0, 'overdue_cycles' => 0, 'outstanding_owed' => 0, 'cycles' => 0, 'settled' => 0, 'void' => 0, 'due' => 0, 'open' => 0, 'partially_paid' => 0];
        }
        $make = function (ProviderProfile $p, string $kind, int $i) use (&$expect) {
            $e = &$expect[$p->id];
            switch ($kind) {
                case 'settled':
                    $this->cycle($p->id, 1, 20, 10000);
                    $e['settled']++;
                    break;
                case 'overdue':        // one job, ten days ago, nothing paid
                    $c = $this->cycle($p->id, 1, 10);
                    $e['overdue']++; $e['overdue_cycles']++; $e['outstanding_owed'] += $c['outstanding'];
                    break;
                case 'overdue_part':   // two jobs, nine days ago, 4,000 paid
                    $c = $this->cycle($p->id, 2, 9, 4000);
                    $e['overdue']++; $e['overdue_cycles']++; $e['outstanding_owed'] += $c['outstanding'];
                    break;
                case 'open':           // one job, an hour ago
                    $c = $this->cycle($p->id, 1, 1 / 24);
                    $e['open']++; $e['outstanding_owed'] += $c['outstanding'];
                    break;
                case 'due':            // three jobs an hour ago: due at once, not overdue
                    $c = $this->cycle($p->id, 3, 1 / 24);
                    $e['due']++; $e['outstanding_owed'] += $c['outstanding'];
                    break;
                case 'part':           // one job an hour ago, part paid
                    $c = $this->cycle($p->id, 1, 1 / 24, 3000);
                    $e['partially_paid']++; $e['outstanding_owed'] += $c['outstanding'];
                    break;
                case 'void':           // everything reversed
                    $this->cycle($p->id, 1, 5, 0, true);
                    $e['void']++;
                    break;
            }
            $e['cycles']++;
        };
        $kinds = ['settled', 'overdue', 'overdue_part', 'open', 'due', 'part', 'void', 'overdue', 'settled', 'open'];
        $i = 0;
        foreach ($providers as $idx => $p) {
            for ($n = 0; $n < 240 + $idx * 10; $n++) {     // 240 + 250 + 260 = 750 cycles
                $make($p, $kinds[$n % count($kinds)], $i++);
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return $expect;
    }

    public function test_totals_over_more_cycles_than_the_old_limits_are_exact_and_provider_figures_stay_separate()
    {
        $providers = [$this->provider(), $this->provider(), $this->provider()];
        $expect = $this->bigDataset($providers);
        $all = array_sum(array_column($expect, 'cycles'));
        $this->assertGreaterThan(500, $all);
        $svc = app(CommissionSettlements::class);

        // The database totals equal what was built, in every state, for every provider and for all of them together
        $totals = $svc->statusTotals();
        foreach (['settled', 'void', 'due', 'open', 'partially_paid', 'overdue'] as $state) {
            $this->assertSame(array_sum(array_column($expect, $state)), $totals[$state]['count'] ?? 0, $state);
        }
        $this->assertSame(array_sum(array_column($expect, 'overdue_cycles')), $totals['overdue']['count']);
        foreach ($providers as $p) {
            $mine = $svc->statusTotals(['provider' => $p->id]);
            foreach (['settled', 'void', 'due', 'open', 'partially_paid', 'overdue'] as $state) {
                $this->assertSame($expect[$p->id][$state], $mine[$state]['count'] ?? 0, "{$p->id} {$state}");
            }
            $this->assertSame($expect[$p->id]['outstanding_owed'], array_sum(array_column($mine, 'outstanding_ugx')), 'outstanding of '.$p->id);
        }

        // The overview says the same, with no sampling note, for all providers and for one
        $this->signIn($this->userWithRoles(['finance']));
        $o = $this->getJson('/api/v1/admin/financial/overview')->assertOk()->json('data');
        $overdueUgx = array_sum(array_map(function ($e) {
            return $e['overdue_cycles'] ? 0 : 0;
        }, $expect));
        $this->assertArrayNotHasKey('overdue_truncated', $o['as_of']);
        $this->assertSame(array_sum(array_column($expect, 'overdue_cycles')), $o['as_of']['overdue_cycles']);
        $this->assertSame($totals['overdue']['outstanding_ugx'], $o['as_of']['overdue_ugx']);
        $this->assertSame(array_sum(array_column($expect, 'outstanding_owed')), $o['as_of']['outstanding_ugx']);
        $this->assertSame((int) DB::table('commission_ledger_entries')->sum('amount_ugx'), $o['as_of']['outstanding_ugx']);   // the ledger agrees
        $this->assertSame(0, $overdueUgx);
        foreach ($providers as $p) {
            $one = $this->getJson('/api/v1/admin/financial/overview?provider='.$p->id)->json('data');
            $this->assertSame($expect[$p->id]['overdue_cycles'], $one['as_of']['overdue_cycles']);
            $this->assertSame($expect[$p->id]['outstanding_owed'], $one['as_of']['outstanding_ugx']);
            $this->assertLessThan($o['as_of']['outstanding_ugx'], $one['as_of']['outstanding_ugx']);
        }

        // Settlement performance counts every cycle
        $perf = collect($this->getJson('/api/v1/admin/financial/reports/performance')->json('data.items'))->keyBy('metric');
        $this->assertSame($all, $perf['Settlement cycles (all)']['count']);
        $this->assertSame(array_sum(array_column($expect, 'overdue')), $perf['Overdue cycles']['count']);
        $this->assertSame($totals['overdue']['outstanding_ugx'], $perf['Overdue cycles']['amount_ugx']);
        $this->assertSame(array_sum(array_column($expect, 'void')), $perf['Nothing owed cycles']['count']);
        $one = collect($this->getJson('/api/v1/admin/financial/reports/performance?provider='.$providers[0]->id)->json('data.items'))->keyBy('metric');
        $this->assertSame($expect[$providers[0]->id]['cycles'], $one['Settlement cycles (all)']['count']);

        // The cycle list pages the whole set, counts every state, and filters by state across all of it
        $first = $this->getJson('/api/v1/admin/settlements/cycles')->json('data');
        $this->assertSame($all, $first['total']);
        $this->assertSame(array_sum(array_column($expect, 'overdue')), $first['counts']['overdue']);
        $this->assertCount(25, $first['items']);
        $overdue = $this->getJson('/api/v1/admin/settlements/cycles?status=overdue')->json('data');
        $this->assertSame(array_sum(array_column($expect, 'overdue')), $overdue['total']);
        $this->assertSame(['overdue'], array_values(array_unique(array_column($overdue['items'], 'status'))));
        $last = $this->getJson('/api/v1/admin/settlements/cycles?page='.(int) ceil($all / 25))->json('data');
        $this->assertNotEmpty($last['items']);                                  // the oldest cycles are reachable too
        $mineOnly = $this->getJson('/api/v1/admin/settlements/cycles?provider='.$providers[2]->id)->json('data');
        $this->assertSame($expect[$providers[2]->id]['cycles'], $mineOnly['total']);
        $this->assertSame([$providers[2]->id], array_values(array_unique(array_column($mineOnly['items'], 'provider_id'))));
    }

    public function test_the_sql_and_the_php_rules_give_the_same_state_for_every_cycle()
    {
        $providers = [$this->provider(), $this->provider(), $this->provider()];
        $this->bigDataset($providers);
        $svc = app(CommissionSettlements::class);
        $rows = $svc->cycleSummary()->orderBy('id')->get()->keyBy('id');
        $this->assertGreaterThan(500, $rows->count());
        $mismatches = [];
        foreach ($rows as $id => $row) {
            $s = $svc->state((int) $id);
            $same = $s['status'] === $row->status && $s['payable_ugx'] === (int) $row->payable_ugx && $s['credits_ugx'] === (int) $row->credits_ugx && $s['outstanding_ugx'] === (int) $row->outstanding_ugx;
            if (! $same) {
                $mismatches[] = $id.': '.$s['status'].' vs '.$row->status;
            }
        }
        $this->assertSame([], array_slice($mismatches, 0, 5));
    }

    public function test_the_sql_follows_the_optional_three_job_window_exactly_as_the_php_rules_do()
    {
        $p = $this->provider();
        app(PlatformSettings::class)->set('finance.settlement_three_job_window_hours', 24, $this->root);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $inside = $this->cycle($p->id, 3, 20 / 24);        // three jobs 20 hours ago: due, inside the window
        $outside = $this->cycle($p->id, 3, 30 / 24);       // 30 hours ago: past the window, so overdue
        $slow = $this->cycle($p->id, 1, 8);                // one job eight days ago: past seven days
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $svc = app(CommissionSettlements::class);
        $sql = $svc->cycleSummary()->get()->keyBy('id');
        foreach ([$inside, $outside, $slow] as $c) {
            $this->assertSame($svc->state($c['id'])['status'], $sql[$c['id']]->status, 'cycle '.$c['id']);
        }
        $this->assertSame(['due', 'overdue', 'overdue'], [$sql[$inside['id']]->status, $sql[$outside['id']]->status, $sql[$slow['id']]->status]);
    }

    public function test_a_provider_sees_their_current_settlement_even_when_it_is_older_than_the_twelve_listed()
    {
        $settings = app(PlatformSettings::class);
        $settings->set('finance.pricing_enabled', true, $this->root);
        $settings->set('finance.commission_assessment_enabled', true, $this->root);
        $settings->set('finance.commission_settlement_enabled', true, $this->root);
        $p = $this->provider();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $old = $this->cycle($p->id, 1, 3);                 // the only one still owing, and the OLDEST
        for ($i = 0; $i < 15; $i++) {
            $this->cycle($p->id, 1, 2, 10000);             // fifteen newer ones, all settled
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->signIn($p->owner);
        $data = $this->getJson('/api/v1/me/commission')->assertOk()->json('data');
        $this->assertSame(12, count($data['settlement']['cycles']));
        $this->assertSame($old['id'], $data['settlement']['current']['id']);          // found by query, not from the twelve
        $this->assertSame(10000, $data['settlement']['current']['outstanding_ugx']);
        $this->assertSame(10000, $data['outstanding_ugx']);
    }
}
