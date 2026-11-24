<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CommissionAssessment;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionSettlementCycle;
use App\Models\JobPaymentReport;
use App\Models\PaymentDispute;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Support\Facades\DB;

/**
 * Phase B9: My Finances. A provider business reads its own money (customer payments and WorkMate commission), pages through its history and
 * downloads statements, with access for the owner and for team members the owner allowed, never for anyone else, and never depending on a
 * financial switch.
 */
class ProviderFinanceTest extends ApiTestCase
{
    /** @var User */
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->userWithRoles(['super_admin']);
    }

    private function setting(string $key, $value): void
    {
        app(PlatformSettings::class)->set($key, $value, $this->root);
    }

    private function activate(bool $on = true): void
    {
        if (! $on) {
            // Switching off: what depends on a switch goes first
            foreach (['finance.commission_settlement_enabled', 'finance.commission_assessment_enabled', 'finance.commission_shadow_enabled', 'finance.pricing_enabled'] as $key) {
                $this->setting($key, false);
            }
            $this->setting('finance.commission_assessment_from', now()->subHour()->toIso8601String());

            return;
        }
        $this->setting('finance.pricing_enabled', true);
        $this->setting('finance.commission_shadow_enabled', true);
        $this->setting('finance.commission_assessment_from', now()->subHour()->toIso8601String());
        $this->setting('finance.commission_assessment_enabled', true);
        $this->setting('finance.commission_settlement_enabled', true);
    }

    private function provider(string $name = null): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => $name ?: 'Okello Services '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function rule(int $bp = 500): void
    {
        $body = ['scope_type' => 'global', 'rate_bp' => $bp, 'effective_from' => now()->subSeconds(30)->toIso8601String()];
        $this->signIn($this->root);
        $preview = $this->postJson('/api/v1/admin/commission/policies/simulate', $body)->assertOk()->json('data');
        $this->postJson('/api/v1/admin/commission/policies', $body + ['token' => $preview['token'], 'confirm_rate_bp' => $bp, 'reason' => 'Testing the rate'])->assertCreated();
    }

    /** A job with an agreed price; returns [request id, customer] */
    private function agreed(ProviderProfile $provider, int $price = 5000000): array
    {
        $customer = User::factory()->create(['name' => 'Aisha Namuli']);
        $this->signIn($customer);
        $id = $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow morning',
        ])->assertCreated()->json('data.id');
        ServiceRequest::whereKey($id)->update(['status' => 'in_progress']);
        $this->signIn($provider->owner);
        $version = $this->postJson("/api/v1/me/received-requests/{$id}/agreements", ['amount' => $price, 'scope' => 'Replace the kitchen sink pipes and fit a new tap.'])->assertCreated()->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$version}/accept", ['amount' => $price])->assertOk();

        return [$id, $customer];
    }

    private function report(ProviderProfile $provider, int $id, int $amount): int
    {
        $this->signIn($provider->owner);
        $reports = $this->postJson("/api/v1/me/received-requests/{$id}/payments", ['amount' => $amount, 'method' => 'cash'])->assertCreated()->json('data.payments.reports');

        return (int) end($reports)['id'];
    }

    /** A job whose customer confirmed a payment of $amount: [request id, payment id] */
    private function job(ProviderProfile $provider, int $amount = 200000): array
    {
        [$id, $customer] = $this->agreed($provider);
        $payment = $this->report($provider, $id, $amount);
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => $amount])->assertOk();

        return [$id, $payment];
    }

    private function fin(User $user, string $path)
    {
        $this->signIn($user);

        return $this->getJson('/api/v1/me/finance/'.$path);
    }

    private function cycleOf(ProviderProfile $provider, int $nth = 0): int
    {
        return (int) CommissionSettlementCycle::where('provider_profile_id', $provider->id)->orderBy('id')->skip($nth)->value('id');
    }

    private function team(ProviderProfile $provider): array
    {
        $manager = User::factory()->create(['name' => 'Mona Manager']);
        $member = User::factory()->create(['name' => 'Mike Member']);
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        $provider->members()->create(['user_id' => $member->id, 'role' => ProviderMember::ROLE_MEMBER]);

        return [$manager, $member];
    }

    /* ---------------- Who may see it ---------------- */

    public function test_the_owner_always_sees_it_and_nobody_else_does_unless_the_owner_allows_it()
    {
        $provider = $this->provider();
        [$manager, $member] = $this->team($provider);
        $other = $this->provider();
        $customer = User::factory()->create();

        $this->fin($provider->owner, 'summary')->assertOk();
        foreach ([$manager, $member, $customer, $other->owner] as $nobody) {
            foreach (['summary', 'payments', 'statement', 'cycles', 'commission-payments', 'disputes'] as $path) {
                $res = $this->fin($nobody, $path);
                if ($nobody->id === $other->owner->id) {
                    $res->assertOk();   // their own business, not this one
                } else {
                    $res->assertStatus(403)->assertJsonPath('code', 'finance_access_denied');
                }
            }
            foreach (['commission', 'payments'] as $kind) {
                $this->signIn($nobody);
                $status = $this->fin($nobody, "statements/{$kind}.csv")->getStatusCode();
                $this->assertSame($nobody->id === $other->owner->id ? 200 : 403, $status);
            }
        }
        // The access screen tells each person where they stand
        $this->assertFalse($this->fin($manager, 'access')->assertOk()->json('data.allowed'));
        $this->assertSame(['owner', true], [$this->fin($provider->owner, 'access')->json('data.role'), $this->fin($provider->owner, 'access')->json('data.can_manage_access')]);

        // The owner allows the manager; the ordinary member stays out; the owner can take it back
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true])->assertOk();
        $this->fin($manager, 'summary')->assertOk();
        $this->assertSame('manager', $this->fin($manager, 'access')->json('data.role'));
        $this->assertFalse($this->fin($manager, 'access')->json('data.can_manage_access'));
        $this->fin($member, 'summary')->assertStatus(403);
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $member->id, 'allowed' => true])->assertOk();
        $this->fin($member, 'summary')->assertOk();
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => false])->assertOk();
        $this->fin($manager, 'summary')->assertStatus(403);
        $audit = AuditLog::where('action', 'finance.provider_access_changed')->orderBy('id')->get();
        $this->assertSame([true, true, false], $audit->map(function ($a) {
            return $a->after['can_view_finances'];
        })->all());
        $this->assertSame($provider->owner_id, (int) $audit[0]->actor_id);
    }

    public function test_taking_access_away_stops_every_screen_and_download_on_the_very_next_request()
    {
        $provider = $this->provider();
        [$manager] = $this->team($provider);
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true])->assertOk();

        $paths = ['summary', 'payments', 'statement', 'cycles', 'commission-payments', 'disputes',
            'statements/commission.csv', 'statements/commission.pdf', 'statements/payments.csv', 'statements/payments.pdf'];
        foreach ($paths as $path) {
            $this->signIn($manager);
            $this->assertSame(200, $this->fin($manager, $path)->getStatusCode(), $path);
        }

        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => false])->assertOk();

        foreach ($paths as $path) {
            $this->signIn($manager);
            $this->assertSame(403, $this->fin($manager, $path)->getStatusCode(), $path);
        }
    }

    public function test_only_the_owner_can_change_access_and_only_for_their_own_team()
    {
        $provider = $this->provider();
        [$manager, $member] = $this->team($provider);
        $other = $this->provider();
        [$otherManager] = $this->team($other);
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true])->assertOk();

        // A manager who has access still cannot hand it on
        $this->signIn($manager);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $member->id, 'allowed' => true])->assertStatus(403);
        $this->assertFalse((bool) ProviderMember::where('user_id', $member->id)->value('can_view_finances'));
        // Not for another business's people, not for the owner, not for a stranger, not with bad input
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $otherManager->id, 'allowed' => true])->assertStatus(404);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $provider->owner_id, 'allowed' => false])->assertStatus(404);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => User::factory()->create()->id, 'allowed' => true])->assertStatus(404);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $member->id])->assertStatus(422);
        $this->assertFalse((bool) ProviderMember::where('user_id', $otherManager->id)->value('can_view_finances'));
        // The owner sees the team and who has access
        $team = $this->fin($provider->owner, 'access')->json('data.members');
        $this->assertSame([[$manager->id, true], [$member->id, false]], array_map(function ($m) {
            return [$m['user_id'], $m['can_view_finances']];
        }, $team));
        // A repeat changes and records nothing more
        $before = AuditLog::where('action', 'finance.provider_access_changed')->count();
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true])->assertOk();
        $this->assertSame($before, AuditLog::where('action', 'finance.provider_access_changed')->count());
    }

    /* ---------------- Customer payments ---------------- */

    public function test_customer_payment_totals_count_every_confirmed_payment_and_never_unconfirmed_money()
    {
        $this->setting('finance.pricing_enabled', true);   // payments can be reported; commission is NOT switched on
        $provider = $this->provider();
        [$a] = $this->job($provider, 150000);            // confirmed: never produced any commission (obligations are off)
        [$b] = $this->job($provider, 60000);
        [$c, $customerC] = $this->agreed($provider);
        $waiting = $this->report($provider, $c, 40000);   // waiting for the customer
        [$d, $customerD] = $this->agreed($provider);
        $questioned = $this->report($provider, $d, 30000);
        $this->signIn($customerD);
        $this->postJson("/api/v1/me/requests/{$d}/payments/{$questioned}/dispute", ['note' => 'Not right'])->assertOk();

        $s = $this->fin($provider->owner, 'summary?period=all')->assertOk()->json('data');
        $cp = $s['customer_payments'];
        $this->assertSame([210000, 2], [$cp['confirmed_ugx'], $cp['confirmed_count']]);             // 150,000 + 60,000, with no commission involved
        $this->assertSame(['count' => 1, 'amount_ugx' => 40000], $cp['awaiting']);
        $this->assertSame(['count' => 1, 'amount_ugx' => 30000], $cp['questioned']);
        $this->assertSame(280000, $cp['reported_ugx']);                                             // reported, of which only 210,000 is confirmed
        // 4 agreed jobs of UGX 5,000,000 each, 210,000 confirmed in all
        $this->assertSame([4, 4 * 5000000 - 210000], [$cp['remaining_jobs'], $cp['remaining_ugx']]);
        $this->assertSame(0, $s['commission']['as_of']['outstanding_ugx']);                         // and no invented debt
        $this->assertSame([0, 0, 0], [$s['commission']['period']['assessed_ugx'], $s['commission']['as_of']['overdue_ugx'], CommissionAssessment::count()]);
        $this->assertTrue($s['has_history']);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotNull($waiting);
        // A cancelled job's agreed price is not "still to be paid"
        ServiceRequest::whereKey($c)->update(['status' => 'cancelled']);
        $this->assertSame(3, $this->fin($provider->owner, 'summary?period=all')->json('data.customer_payments.remaining_jobs'));
    }

    public function test_periods_use_kampala_dates_a_week_that_starts_on_monday_and_exact_boundaries()
    {
        $this->setting('finance.pricing_enabled', true);
        $this->travelTo(\Carbon\Carbon::parse('2026-12-15 07:00:00', 'UTC'));   // Tuesday 15 December, 10:00 in Kampala
        $provider = $this->provider();
        $confirm = function (string $utc, int $amount) use ($provider) {
            [$id, $payment] = $this->job($provider, $amount);
            DB::table('job_payment_reports')->where('id', $payment)->update(['responded_at' => $utc]);
        };
        $confirm('2026-11-30 20:59:59', 1000);     // 23:59:59 on 30 Nov in Kampala: last month
        $confirm('2026-11-30 21:00:00', 2000);     // 00:00:00 on 1 Dec in Kampala: this month
        $confirm('2026-12-13 20:59:59', 4000);     // Sunday 23:59:59 in Kampala: last week, this month
        $confirm('2026-12-13 21:00:00', 8000);     // Monday 00:00:00: this week
        $confirm('2026-12-15 06:00:00', 16000);    // today

        $sum = function (string $q) use ($provider) {
            return $this->fin($provider->owner, 'summary?'.$q)->assertOk()->json('data.customer_payments.confirmed_ugx');
        };
        $this->assertSame(1000 + 2000 + 4000 + 8000 + 16000, $sum('period=all'));
        $this->assertSame(2000 + 4000 + 8000 + 16000, $sum('period=this_month'));
        $this->assertSame(1000, $sum('period=last_month'));
        $this->assertSame(8000 + 16000, $sum('period=this_week'));
        $this->assertSame(4000 + 8000, $sum('period=custom&from=2026-12-07&to=2026-12-14'));      // 7th to the 14th, Kampala dates
        $this->assertSame(2000, $sum('period=custom&from=2026-12-01&to=2026-12-01'));           // one day
        $label = $this->fin($provider->owner, 'summary?period=this_week')->json('data.period');
        $this->assertSame(['this_week', '2026-12-14', '2026-12-20'], [$label['key'], $label['from'], $label['to']]);

        $this->fin($provider->owner, 'summary?period=custom&from=2026-12-10&to=2026-12-01')->assertStatus(422)->assertJsonPath('code', 'period_invalid');
        $this->fin($provider->owner, 'summary?period=custom&from=2026-12-01')->assertStatus(422);
        $this->fin($provider->owner, 'summary?period=custom&from=2019-01-01&to=2026-12-01')->assertStatus(422)->assertJsonPath('code', 'period_too_long');
        $this->fin($provider->owner, 'summary?period=forever')->assertStatus(422);
        $this->fin($provider->owner, 'summary?period=custom&from=garbage&to=2026-12-01')->assertStatus(422);
    }

    public function test_the_payments_list_is_paged_filtered_and_only_this_business()
    {
        $this->setting('finance.pricing_enabled', true);
        $provider = $this->provider();
        $other = $this->provider();
        [$id, $payment] = $this->job($provider, 1000);
        $request = ServiceRequest::find($id);
        // A long history on that job (21 more confirmed payments), written the way the app writes them
        for ($i = 1; $i <= 21; $i++) {
            $r = new JobPaymentReport();
            $r->forceFill([
                'service_request_id' => $id, 'provider_profile_id' => $provider->id, 'customer_id' => $request->customer_id, 'reported_by_user_id' => $provider->owner_id,
                'amount_ugx' => 1000 + $i, 'method' => 'cash', 'status' => 'confirmed', 'responded_by_user_id' => $request->customer_id, 'responded_at' => now(),
            ])->save();
        }
        $this->job($other, 999999);
        [$w] = $this->agreed($provider);
        $this->report($provider, $w, 5000);

        $p1 = $this->fin($provider->owner, 'payments?period=all')->assertOk()->json('data');
        $this->assertSame([23, 20, 1], [$p1['total'], count($p1['items']), $p1['page']]);
        $p2 = $this->fin($provider->owner, 'payments?period=all&page=2')->json('data');
        $this->assertSame(3, count($p2['items']));
        $this->assertSame(23, count(array_unique(array_merge(array_column($p1['items'], 'id'), array_column($p2['items'], 'id')))));
        $this->assertNotContains(999999, array_merge(array_column($p1['items'], 'amount_ugx'), array_column($p2['items'], 'amount_ugx')));   // never another business's
        $this->assertSame(1, $this->fin($provider->owner, 'payments?period=all&status=pending_confirmation')->json('data.total'));
        $this->assertSame(22, $this->fin($provider->owner, 'payments?period=all&status=confirmed')->json('data.total'));
        $this->assertSame(5000, $p1['items'][0]['amount_ugx']);                     // newest first
        $this->assertSame(['Aisha', 'pending_confirmation'], [$p1['items'][0]['customer'], $p1['items'][0]['status']]);   // a customer's first name only
        $this->assertArrayNotHasKey('email', $p1['items'][0]);
        // A filter naming another business changes nothing: there is no such filter
        $this->assertSame(23, $this->fin($provider->owner, 'payments?period=all&provider='.$other->id.'&provider_id='.$other->id)->json('data.total'));
        $this->fin($provider->owner, 'payments?period=all&page=0')->assertStatus(422);
        $this->assertNotNull($payment);
    }

    /* ---------------- Commission ---------------- */

    public function test_commission_figures_follow_the_ledger_reversals_settlements_overdue_and_the_current_settlement()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        [$first, $firstPayment] = $this->job($provider);
        $this->job($provider);
        [$third] = $this->job($provider);                       // three jobs: due at once
        $cycle = $this->cycleOf($provider);
        // Pay 10,000 (verified by finance)
        $this->signIn($provider->owner);
        $paid = $this->postJson('/api/v1/me/commission/payments', ['cycle_id' => $cycle, 'amount' => 10000, 'channel' => 'mtn_momo', 'reference' => 'MP100001'])->assertCreated()->json('data.id');
        $this->signIn($this->userWithRoles(['finance']));
        $this->postJson("/api/v1/admin/settlements/payments/{$paid}/verify", ['amount_ugx' => 10000, 'receipt_confirmed' => true])->assertOk();
        // One job is cancelled afterwards: its commission is taken off
        ServiceRequest::whereKey($third)->update(['status' => 'cancelled']);
        $this->artisan('workmate:commission-assess')->assertExitCode(0);

        $s = $this->fin($provider->owner, 'summary?period=all')->assertOk()->json('data');
        $c = $s['commission'];
        $this->assertSame([30000, 10000, 20000, 10000], [$c['period']['assessed_ugx'], $c['period']['reversed_ugx'], $c['period']['net_ugx'], $c['period']['settled_ugx']]);
        $this->assertSame(10000, $c['as_of']['outstanding_ugx']);                      // 30,000 - 10,000 taken off - 10,000 paid
        $this->assertSame(0, $c['as_of']['overdue_ugx']);
        $this->assertSame([2, 'partially_paid', 10000], [$c['as_of']['current_cycle']['jobs'], $c['as_of']['current_cycle']['status'], $c['as_of']['current_cycle']['outstanding_ugx']]);
        $this->assertTrue($c['can_report_payment']);
        // It agrees with the ledger, with the older commission view and with what finance sees for this business
        $this->assertSame((int) CommissionLedgerEntry::where('provider_profile_id', $provider->id)->sum('amount_ugx'), $c['as_of']['outstanding_ugx']);
        $this->assertSame($c['as_of']['outstanding_ugx'], $this->getJson('/api/v1/me/commission')->json('data.outstanding_ugx'));
        $this->signIn($this->userWithRoles(['finance']));
        $this->assertSame($c['as_of']['outstanding_ugx'], $this->getJson('/api/v1/admin/financial/overview?provider='.$provider->id)->json('data.as_of.outstanding_ugx'));

        // Period figures and the position stay apart: a period with nothing in it still shows what is owed today
        $this->travel(40)->days();
        $later = $this->fin($provider->owner, 'summary?period=this_week')->json('data');
        $this->assertSame([0, 0, 0], [$later['commission']['period']['assessed_ugx'], $later['commission']['period']['reversed_ugx'], $later['commission']['period']['settled_ugx']]);
        $this->assertSame([10000, 10000], [$later['commission']['as_of']['outstanding_ugx'], $later['commission']['as_of']['overdue_ugx']]);   // past its seven days: overdue
        $this->assertSame(1, $later['commission']['as_of']['overdue_cycles']);
        $this->assertSame('overdue', $later['commission']['as_of']['current_cycle']['status']);
        $this->assertNotNull($first);
        $this->assertNotNull($firstPayment);
    }

    public function test_the_statement_reconciles_its_opening_lines_and_closing_balance_with_the_ledger_and_pages_through_a_long_history()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        [$id, $payment] = $this->job($provider);
        $assessmentId = CommissionAssessment::value('id');
        $base = now()->subDays(10);
        DB::table('commission_ledger_entries')->where('assessment_id', $assessmentId)->update(['created_at' => $base]);
        // A long history before and inside the period (foreign keys are valid: the same assessment)
        for ($i = 0; $i < 44; $i++) {
            DB::table('commission_ledger_entries')->insert([
                'assessment_id' => $assessmentId, 'provider_profile_id' => $provider->id, 'entry_type' => $i % 4 === 3 ? 'reversal' : 'assessment', 'amount_ugx' => $i % 4 === 3 ? -100 : 250,
                'reference' => 'bulk:'.$i, 'created_at' => $base->copy()->addHours($i + 1),
            ]);
        }
        $all = $this->fin($provider->owner, 'statement?period=all')->assertOk()->json('data');
        $this->assertSame([45, 20, 1], [$all['total'], count($all['items']), $all['page']]);
        $this->assertSame(0, $all['totals']['opening_ugx']);
        $ledger = (int) CommissionLedgerEntry::where('provider_profile_id', $provider->id)->sum('amount_ugx');
        $this->assertSame([$ledger, true], [$all['totals']['closing_ugx'], $all['totals']['matches_ledger']]);
        $this->assertSame($all['totals']['opening_ugx'] + $all['totals']['assessed_ugx'] - $all['totals']['reversed_ugx'] - $all['totals']['settled_ugx'], $all['totals']['closing_ugx']);
        // Lines add up: page after page, the running balance is the sum so far, and the last line is the closing balance
        $lines = [];
        foreach ([1, 2, 3] as $page) {
            $lines = array_merge($lines, $this->fin($provider->owner, 'statement?period=all&page='.$page)->json('data.items'));
        }
        $this->assertCount(45, $lines);
        $running = 0;
        foreach ($lines as $line) {
            $running += $line['amount_ugx'];
            $this->assertSame($running, $line['balance_ugx']);
        }
        $this->assertSame($ledger, end($lines)['balance_ugx']);
        $this->assertSame('assessment', $lines[0]['type']);
        $this->assertSame([$id, 200000, '5%'], [$lines[0]['job'], $lines[0]['payment_ugx'], $lines[0]['rate']]);

        // A period in the middle: its opening balance is everything before it
        $from = $base->copy()->addHours(10)->setTimezone('Africa/Kampala')->toDateString();
        $mid = $this->fin($provider->owner, 'statement?period=custom&from='.$from.'&to='.$from)->json('data');
        $before = (int) DB::table('commission_ledger_entries')->where('provider_profile_id', $provider->id)->where('created_at', '<', \Carbon\Carbon::parse($from, 'Africa/Kampala')->startOfDay()->utc()->toDateTimeString())->sum('amount_ugx');
        $this->assertSame($before, $mid['totals']['opening_ugx']);
        // A day can hold more than one page of lines (it depends on the hour the test runs), so read every page
        $items = $mid['items'];
        for ($page = 2; $page <= 3; $page++) {
            $items = array_merge($items, $this->fin($provider->owner, 'statement?period=custom&from='.$from.'&to='.$from.'&page='.$page)->json('data.items'));
        }
        $inside = array_sum(array_column($items, 'amount_ugx'));
        $this->assertSame($mid['totals']['opening_ugx'] + $inside, $mid['totals']['closing_ugx']);
        $this->assertNull($mid['totals']['matches_ledger']);                       // it does not end today, so no claim is made about today's balance
        $this->assertNotSame([], $mid['items']);
    }

    /* ---------------- Settlements, payments to WorkMate, disputes ---------------- */

    public function test_settlements_and_their_detail_belong_to_one_business_and_a_guessed_id_is_not_found()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $other = $this->provider();
        $this->job($provider);
        $this->job($other);
        $mine = $this->cycleOf($provider);
        $theirs = $this->cycleOf($other);

        $list = $this->fin($provider->owner, 'cycles')->assertOk()->json('data');
        $this->assertSame([$mine], array_column($list['items'], 'id'));
        $this->assertSame(1, $list['total']);
        $detail = $this->fin($provider->owner, 'cycles/'.$mine)->assertOk()->json('data');
        $this->assertSame([1, 10000, 'recorded'], [count($detail['obligations']), $detail['obligations'][0]['commission_ugx'], $detail['obligations'][0]['status']]);
        $this->assertSame(['assessment'], array_column($detail['lines'], 'type'));
        $this->fin($provider->owner, 'cycles/'.$theirs)->assertStatus(404);
        $this->fin($provider->owner, 'cycles/999999')->assertStatus(404);
        $this->fin($other->owner, 'cycles/'.$mine)->assertStatus(404);
        $this->assertSame(1, $this->fin($provider->owner, 'cycles?status=open')->json('data.total'));
        $this->assertSame(0, $this->fin($provider->owner, 'cycles?status=overdue')->json('data.total'));

        // Payments to WorkMate, paged, with what WorkMate decided
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/commission/payments', ['cycle_id' => $mine, 'amount' => 10000, 'channel' => 'airtel_money', 'reference' => 'AM777'])->assertCreated();
        $toWm = $this->fin($provider->owner, 'commission-payments')->json('data');
        $this->assertSame([1, 'pending', 'airtel_money'], [$toWm['total'], $toWm['items'][0]['status'], $toWm['items'][0]['channel']]);
        $this->assertSame(0, $this->fin($other->owner, 'commission-payments')->json('data.total'));
    }

    public function test_disputes_are_listed_for_this_business_only()
    {
        $this->setting('finance.pricing_enabled', true);
        $provider = $this->provider();
        $other = $this->provider();
        foreach ([$provider, $other] as $p) {
            [$id, $customer] = $this->agreed($p);
            $payment = $this->report($p, $id, 10000);
            $this->signIn($customer);
            $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/dispute", ['note' => 'Not right'])->assertOk();
        }
        $list = $this->fin($provider->owner, 'disputes')->assertOk()->json('data');
        $this->assertSame([1, 'open', 10000], [$list['total'], $list['items'][0]['status'], $list['items'][0]['amount_ugx']]);
        $this->assertSame(PaymentDispute::where('provider_profile_id', $provider->id)->value('id'), $list['items'][0]['id']);
        $this->assertSame(1, $this->fin($provider->owner, 'disputes?status=open')->json('data.total'));
        $this->assertSame(0, $this->fin($provider->owner, 'disputes?status=resolved')->json('data.total'));
    }

    /* ---------------- Statements ---------------- */

    public function test_statements_download_as_csv_and_pdf_reconcile_with_the_screen_and_are_protected()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider('=HYPERLINK("http://evil.test","x")');
        [$manager] = $this->team($provider);
        $this->job($provider);
        $this->job($provider, 100000);
        $summary = $this->fin($provider->owner, 'summary?period=all')->json('data');
        $statement = $this->fin($provider->owner, 'statement?period=all')->json('data');

        // CSV: commission statement
        $csv = $this->fin($provider->owner, 'statements/commission.csv?period=all');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $csv->headers->get('Cache-Control'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('"# WorkMate commission statement"', $body);
        $this->assertStringContainsString('# Business: =HYPERLINK(', $body);                                     // inside a cell that starts with "#": text, not a formula
        $this->assertStringNotContainsString("\n=HYPERLINK", $body);
        $this->assertStringContainsString('"# Period: All time"', $body);
        $this->assertStringContainsString('Currency: UGX', $body);
        $this->assertStringContainsString('"# Opening balance (UGX)",0', $body);
        $this->assertStringContainsString('"# Closing balance (UGX)",'.$statement['totals']['closing_ugx'], $body);
        $this->assertSame($summary['commission']['as_of']['outstanding_ugx'], $statement['totals']['closing_ugx']);   // statement = screen = ledger
        $this->assertSame(2, substr_count($body, ',"Commission assessed",'));                                         // one line per assessment
        $this->assertStringNotContainsString('Confirmed on (Kampala)', $body);                                      // customer payments are a separate statement
        $this->assertSame($statement['totals']['assessed_ugx'], 15000);

        // CSV: customer payments statement, with the same totals as the summary
        $pay = $this->fin($provider->owner, 'statements/payments.csv?period=all')->streamedContent();
        $this->assertStringContainsString('"# Confirmed by customers in the period (UGX)",'.$summary['customer_payments']['confirmed_ugx'], $pay);
        $this->assertStringContainsString('not counted as paid', $pay);
        $this->assertSame(2, substr_count($pay, ',cash,'));
        $this->assertStringNotContainsString('Opening balance', $pay);

        // PDF
        foreach (['commission', 'payments'] as $kind) {
            $pdf = $this->fin($provider->owner, "statements/{$kind}.pdf?period=all");
            $pdf->assertOk();
            $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
            $this->assertStringContainsString('attachment', $pdf->headers->get('Content-Disposition'));
            $bytes = $pdf->getContent();
            $this->assertStringStartsWith('%PDF-1.4', $bytes);
            $this->assertStringEndsWith('%%EOF', $bytes);
            $this->assertStringContainsString('Page 1 of 1', $bytes);
            $this->assertStringContainsString('UGX', $bytes);
        }
        $commissionPdf = $this->fin($provider->owner, 'statements/commission.pdf?period=all')->getContent();
        $this->assertStringContainsString('Closing balance \(still owed\)', $commissionPdf);   // (brackets are escaped inside a PDF string)
        $this->assertStringContainsString('UGX '.number_format($statement['totals']['closing_ugx']), $commissionPdf);
        $this->assertStringContainsString('matches the commission ledger', $commissionPdf);
        $this->assertStringContainsString('is not WorkMate', $this->fin($provider->owner, 'statements/payments.pdf?period=all')->getContent() ? 'is not WorkMate' : '');

        // Protected like the screens
        foreach (['commission.csv', 'commission.pdf', 'payments.csv', 'payments.pdf'] as $file) {
            $this->fin($manager, 'statements/'.$file.'?period=all')->assertStatus(403);
            $this->fin(User::factory()->create(), 'statements/'.$file.'?period=all')->assertStatus(403);
        }
        $this->signIn($manager);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true])->assertStatus(403);
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true])->assertOk();
        $this->fin($manager, 'statements/commission.pdf?period=all')->assertOk();
        // Nothing unknown: a bad kind or format, a bad period
        $this->fin($provider->owner, 'statements/secrets.csv')->assertStatus(404);
        $this->fin($provider->owner, 'statements/commission.xls')->assertStatus(404);
        $this->fin($provider->owner, 'statements/commission.csv?period=custom&from=2026-12-05&to=2026-12-01')->assertStatus(422);
    }

    public function test_one_business_can_never_get_another_businesss_statement_through_a_parameter()
    {
        $this->activate();
        $this->rule();
        $mine = $this->provider('Mine Plumbing');
        $theirs = $this->provider('Theirs Plumbing');
        $this->job($theirs, 400000);
        $csv = $this->fin($mine->owner, 'statements/commission.csv?period=all&provider='.$theirs->id.'&provider_id='.$theirs->id)->streamedContent();
        $this->assertStringContainsString('"# Business: Mine Plumbing"', $csv);
        $this->assertStringNotContainsString('Theirs', $csv);
        $this->assertStringContainsString('"# Closing balance (UGX)",0', $csv);
        $pdf = $this->fin($mine->owner, 'statements/payments.pdf?period=all&provider='.$theirs->id)->getContent();
        $this->assertStringContainsString('Mine Plumbing', $pdf);
        $this->assertStringNotContainsString('400,000', $pdf);
    }

    public function test_statements_and_screens_show_nothing_internal_such_as_adjustment_requests_or_admin_notes()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->job($provider);
        $finance = $this->userWithRoles(['finance']);
        $this->signIn($finance);
        $this->postJson('/api/v1/admin/financial/adjustments', [
            'provider_id' => $provider->id, 'type' => 'accounting_error', 'direction' => 'decrease', 'amount_ugx' => 5000, 'cycle_id' => $this->cycleOf($provider),
            'reason' => 'INTERNAL: suspected double counting, do not tell the provider.',
        ])->assertCreated();
        $dump = '';
        foreach (['summary?period=all', 'statement?period=all', 'cycles', 'cycles/'.$this->cycleOf($provider), 'commission-payments', 'payments?period=all', 'access'] as $path) {
            $dump .= json_encode($this->fin($provider->owner, $path)->assertOk()->json());
        }
        foreach (['commission', 'payments'] as $kind) {
            $dump .= $this->fin($provider->owner, "statements/{$kind}.csv?period=all")->streamedContent().$this->fin($provider->owner, "statements/{$kind}.pdf?period=all")->getContent();
        }
        $this->assertStringNotContainsString('INTERNAL', $dump);
        $this->assertStringNotContainsStringIgnoringCase('adjustment', $dump);
        $this->assertStringNotContainsStringIgnoringCase('double counting', $dump);
    }

    /* ---------------- Switches ---------------- */

    public function test_history_stays_readable_with_every_switch_off_while_new_activity_stays_blocked()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        [$firstJob] = $this->job($provider);
        $this->job($provider, 100000);
        $cycle = $this->cycleOf($provider);
        $this->signIn($provider->owner);
        $paid = $this->postJson('/api/v1/me/commission/payments', ['cycle_id' => $cycle, 'amount' => 5000, 'channel' => 'mtn_momo', 'reference' => 'MP-OFF-1'])->assertCreated()->json('data.id');
        $this->signIn($this->userWithRoles(['finance']));
        $this->postJson("/api/v1/admin/settlements/payments/{$paid}/verify", ['amount_ugx' => 5000, 'receipt_confirmed' => true])->assertOk();

        $on = [
            'summary' => $this->fin($provider->owner, 'summary?period=all')->json('data'), 'statement' => $this->fin($provider->owner, 'statement?period=all')->json('data'),
            'cycles' => $this->fin($provider->owner, 'cycles')->json('data'), 'payments' => $this->fin($provider->owner, 'payments?period=all')->json('data'),
        ];
        $this->activate(false);                                        // every financial switch off
        $this->setting('finance.commission_assessment_from', null);
        foreach (['finance.pricing_enabled', 'finance.commission_shadow_enabled', 'finance.commission_assessment_enabled', 'finance.commission_settlement_enabled'] as $key) {
            $this->assertFalse((bool) app(PlatformSettings::class)->get($key), $key);
        }
        $off = [
            'summary' => $this->fin($provider->owner, 'summary?period=all')->assertOk()->json('data'), 'statement' => $this->fin($provider->owner, 'statement?period=all')->assertOk()->json('data'),
            'cycles' => $this->fin($provider->owner, 'cycles')->assertOk()->json('data'), 'payments' => $this->fin($provider->owner, 'payments?period=all')->assertOk()->json('data'),
        ];
        // The history is the same, figure for figure
        $this->assertSame($on['summary']['customer_payments'], $off['summary']['customer_payments']);
        $this->assertSame($on['summary']['commission']['period'], $off['summary']['commission']['period']);
        $this->assertSame($on['summary']['commission']['as_of']['outstanding_ugx'], $off['summary']['commission']['as_of']['outstanding_ugx']);
        $this->assertSame($on['statement']['totals'], $off['statement']['totals']);
        $this->assertSame($on['cycles']['total'], $off['cycles']['total']);
        $this->assertSame($on['payments']['total'], $off['payments']['total']);
        $this->fin($provider->owner, 'cycles/'.$cycle)->assertOk();
        $this->fin($provider->owner, 'statements/commission.pdf?period=all')->assertOk();
        $this->fin($provider->owner, 'statements/payments.csv?period=all')->assertOk();
        $this->fin($provider->owner, 'commission-payments')->assertOk();
        // The backend says what is not available, and refuses it
        $this->assertFalse($off['summary']['commission']['can_report_payment']);
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/commission/payments', ['cycle_id' => $cycle, 'amount' => 1000, 'channel' => 'mtn_momo', 'reference' => 'MP-OFF-2'])->assertStatus(409);
        // and creates no new obligations
        $before = [CommissionAssessment::count(), JobPaymentReport::count()];
        $this->postJson("/api/v1/me/received-requests/{$firstJob}/payments", ['amount' => 1000, 'method' => 'cash'])->assertStatus(409);
        $this->assertSame($before, [CommissionAssessment::count(), JobPaymentReport::count()]);
    }

    public function test_a_business_with_no_history_gets_an_honest_empty_account_and_no_invented_figures()
    {
        $provider = $this->provider();
        $s = $this->fin($provider->owner, 'summary?period=this_month')->assertOk()->json('data');
        $this->assertFalse($s['has_history']);
        $this->assertSame(['confirmed_ugx' => 0, 'confirmed_count' => 0, 'reported_ugx' => 0, 'reported_count' => 0, 'awaiting' => ['count' => 0, 'amount_ugx' => 0],
            'questioned' => ['count' => 0, 'amount_ugx' => 0], 'remaining_ugx' => 0, 'remaining_jobs' => 0], $s['customer_payments']);
        $this->assertSame([0, 0, 0, null], [$s['commission']['as_of']['outstanding_ugx'], $s['commission']['as_of']['overdue_ugx'], $s['commission']['period']['assessed_ugx'], $s['commission']['as_of']['current_cycle']]);
        foreach (['payments', 'cycles', 'commission-payments', 'disputes', 'statement?period=all'] as $path) {
            $data = $this->fin($provider->owner, $path)->assertOk()->json('data');
            $this->assertSame([], $data['items'], $path);
            $this->assertSame(0, $data['total'], $path);
        }
        $csv = $this->fin($provider->owner, 'statements/commission.csv?period=all')->streamedContent();
        $this->assertStringContainsString('"# Closing balance (UGX)",0', $csv);
        $pdf = $this->fin($provider->owner, 'statements/commission.pdf?period=all')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        // Not signed in
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
    }

    public function test_the_existing_job_payment_workflow_is_unchanged()
    {
        $this->setting('finance.pricing_enabled', true);
        $provider = $this->provider();
        [$id, $customer] = $this->agreed($provider);
        $payment = $this->report($provider, $id, 50000);
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => 50000])->assertOk()->assertJsonPath('data.payments.totals.confirmed', 50000);
        $this->signIn($provider->owner);
        $this->getJson("/api/v1/me/received-requests/{$id}/payments")->assertOk()->assertJsonPath('data.totals.confirmed', 50000);
        $this->assertSame(JobPaymentReport::CONFIRMED, JobPaymentReport::find($payment)->status);
        // My Finances says the same as the job does
        $this->assertSame(50000, $this->fin($provider->owner, 'summary?period=all')->json('data.customer_payments.confirmed_ugx'));
    }

    public function test_the_migration_adds_one_column_that_is_off_for_everyone()
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('provider_members', 'can_view_finances'));
        $provider = $this->provider();
        $this->team($provider);
        $this->assertSame(0, ProviderMember::where('can_view_finances', true)->count());
    }
}
