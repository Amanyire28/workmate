<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CommissionAdjustmentRequest;
use App\Models\CommissionAssessment;
use App\Models\CommissionException;
use App\Models\CommissionExceptionEvent;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPaymentReport;
use App\Models\CommissionSettlementCycle;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase B8: financial administration. Read-only overview and reports over the ledger, a two-step adjustment REQUEST workflow that posts
 * nothing, exception investigation with a "no financial change" resolution only, CSV exports and the financial audit trail.
 */
class FinancialAdministrationTest extends ApiTestCase
{
    /** @var User */
    private $root;

    /** @var User */
    private $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->userWithRoles(['super_admin']);
        $this->finance = $this->userWithRoles(['finance']);
    }

    private function setting(string $key, $value): void
    {
        app(PlatformSettings::class)->set($key, $value, $this->root);
    }

    private function activate(): void
    {
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

    /** A job with a customer-confirmed payment of $amount (5% of it is the obligation): the request id */
    private function job(ProviderProfile $provider, int $amount = 200000): int
    {
        $customer = User::factory()->create();
        $this->signIn($customer);
        $id = $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow morning',
        ])->assertCreated()->json('data.id');
        ServiceRequest::whereKey($id)->update(['status' => 'in_progress']);
        $this->signIn($provider->owner);
        $version = $this->postJson("/api/v1/me/received-requests/{$id}/agreements", ['amount' => 5000000, 'scope' => 'Replace the kitchen sink pipes and fit a new tap.'])->assertCreated()->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$version}/accept", ['amount' => 5000000])->assertOk();
        $this->signIn($provider->owner);
        $reports = $this->postJson("/api/v1/me/received-requests/{$id}/payments", ['amount' => $amount, 'method' => 'cash'])->assertCreated()->json('data.payments.reports');
        $payment = (int) end($reports)['id'];
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => $amount])->assertOk();

        return $id;
    }

    private function cycleOf(ProviderProfile $provider, int $nth = 0): int
    {
        return (int) CommissionSettlementCycle::where('provider_profile_id', $provider->id)->orderBy('id')->skip($nth)->value('id');
    }

    private function reportPayment(ProviderProfile $provider, int $cycle, int $amount, array $extra = [])
    {
        $this->signIn($provider->owner);

        return $this->postJson('/api/v1/me/commission/payments', $extra + ['cycle_id' => $cycle, 'amount' => $amount, 'channel' => 'mtn_momo', 'reference' => 'MP'.random_int(100000, 999999)]);
    }

    private function verify(int $payment, int $amount): void
    {
        $this->signIn($this->finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$payment}/verify", ['amount_ugx' => $amount, 'receipt_confirmed' => true, 'note' => 'Checked against the account statement'])->assertOk();
    }

    /** A provider with three jobs (UGX 30,000 owed, one cycle due), part of it paid and verified: [provider, cycle id] */
    private function scenario(int $paid = 10000): array
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        for ($i = 0; $i < 3; $i++) {
            $this->job($provider);
        }
        $cycle = $this->cycleOf($provider);
        if ($paid) {
            $payment = $this->reportPayment($provider, $cycle, $paid)->assertCreated()->json('data.id');
            $this->verify($payment, $paid);
        }

        return [$provider, $cycle];
    }

    private function owed(ProviderProfile $provider): int
    {
        return (int) CommissionLedgerEntry::where('provider_profile_id', $provider->id)->sum('amount_ugx');
    }

    private function propose(ProviderProfile $provider, array $extra = [], ?User $by = null)
    {
        $this->signIn($by ?: $this->finance);

        return $this->postJson('/api/v1/admin/financial/adjustments', $extra + [
            'provider_id' => $provider->id, 'type' => 'accounting_error', 'direction' => 'decrease', 'amount_ugx' => 5000,
            'cycle_id' => $this->cycleOf($provider), 'reason' => 'The statement shows this amount was counted twice in the cycle.',
        ]);
    }

    /* ---------------- Permissions ---------------- */

    public function test_each_action_needs_its_own_permission_and_providers_and_customers_get_none()
    {
        [$provider, $cycle] = $this->scenario();
        $request = $this->propose($provider)->assertCreated()->json('data.id');
        $support = $this->userWithRoles(['support']);
        $customer = User::factory()->create();

        $reads = ['overview', 'reports/obligations', 'reports/obligations.csv', 'audit', 'adjustments', "adjustments/{$request}", 'exceptions'];
        foreach ([$support, $provider->owner, $customer] as $denied) {
            $this->signIn($denied);
            foreach ($reads as $path) {
                $this->getJson('/api/v1/admin/financial/'.$path)->assertForbidden();
            }
            $this->postJson('/api/v1/admin/financial/adjustments', [])->assertForbidden();
            $this->postJson("/api/v1/admin/financial/adjustments/{$request}/approve", ['reason' => 'Trying to approve this.'])->assertForbidden();
        }
        // Finance: everything except approving and resolving
        $this->signIn($this->finance);
        foreach ($reads as $path) {
            $this->get('/api/v1/admin/financial/'.$path, ['Accept' => 'application/json'])->assertOk();
        }
        $this->postJson("/api/v1/admin/financial/adjustments/{$request}/approve", ['reason' => 'Finance may not approve.'])->assertForbidden();
        $this->postJson("/api/v1/admin/financial/adjustments/{$request}/reject", ['reason' => 'Finance may not reject.'])->assertForbidden();
        $this->postJson('/api/v1/admin/financial/exceptions/1/resolve', ['note' => str_repeat('x', 25), 'acknowledged' => true])->assertForbidden();
        $this->assertSame(CommissionAdjustmentRequest::PENDING, CommissionAdjustmentRequest::find($request)->status);

        $holders = function (string $key) {
            return DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')->join('roles as r', 'r.id', '=', 'pr.role_id')
                ->where('p.key', $key)->orderBy('r.key')->pluck('r.key')->all();
        };
        foreach (['finance.overview.view', 'finance.reports.view', 'finance.adjustments.request', 'finance.exceptions.review'] as $key) {
            $this->assertSame(['finance', 'super_admin'], $holders($key), $key);
        }
        foreach (['finance.adjustments.approve', 'finance.exceptions.resolve'] as $key) {
            $this->assertSame(['super_admin'], $holders($key), $key);
        }
    }

    public function test_everything_is_off_by_default_and_the_pages_read_an_empty_ledger()
    {
        $config = config('workmate.settings');
        foreach (['finance.pricing_enabled', 'finance.commission_shadow_enabled', 'finance.commission_assessment_enabled', 'finance.commission_settlement_enabled'] as $key) {
            $this->assertFalse($config[$key]['default'], $key);
        }
        $this->assertNull($config['finance.commission_assessment_from']['default']);
        $this->signIn($this->finance);
        $o = $this->getJson('/api/v1/admin/financial/overview')->assertOk()->json('data');
        $this->assertSame([0, 0, 0, 0, 0], [$o['period']['assessed_ugx'], $o['period']['collected_ugx'], $o['as_of']['outstanding_ugx'], $o['as_of']['overdue_ugx'], $o['as_of']['open_exceptions']['count']]);
        $this->assertSame(0, CommissionAssessment::count());
    }

    /* ---------------- Overview ---------------- */

    public function test_the_overview_separates_the_period_from_where_things_stand_now_and_matches_the_ledger()
    {
        [$provider, $cycle] = $this->scenario(10000);
        // A second provider that has paid more than it owed (a credit), a pending report and a rejected one
        $other = $this->provider();
        $this->job($other);
        $oc = $this->cycleOf($other);
        $p = $this->reportPayment($other, $oc, 10000)->assertCreated()->json('data.id');
        $this->verify($p, 10000);                                       // settled exactly
        $pending = $this->reportPayment($provider, $cycle, 5000)->assertCreated()->json('data.id');
        $rejected = $this->reportPayment($provider, $cycle, 3000)->assertCreated()->json('data.id');
        $this->signIn($this->finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$rejected}/reject", ['reason' => 'Not in our statement at all.'])->assertOk();

        $o = $this->getJson('/api/v1/admin/financial/overview')->assertOk()->json('data');
        $this->assertSame([40000, 0, 40000], [$o['period']['assessed_ugx'], $o['period']['reversed_ugx'], $o['period']['net_obligations_ugx']]);
        $this->assertSame([20000, 20000, 0, 2], [$o['period']['collected_ugx'], $o['period']['applied_ugx'], $o['period']['excess_ugx'], $o['period']['collected_payments']]);   // verified only: not the pending 5,000, not the rejected 3,000
        $this->assertSame(20000, $o['as_of']['outstanding_ugx']);                                // 40,000 assessed less 20,000 verified
        $this->assertSame(['count' => 1, 'amount_ugx' => 5000], $o['as_of']['pending_verification']);
        $this->assertSame($o['as_of']['outstanding_ugx'], (int) CommissionLedgerEntry::sum('amount_ugx'));
        $this->assertSame(0, $o['as_of']['overdue_ugx']);
        $this->assertSame(0, $o['period']['posted_adjustments_ugx']);
        $this->assertSame(['from', 'to', 'label'], array_slice(array_keys($o['period']), 0, 3));
        $this->assertStringContainsString('Collected is money WorkMate verified received', json_encode($o['notes']));

        // Narrowed to one provider
        $mine = $this->getJson('/api/v1/admin/financial/overview?provider='.$provider->id)->json('data');
        $this->assertSame([30000, 10000, 20000, 1], [$mine['period']['assessed_ugx'], $mine['period']['collected_ugx'], $mine['as_of']['outstanding_ugx'], $mine['as_of']['pending_verification']['count']]);

        // An overdue cycle appears in the position (not in the period), once its seven days pass
        $this->travel(8)->days();
        $late = $this->getJson('/api/v1/admin/financial/overview?provider='.$provider->id)->json('data');
        $this->assertSame([20000, 1], [$late['as_of']['overdue_ugx'], $late['as_of']['overdue_cycles']]);
    }

    public function test_the_period_is_explicit_and_activity_outside_it_is_not_counted()
    {
        [$provider, $cycle] = $this->scenario(10000);
        $this->travel(40)->days();
        $this->signIn($this->finance);
        $recent = $this->getJson('/api/v1/admin/financial/overview')->json('data');                     // the last 30 days: nothing
        $this->assertSame([0, 0], [$recent['period']['assessed_ugx'], $recent['period']['collected_ugx']]);
        $this->assertSame(20000, $recent['as_of']['outstanding_ugx']);                                  // the position is still there
        $then = $this->getJson('/api/v1/admin/financial/overview?from='.now()->subDays(45)->toDateString().'&to='.now()->subDays(35)->toDateString())->json('data');
        $this->assertSame([30000, 10000], [$then['period']['assessed_ugx'], $then['period']['collected_ugx']]);

        $this->getJson('/api/v1/admin/financial/overview?from=2026-12-10&to=2026-12-01')->assertStatus(422)->assertJsonPath('code', 'period_invalid');
        $this->getJson('/api/v1/admin/financial/overview?from=2024-01-01&to=2026-12-01')->assertStatus(422)->assertJsonPath('code', 'period_too_long');
        $this->getJson('/api/v1/admin/financial/overview?from=not-a-date')->assertStatus(422);
    }

    public function test_a_provider_credit_does_not_reduce_what_another_provider_owes()
    {
        $this->activate();
        $this->rule();
        $a = $this->provider();
        $b = $this->provider();
        $this->job($a);
        $this->job($b);
        $ca = $this->cycleOf($a);
        $payment = $this->reportPayment($a, $ca, 10000)->assertCreated()->json('data.id');
        $this->verify($payment, 10000);
        // Reverse A's job after it was paid: A now has a credit of 10,000 while B still owes 10,000
        ServiceRequest::whereKey(CommissionAssessment::where('provider_profile_id', $a->id)->value('service_request_id'))->update(['status' => 'cancelled']);
        $this->artisan('workmate:commission-assess')->assertExitCode(0);
        $this->assertSame(-10000, $this->owed($a));
        $this->signIn($this->finance);
        $o = $this->getJson('/api/v1/admin/financial/overview')->json('data');
        $this->assertSame([10000, 10000], [$o['as_of']['outstanding_ugx'], $o['as_of']['provider_credit_ugx']]);   // not 0
        $this->assertSame(1, $o['as_of']['open_exceptions']['count']);
    }

    /* ---------------- Adjustments ---------------- */

    public function test_a_finance_administrator_proposes_an_adjustment_and_nothing_about_what_is_owed_changes()
    {
        [$provider, $cycle] = $this->scenario(10000);
        $before = [CommissionLedgerEntry::count(), $this->owed($provider), json_encode(app(\App\Services\CommissionSettlements::class)->state($cycle))];

        $this->propose($provider, ['reference' => 'MTN statement line 14', 'provider_reason' => 'A counting error was corrected.'])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.posting_status', 'not_posted')->assertJsonPath('data.requested_by', $this->finance->name);
        $row = CommissionAdjustmentRequest::first();
        $this->assertSame([$provider->id, 'accounting_error', 'decrease', 5000, $cycle, $this->finance->id], [$row->provider_profile_id, $row->type, $row->direction, $row->amount_ugx, $row->cycle_id, $row->requested_by_user_id]);
        $this->assertSame($before, [CommissionLedgerEntry::count(), $this->owed($provider), json_encode(app(\App\Services\CommissionSettlements::class)->state($cycle))]);
        $audit = AuditLog::where('action', 'finance.adjustment_requested')->first();
        $this->assertSame([$this->finance->id, 5000, $provider->id], [$audit->actor_id, $audit->after['amount_ugx'], $audit->after['provider_profile_id']]);
        $this->assertStringContainsString('counted twice', $audit->reason);
    }

    public function test_an_adjustment_request_is_checked_and_cannot_be_opened_twice()
    {
        [$provider, $cycle] = $this->scenario(10000);
        $other = $this->provider();
        $this->propose($provider, ['amount_ugx' => 0])->assertStatus(422);
        $this->propose($provider, ['amount_ugx' => 5.5])->assertStatus(422);
        $this->propose($provider, ['type' => 'refund'])->assertStatus(422);
        $this->propose($provider, ['direction' => 'sideways'])->assertStatus(422);
        $this->propose($provider, ['reason' => 'Too short.'])->assertStatus(422);
        $this->propose($provider, ['cycle_id' => null])->assertStatus(422)->assertJsonPath('code', 'subject_required');
        $this->propose($provider, ['provider_id' => 999999])->assertStatus(422);
        // It must be about THIS provider's records
        $this->propose($other, ['cycle_id' => $cycle])->assertStatus(422)->assertJsonPath('code', 'reference_invalid');
        $this->assertSame(0, CommissionAdjustmentRequest::count());

        $first = $this->propose($provider)->assertCreated()->json('data.id');
        $this->propose($provider)->assertStatus(409)->assertJsonPath('code', 'duplicate_request');
        $this->propose($provider, ['amount_ugx' => 6000])->assertCreated();                 // a different amount is a different request
        // A retry with the same token returns the first request
        $one = $this->propose($provider, ['amount_ugx' => 7000, 'client_token' => 'tok'])->assertCreated()->json('data.id');
        $two = $this->propose($provider, ['amount_ugx' => 7000, 'client_token' => 'tok'])->assertCreated()->json('data.id');
        $this->assertSame($one, $two);
        $this->assertSame(3, CommissionAdjustmentRequest::count());
        $this->assertNotNull($first);
    }

    public function test_a_decrease_for_an_obligation_that_was_already_reversed_is_refused_and_so_is_an_adjustment_on_your_own_business()
    {
        [$provider] = $this->scenario(0);
        $assessment = CommissionAssessment::first();
        ServiceRequest::whereKey($assessment->service_request_id)->update(['status' => 'cancelled']);
        $this->artisan('workmate:commission-assess')->assertExitCode(0);
        $this->propose($provider, ['cycle_id' => null, 'assessment_id' => $assessment->id])->assertStatus(409)->assertJsonPath('code', 'already_reversed');
        $this->propose($provider, ['cycle_id' => null, 'payment_report_id' => $assessment->payment_report_id])->assertStatus(409)->assertJsonPath('code', 'already_reversed');
        // An increase is a different matter: it is not a second correction of the same thing
        $this->propose($provider, ['cycle_id' => null, 'assessment_id' => $assessment->id, 'direction' => 'increase'])->assertCreated();

        // A finance administrator who belongs to the provider's business cannot propose for it
        $insider = $this->userWithRoles(['finance']);
        $provider->members()->create(['user_id' => $insider->id, 'role' => ProviderMember::ROLE_MEMBER]);
        $this->propose($provider, ['amount_ugx' => 1234], $insider)->assertStatus(403)->assertJsonPath('code', 'own_business');
    }

    public function test_only_a_different_super_administrator_decides_and_approving_posts_nothing()
    {
        [$provider, $cycle] = $this->scenario(10000);
        $id = $this->propose($provider)->assertCreated()->json('data.id');
        $second = $this->userWithRoles(['super_admin']);
        $ledger = [CommissionLedgerEntry::count(), $this->owed($provider)];

        // The proposer, even a Super Administrator, cannot decide their own request
        $selfProposed = $this->propose($provider, ['amount_ugx' => 2500], $this->root)->assertCreated()->json('data.id');
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/financial/adjustments/{$selfProposed}/approve", ['reason' => 'Approving my own request.'])->assertStatus(403)->assertJsonPath('code', 'own_request');
        $this->postJson("/api/v1/admin/financial/adjustments/{$selfProposed}/reject", ['reason' => 'Rejecting my own request.'])->assertStatus(403);

        $this->postJson("/api/v1/admin/financial/adjustments/{$id}/approve", [])->assertStatus(422);
        $this->postJson("/api/v1/admin/financial/adjustments/{$id}/approve", ['reason' => 'short'])->assertStatus(422);
        $this->assertSame(CommissionAdjustmentRequest::PENDING, CommissionAdjustmentRequest::find($id)->status);

        $this->postJson("/api/v1/admin/financial/adjustments/{$id}/approve", ['reason' => 'Checked the statement and the cycle: agreed.'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.posting_status', 'not_posted')->assertJsonPath('data.decided_by', $this->root->name);
        // The same decision again is a retry; anything else, or someone else, is "already decided"
        $this->postJson("/api/v1/admin/financial/adjustments/{$id}/approve", ['reason' => 'Checked the statement and the cycle: agreed.'])->assertOk();
        $this->postJson("/api/v1/admin/financial/adjustments/{$id}/reject", ['reason' => 'Changing my mind afterwards.'])->assertStatus(409);
        $this->signIn($second);
        $this->postJson("/api/v1/admin/financial/adjustments/{$id}/approve", ['reason' => 'A second approval of the same one.'])->assertStatus(409);

        // Nothing was posted: the ledger and what the provider owes are exactly as before, and the CHECK still refuses any adjustment line
        $this->assertSame($ledger, [CommissionLedgerEntry::count(), $this->owed($provider)]);
        $this->assertSame(1, AuditLog::where('action', 'finance.adjustment_approved')->count());
        $this->assertSame($this->root->id, AuditLog::where('action', 'finance.adjustment_approved')->value('actor_id'));
        try {
            DB::table('commission_ledger_entries')->insert([
                'assessment_id' => null, 'cycle_id' => $cycle, 'commission_payment_id' => null, 'provider_profile_id' => $provider->id, 'entry_type' => 'adjustment',
                'amount_ugx' => -5000, 'reference' => 'adjust:'.$id, 'created_at' => now(),
            ]);
            $this->fail('The ledger accepted an adjustment line');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(true);
        }
        // The overview lists it as approved and NOT posted
        $this->signIn($this->finance);
        $o = $this->getJson('/api/v1/admin/financial/overview')->json('data');
        $this->assertSame([1, 0, 5000, false], [$o['period']['approved_adjustments']['count'], $o['period']['approved_adjustments']['increase_ugx'], $o['period']['approved_adjustments']['decrease_ugx'], $o['period']['approved_adjustments']['posted']]);
        $list = $this->getJson('/api/v1/admin/financial/adjustments')->json('data');
        $this->assertFalse($list['posting']['enabled']);
        $this->assertSame(['approved' => 1, 'pending' => 1], array_intersect_key($list['counts'], ['approved' => 1, 'pending' => 1]));
    }

    public function test_a_rejected_request_creates_nothing_and_its_place_is_released()
    {
        [$provider] = $this->scenario(0);
        $id = $this->propose($provider)->assertCreated()->json('data.id');
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/financial/adjustments/{$id}/reject", ['reason' => 'No evidence for this was attached.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(0, CommissionLedgerEntry::where('entry_type', 'adjustment')->count());
        $this->assertNull(CommissionAdjustmentRequest::find($id)->dedupe_key);
        $again = $this->propose($provider, ['reference' => 'Now with the statement attached'])->assertCreated()->json('data.id');   // can be proposed again
        $this->assertNotSame($id, $again);
        $this->assertTrue(AuditLog::where('action', 'finance.adjustment_rejected')->exists());
        try {
            CommissionAdjustmentRequest::find($id)->forceFill(['status' => 'pending'])->save();
            $this->fail('A decided request moved');
        } catch (LogicException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_adjustment_requests_can_never_be_edited_after_they_are_made_or_deleted()
    {
        [$provider] = $this->scenario(0);
        $id = $this->propose($provider)->assertCreated()->json('data.id');
        foreach ([['amount_ugx' => 1], ['reason' => 'changed'], ['provider_profile_id' => 1], ['requested_by_user_id' => 1]] as $change) {
            try {
                CommissionAdjustmentRequest::find($id)->forceFill($change)->save();
                $this->fail('A request was edited');
            } catch (LogicException $e) {
                $this->assertTrue(true);
            }
        }
        try {
            CommissionAdjustmentRequest::find($id)->delete();
            $this->fail('A request was deleted');
        } catch (LogicException $e) {
            $this->assertTrue(true);
        }
    }

    /* ---------------- Exceptions ---------------- */

    /** An overpayment exception: 30,000 owed, 50,000 verified */
    private function overpayment(): array
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        for ($i = 0; $i < 3; $i++) {
            $this->job($provider);
        }
        $cycle = $this->cycleOf($provider);
        $payment = $this->reportPayment($provider, $cycle, 30000)->assertCreated()->json('data.id');
        $this->verify($payment, 50000);

        return [$provider, CommissionException::first(), $payment];
    }

    public function test_an_exception_is_taken_annotated_and_closed_only_with_a_documented_no_change_resolution()
    {
        [$provider, $exception, $payment] = $this->overpayment();
        $this->assertSame(['overpayment', 20000, 'open'], [$exception->kind, $exception->amount_ugx, $exception->status]);
        $other = $this->userWithRoles(['finance']);

        $this->signIn($this->finance);
        $list = $this->getJson('/api/v1/admin/financial/exceptions')->assertOk()->json('data');
        $this->assertSame([1, 'open'], [$list['total'], $list['items'][0]['status']]);
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/take")->assertOk()->assertJsonPath('data.status', 'under_review')->assertJsonPath('data.assigned_to', $this->finance->name);
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/take")->assertOk();                // the same reviewer again: nothing new
        $this->assertSame(1, CommissionExceptionEvent::where('event', 'taken')->count());
        $this->signIn($other);
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/take")->assertStatus(409)->assertJsonPath('code', 'already_taken');

        $this->signIn($this->finance);
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/notes", ['note' => 'no'])->assertStatus(422);
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/notes", ['note' => 'The MTN statement shows 50,000 received on the 12th.', 'reference' => 'MTN-STMT-12'])->assertOk();
        $detail = $this->getJson("/api/v1/admin/financial/exceptions/{$exception->id}")->assertOk()->json('data');
        $this->assertSame(['taken', 'noted'], array_column($detail['events'], 'event'));
        $this->assertSame('MTN-STMT-12', $detail['events'][1]['reference']);
        $this->assertSame($payment, $detail['payment']['id']);
        $this->assertSame($provider->id, $detail['provider_id']);
        $this->assertContains('settlement', array_column($detail['ledger'], 'type'));
        $this->assertStringContainsString('not approved policies', $detail['pending_decision']);   // says what is undecided

        // Finance can investigate but cannot close it
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/resolve", ['note' => str_repeat('Documented. ', 3), 'acknowledged' => true])->assertForbidden();

        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/resolve", ['note' => str_repeat('Documented. ', 3), 'acknowledged' => false])->assertStatus(422)->assertJsonPath('code', 'acknowledgement_required');
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/resolve", ['note' => 'Too short.', 'acknowledged' => true])->assertStatus(422);
        $this->assertSame('under_review', CommissionException::find($exception->id)->status);
        $balance = $this->owed($provider);
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/resolve", ['note' => 'The provider asked for it to stay as a credit; the business has agreed.', 'acknowledged' => true])->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution', 'no_financial_change')->assertJsonPath('data.resolved_by', $this->root->name);
        $this->assertSame($balance, $this->owed($provider));                                                      // no balance changed
        $this->assertSame(0, CommissionLedgerEntry::whereNotIn('entry_type', ['assessment', 'reversal', 'settlement'])->count());
        $this->assertTrue(AuditLog::where('action', 'finance.exception_resolved')->where('actor_id', $this->root->id)->exists());
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/resolve", ['note' => 'The provider asked for it to stay as a credit; the business has agreed.', 'acknowledged' => true])->assertOk();   // retry
        $this->signIn($this->finance);
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/notes", ['note' => 'A note after it was closed.'])->assertStatus(409);
        foreach ([function () use ($exception) {
            CommissionExceptionEvent::where('exception_id', $exception->id)->first()->forceFill(['note' => 'x'])->save();
        }, function () use ($exception) {
            CommissionExceptionEvent::where('exception_id', $exception->id)->first()->delete();
        }, function () use ($exception) {
            CommissionException::find($exception->id)->delete();
        }] as $attempt) {
            try {
                $attempt();
                $this->fail('History was changed');
            } catch (LogicException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_an_exception_with_an_adjustment_in_progress_cannot_be_closed()
    {
        [$provider, $exception] = $this->overpayment();
        $id = $this->propose($provider, ['type' => 'reconciliation_correction', 'cycle_id' => null, 'exception_id' => $exception->id, 'direction' => 'increase', 'amount_ugx' => 20000])->assertCreated()->json('data.id');
        $this->signIn($this->root);
        $note = 'Documented: nothing further needed for this one.';
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/resolve", ['note' => $note, 'acknowledged' => true])->assertStatus(409)->assertJsonPath('code', 'adjustment_in_progress');
        // Approved is still "in progress" (nothing is posted): the exception stays open until that is finished
        $this->postJson("/api/v1/admin/financial/adjustments/{$id}/approve", ['reason' => 'Approved so the exception can be dealt with.'])->assertOk();
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/resolve", ['note' => $note, 'acknowledged' => true])->assertStatus(409)->assertJsonPath('code', 'adjustment_in_progress');
        $this->assertSame('open', CommissionException::find($exception->id)->status);
    }

    public function test_the_catch_up_does_not_reopen_a_closed_exception_unless_the_excess_grows_and_closes_one_when_the_balance_returns()
    {
        [$provider, $exception] = $this->overpayment();
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/financial/exceptions/{$exception->id}/resolve", ['note' => 'Documented: the credit stays on the provider account for now.', 'acknowledged' => true])->assertOk();
        // The overpayment exception was about the payment; an excess credit arises when an obligation is reversed after payment
        ServiceRequest::whereKey(CommissionAssessment::where('provider_profile_id', $provider->id)->orderBy('id')->value('service_request_id'))->update(['status' => 'cancelled']);
        $this->artisan('workmate:commission-assess')->assertExitCode(0);
        $excess = CommissionException::where('kind', 'excess_credit')->first();
        $this->assertSame(['open', 10000], [$excess->status, $excess->amount_ugx]);

        // Taking it keeps it the one live exception (no second is opened while it is being investigated)
        $this->signIn($this->finance);
        $this->postJson("/api/v1/admin/financial/exceptions/{$excess->id}/take")->assertOk();
        $this->artisan('workmate:commission-assess')->assertExitCode(0);
        $this->assertSame(1, CommissionException::where('kind', 'excess_credit')->count());
        // Closed with no financial change for this much: a catch-up does not bring it back
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/financial/exceptions/{$excess->id}/resolve", ['note' => 'Documented: left as a credit, business agrees.', 'acknowledged' => true])->assertOk();
        app(\App\Services\CommissionSettlements::class)->reconcileProvider($provider->id);
        $this->assertSame(1, CommissionException::where('kind', 'excess_credit')->count());
        // A further reversal makes the excess bigger than what was closed: a new exception
        ServiceRequest::whereKey(CommissionAssessment::where('provider_profile_id', $provider->id)->orderBy('id')->skip(1)->value('service_request_id'))->update(['status' => 'cancelled']);
        $this->artisan('workmate:commission-assess')->assertExitCode(0);
        $this->assertSame(2, CommissionException::where('kind', 'excess_credit')->count());
        $this->assertSame(20000, CommissionException::where('kind', 'excess_credit')->where('status', 'open')->value('amount_ugx'));
    }

    /* ---------------- Reports ---------------- */

    public function test_the_reports_show_the_ledger_and_only_verified_money_as_collected()
    {
        [$provider, $cycle] = $this->scenario(10000);
        $pending = $this->reportPayment($provider, $cycle, 5000)->assertCreated()->json('data.id');
        $this->signIn($this->finance);

        $a = $this->getJson('/api/v1/admin/financial/reports/obligations')->assertOk()->json('data');
        $this->assertSame(3, $a['total']);
        $this->assertSame([200000, '5%', 1, 10000, 0, 10000], [$a['items'][0]['payment_ugx'], $a['items'][0]['rate'], $a['items'][0]['policy_version'], $a['items'][0]['commission_ugx'], $a['items'][0]['reversed_ugx'], $a['items'][0]['outstanding_ugx']]);
        $this->assertSame('Plumbing', $a['items'][0]['category']);
        $this->assertArrayHasKey('Commission assessed (UGX)', array_flip($a['columns']));
        $this->assertCount(3, $this->getJson('/api/v1/admin/financial/reports/obligations?category='.\App\Models\Category::where('slug', 'plumbing')->value('id'))->json('data.items'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/financial/reports/obligations?profession=999999')->json('data.items'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/financial/reports/obligations?provider=999999')->json('data.items'));

        $b = $this->getJson('/api/v1/admin/financial/reports/collections')->json('data');
        $this->assertSame(1, $b['total']);                                                      // the pending report is not collected
        $this->assertSame(['mtn_momo', 10000, 10000, 0, $cycle], [$b['items'][0]['channel'], $b['items'][0]['reported_ugx'], $b['items'][0]['verified_ugx'], $b['items'][0]['excess_ugx'], $b['items'][0]['cycle']]);

        $c = $this->getJson('/api/v1/admin/financial/reports/outstanding')->json('data');
        $this->assertSame(1, $c['total']);
        $this->assertSame([20000, $cycle, 'partially_paid', 'No'], [$c['items'][0]['outstanding_ugx'], $c['items'][0]['cycle'], $c['items'][0]['cycle_status'], $c['items'][0]['overdue']]);
        $this->assertNotNull($c['items'][0]['last_verified_at']);
        $this->assertStringStartsWith('As of', $c['period']);

        $d = collect($this->getJson('/api/v1/admin/financial/reports/performance')->json('data.items'))->keyBy('metric');
        $this->assertSame([1, 1, 1], [$d['Partly paid cycles']['count'], $d['Verified payments in the period']['count'], $d['Payments awaiting verification now']['count']]);
        $this->assertSame([20000, 10000], [$d['Partly paid cycles']['amount_ugx'], $d['Verified payments in the period']['amount_ugx']]);

        $this->propose($provider)->assertCreated();
        $this->signIn($this->finance);
        $e = $this->getJson('/api/v1/admin/financial/reports/adjustments')->json('data');
        $this->assertSame(['adjustment'], array_column($e['items'], 'record'));
        $this->assertNotNull($pending);
        $this->getJson('/api/v1/admin/financial/reports/nonsense')->assertNotFound();
    }

    public function test_csv_exports_follow_the_filters_the_permissions_and_neutralise_formulas()
    {
        $this->activate();
        $this->rule();
        $evil = $this->provider('=HYPERLINK("http://evil.test","click")');
        $plus = $this->provider('+SUM(1+1)');
        $this->job($evil);
        $this->job($plus);
        $this->signIn($this->finance);

        $csv = $this->get('/api/v1/admin/financial/reports/obligations.csv', ['Accept' => 'text/csv']);
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $csv->headers->get('Cache-Control'));
        $body = $csv->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($body, "\xEF\xBB\xBF"))));
        $this->assertStringContainsString('# Commission obligations', $lines[0]);
        $this->assertMatchesRegularExpression('/# Period: \d{4}-\d{2}-\d{2} to \d{4}-\d{2}-\d{2}/', $lines[1]);
        $this->assertStringContainsString('Commission assessed (UGX)', $lines[3]);
        $this->assertCount(6, $lines);                       // 3 note lines, the header and two rows
        // Formula-like names are text, not formulas; the money columns are plain whole numbers
        $this->assertStringContainsString("\"'=HYPERLINK(", $body);
        $this->assertStringContainsString("'+SUM(1+1)", $body);
        $this->assertStringNotContainsString("\n=HYPERLINK", $body);
        $this->assertMatchesRegularExpression('/,200000,5%,1,10000,0,10000,0/', $body);
        // The filter narrows the export just as it narrows the screen
        $one = $this->get('/api/v1/admin/financial/reports/obligations.csv?provider='.$plus->id, ['Accept' => 'text/csv'])->streamedContent();
        $this->assertStringContainsString("'+SUM(1+1)", $one);
        $this->assertStringNotContainsString('HYPERLINK', $one);
        // No contact details are in any export
        $this->assertStringNotContainsString('@', preg_replace('/\'?[=+\-@]\S*/', '', $body));
        foreach (['email', 'phone'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $body);
        }
        // Not for people without the permission
        $this->signIn($this->userWithRoles(['support']));
        $this->get('/api/v1/admin/financial/reports/obligations.csv', ['Accept' => 'text/csv'])->assertForbidden();
        $this->signIn($evil->owner);
        $this->get('/api/v1/admin/financial/reports/collections.csv', ['Accept' => 'text/csv'])->assertForbidden();
        // The collections export has only verified money
        $this->signIn($this->finance);
        $none = $this->get('/api/v1/admin/financial/reports/collections.csv', ['Accept' => 'text/csv'])->streamedContent();
        $this->assertCount(4, array_filter(preg_split('/\r?\n/', ltrim($none, "\xEF\xBB\xBF"))));   // notes and header only
        $this->assertSame("'=1+1", \App\Services\FinancialReports::safe('=1+1'));
        $this->assertSame("'@x", \App\Services\FinancialReports::safe('@x'));
        $this->assertSame("'-5", \App\Services\FinancialReports::safe('-5'));
        $this->assertSame(-5, \App\Services\FinancialReports::safe(-5));
        $this->assertSame('', \App\Services\FinancialReports::safe(null));
        $this->assertSame('Okello', \App\Services\FinancialReports::safe('Okello'));
    }

    /* ---------------- Audit trail ---------------- */

    public function test_the_audit_trail_shows_the_financial_actions_with_who_why_and_which_provider()
    {
        [$provider] = $this->scenario(10000);
        $id = $this->propose($provider)->assertCreated()->json('data.id');
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/financial/adjustments/{$id}/reject", ['reason' => 'No supporting statement was attached.'])->assertOk();

        $this->signIn($this->finance);
        $all = $this->getJson('/api/v1/admin/financial/audit')->assertOk()->json('data');
        $actions = array_column($all['items'], 'action');
        foreach (['commission.obligation_assessed', 'commission.payment_reported', 'commission.payment_verified', 'finance.adjustment_requested', 'finance.adjustment_rejected'] as $action) {
            $this->assertContains($action, $actions, $action);
        }
        $this->assertNotContains('marketplace.payment_confirmed', $actions);    // only financial administration entries
        $rejected = collect($all['items'])->firstWhere('action', 'finance.adjustment_rejected');
        $this->assertSame([$this->root->name, 'No supporting statement was attached.'], [$rejected['by'], $rejected['reason']]);
        $this->assertSame($provider->id, $rejected['after']['provider_profile_id']);

        $this->assertSame(['finance.adjustment_requested'], array_values(array_unique(array_column($this->getJson('/api/v1/admin/financial/audit?action=finance.adjustment_req')->json('data.items'), 'action'))));
        $mine = $this->getJson('/api/v1/admin/financial/audit?provider='.$provider->id)->json('data.items');
        $this->assertNotEmpty($mine);
        $none = $this->getJson('/api/v1/admin/financial/audit?provider=999999')->json('data.items');
        $this->assertSame([], $none);
        // Read only: nothing here can change an entry
        $this->postJson('/api/v1/admin/financial/audit', [])->assertStatus(405);
        $this->assertSame(1, AuditLog::where('action', 'finance.adjustment_rejected')->count());
    }

    public function test_the_provider_commission_view_is_unchanged_and_shows_nothing_internal()
    {
        [$provider, $cycle] = $this->scenario(10000);
        $this->propose($provider, ['reason' => 'INTERNAL: suspected double counting, do not tell the provider.'])->assertCreated();
        $this->signIn($provider->owner);
        $json = json_encode($this->getJson('/api/v1/me/commission')->assertOk()->json('data'));
        $this->assertStringNotContainsString('INTERNAL', $json);
        $this->assertStringNotContainsString('double counting', $json);
        $this->assertStringNotContainsString('adjustment', strtolower($json));
        $this->assertSame(20000, $this->getJson('/api/v1/me/commission')->json('data.outstanding_ugx'));
    }

    public function test_the_two_migrations_are_additive_and_safe_to_run_again()
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('commission_adjustment_requests'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('commission_exceptions', ['assigned_to_user_id', 'resolution', 'resolved_by_user_id', 'resolved_at', 'resolution_note']));
        $migration = require_once base_path('database/migrations/2026_12_02_000078_add_financial_administration_permissions.php');
        $migration = is_object($migration) ? $migration : new \AddFinancialAdministrationPermissions();
        $before = [DB::table('permissions')->count(), DB::table('permission_role')->count()];
        $migration->up();
        $migration->up();
        $this->assertSame($before, [DB::table('permissions')->count(), DB::table('permission_role')->count()]);
        // The B7 ledger shape check is exactly as it was: an unknown line kind and a settlement without a cycle are still refused
        foreach ([['entry_type' => 'adjustment', 'cycle_id' => null], ['entry_type' => 'settlement', 'cycle_id' => null, 'commission_payment_id' => null]] as $bad) {
            try {
                DB::table('commission_ledger_entries')->insert($bad + ['assessment_id' => null, 'provider_profile_id' => 1, 'amount_ugx' => -1, 'reference' => 'x:'.random_int(1, 99999), 'created_at' => now()]);
                $this->fail('The ledger accepted a line it should refuse');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertTrue(true);
            }
        }
    }
}
