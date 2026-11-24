<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CommissionAssessment;
use App\Models\CommissionException;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPaymentReport;
use App\Models\CommissionSettlementCycle;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\CommissionSettlements;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Phase B7: settling commission. Obligations (B6) fall into cycles that are due after seven days or three distinct jobs, whichever is first;
 * a provider REPORTS paying WorkMate, and only finance verifying the receipt writes a credit into the same ledger. Nothing is collected, no
 * penalty exists, and everything is off by default.
 */
class CommissionSettlementTest extends ApiTestCase
{
    /** @var User */
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->root = $this->userWithRoles(['super_admin']);
    }

    private function setting(string $key, $value): void
    {
        app(PlatformSettings::class)->set($key, $value, $this->root);
    }

    private function activate(bool $settlement = true): void
    {
        $this->setting('finance.pricing_enabled', true);
        $this->setting('finance.commission_shadow_enabled', true);
        $this->setting('finance.commission_assessment_enabled', true);
        $this->setting('finance.commission_assessment_from', now()->subHour()->toIso8601String());
        $this->setting('finance.commission_settlement_enabled', $settlement);
    }

    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Services '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
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

    /** A job with a customer-confirmed payment of $amount (5% of it becomes the obligation): [request id, payment id, customer] */
    private function job(ProviderProfile $provider, int $amount = 200000): array
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
        $payment = $this->paymentFor($provider, $id, $customer, $amount);

        return [$id, $payment, $customer];
    }

    private function paymentFor(ProviderProfile $provider, int $id, User $customer, int $amount): int
    {
        $this->signIn($provider->owner);
        $reports = $this->postJson("/api/v1/me/received-requests/{$id}/payments", ['amount' => $amount, 'method' => 'cash'])->assertCreated()->json('data.payments.reports');
        $payment = (int) end($reports)['id'];
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => $amount])->assertOk();

        return $payment;
    }

    /** Three jobs of UGX 10,000 commission each: a cycle that is due at once */
    private function threeJobs(ProviderProfile $provider): array
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->job($provider)[0];
        }

        return $ids;
    }

    private function cycleOf(ProviderProfile $provider, int $nth = 0): int
    {
        return (int) CommissionSettlementCycle::where('provider_profile_id', $provider->id)->orderBy('id')->skip($nth)->value('id');
    }

    private function state(int $cycle): array
    {
        return app(CommissionSettlements::class)->state($cycle);
    }

    private function owed(ProviderProfile $provider): int
    {
        return (int) CommissionLedgerEntry::where('provider_profile_id', $provider->id)->sum('amount_ugx');
    }

    private function report(ProviderProfile $provider, int $cycle, array $extra = [])
    {
        $this->signIn($provider->owner);

        return $this->postJson('/api/v1/me/commission/payments', $extra + ['cycle_id' => $cycle, 'amount' => 10000, 'channel' => 'mtn_momo', 'reference' => 'MP'.random_int(100000, 999999)]);
    }

    private function verify(User $admin, int $payment, int $amount, array $extra = [])
    {
        $this->signIn($admin);

        return $this->postJson("/api/v1/admin/settlements/payments/{$payment}/verify", $extra + ['amount_ugx' => $amount, 'receipt_confirmed' => true]);
    }

    private function note(User $user, string $title)
    {
        return UserNotification::where('user_id', $user->id)->where('title', $title);
    }

    /* ---------------- Off by default ---------------- */

    public function test_everything_is_off_by_default_and_nothing_is_written_while_settlement_is_off()
    {
        $config = config('workmate.settings');
        $this->assertFalse($config['finance.commission_settlement_enabled']['default']);
        $this->assertFalse($config['finance.commission_accepts_cash']['default']);
        $this->assertNull($config['finance.commission_payment_instructions']['default']);
        $this->assertSame(0, $config['finance.settlement_three_job_window_hours']['default']);
        $this->assertFalse($config['finance.commission_assessment_enabled']['default']);
        $this->assertNull($config['finance.commission_assessment_from']['default']);

        $this->activate(false);
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $this->assertSame(3, CommissionAssessment::count());           // B6 obligations exist
        $this->assertSame(0, CommissionSettlementCycle::count());     // but no cycle, no reminder, nothing
        $this->assertSame(0, DB::table('commission_settlement_notices')->count());
        $this->signIn($provider->owner);
        $this->assertNull($this->getJson('/api/v1/me/commission')->json('data.settlement'));
        $this->postJson('/api/v1/me/commission/payments', ['cycle_id' => 1, 'amount' => 1000, 'channel' => 'mtn_momo', 'reference' => 'X1'])->assertStatus(409);
        $this->artisan('workmate:commission-settlements')->expectsOutput('Commission settlement is switched off: nothing to do.')->assertExitCode(0);
        $this->assertSame(0, CommissionPaymentReport::count());
    }

    /* ---------------- Cycles and triggers ---------------- */

    public function test_three_distinct_jobs_make_the_cycle_due_at_once_and_the_fourth_job_starts_a_new_cycle()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        [$j1, , $c1] = $this->job($provider);
        $this->assertSame('open', $this->state($this->cycleOf($provider))['status']);
        // A second payment on the SAME job is not another job
        $this->paymentFor($provider, $j1, $c1, 100000);
        $first = $this->state($this->cycleOf($provider));
        $this->assertSame([2, 'open', 15000], [CommissionAssessment::count(), $first['status'], $first['payable_ugx']]);
        $this->assertSame(1, $first['jobs']);
        $this->job($provider);
        $this->assertSame(2, $this->state($this->cycleOf($provider))['jobs']);
        $this->assertSame(0, DB::table('commission_settlement_notices')->count());

        $this->job($provider);
        $cycle = $this->cycleOf($provider);
        $s = $this->state($cycle);
        $this->assertSame([3, 'due', 'three_jobs', 35000], [$s['jobs'], $s['status'], $s['trigger'], $s['payable_ugx']]);
        $this->assertSame(1, $this->note($provider->owner, 'Your commission is due')->count());
        $this->assertStringNotContainsString('35', $this->note($provider->owner, 'Your commission is due')->first()->body);

        // The next job goes to a NEW cycle: a due cycle is never added to
        $this->job($provider);
        $this->assertSame(2, CommissionSettlementCycle::where('provider_profile_id', $provider->id)->count());
        $this->assertSame(3, $this->state($cycle)['jobs']);
        $this->assertSame(1, $this->state($this->cycleOf($provider, 1))['jobs']);
        $this->assertSame(1, $this->note($provider->owner, 'Your commission is due')->count());
        // Every assessment is in exactly one cycle
        $this->assertSame(CommissionAssessment::count(), DB::table('commission_cycle_obligations')->count());
        $this->assertSame(CommissionAssessment::count(), DB::table('commission_cycle_obligations')->distinct()->count('assessment_id'));
    }

    public function test_the_seven_day_deadline_runs_from_the_first_obligation_and_a_payment_never_resets_it()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->job($provider);
        $cycle = $this->cycleOf($provider);
        $first = $this->state($cycle);
        $this->assertSame('seven_days', $first['trigger']);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, strtotime($first['due_at']), 5);

        $this->travel(3)->days();
        $this->job($provider);   // joins the same open cycle; the clock does not restart
        $this->assertSame($first['due_at'], $this->state($cycle)['due_at']);
        $this->assertSame(2, $this->state($cycle)['jobs']);

        // A verified part payment changes the balance and the status, never the deadline
        $payment = $this->report($provider, $cycle, ['amount' => 5000, 'reference' => 'MP111111'])->assertCreated()->json('data.id');
        $this->verify($this->userWithRoles(['finance']), $payment, 5000)->assertOk();
        $after = $this->state($cycle);
        $this->assertSame([$first['due_at'], 'partially_paid', 15000], [$after['due_at'], $after['status'], $after['outstanding_ugx']]);

        $this->travel(3)->days();
        $this->assertSame('partially_paid', $this->state($cycle)['status']);   // day 6: still inside the deadline
        $this->travel(2)->days();                                              // day 8: past it
        $late = $this->state($cycle);
        $this->assertSame([$first['due_at'], 'overdue', 15000], [$late['due_at'], $late['status'], $late['outstanding_ugx']]);

        // An overdue cycle is closed to new obligations
        $this->job($provider);
        $this->assertSame(2, CommissionSettlementCycle::where('provider_profile_id', $provider->id)->count());
    }

    public function test_a_cycle_that_became_due_early_has_no_invented_deadline_until_one_is_approved()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);
        $this->travel(3)->days();
        $this->assertSame('due', $this->state($cycle)['status']);   // due, but no penalty, no restriction, and not overdue
        $this->travel(5)->days();                                   // past the seven-day outer limit
        $this->assertSame('overdue', $this->state($cycle)['status']);

        // With an approved window the cycle becomes overdue that many hours after the third job
        $other = $this->provider();
        $this->setting('finance.settlement_three_job_window_hours', 24);
        $this->threeJobs($other);
        $oc = $this->cycleOf($other);
        $this->assertSame('due', $this->state($oc)['status']);
        $this->travel(25)->hours();
        $this->assertSame('overdue', $this->state($oc)['status']);
    }

    public function test_a_reversed_obligation_stops_counting_towards_the_trigger_and_the_balance()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        [$a] = $this->job($provider);
        $this->job($provider);
        [$c] = $this->job($provider);
        $cycle = $this->cycleOf($provider);
        $this->assertSame('due', $this->state($cycle)['status']);

        ServiceRequest::whereKey($c)->update(['status' => 'cancelled']);
        $this->artisan('workmate:commission-assess')->assertExitCode(0);
        $s = $this->state($cycle);
        $this->assertSame([2, 'open', 20000], [$s['jobs'], $s['status'], $s['payable_ugx']]);   // two jobs, not three; the reversed one owes nothing
        $this->assertSame(20000, $this->owed($provider));
        $this->assertSame(3, CommissionAssessment::count());                                   // the assessment itself is still there
    }

    /* ---------------- Reporting and verifying ---------------- */

    public function test_a_reported_payment_credits_nothing_until_finance_verifies_it_and_the_deadline_stays()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);
        $due = $this->state($cycle)['due_at'];

        $payment = $this->report($provider, $cycle, ['amount' => 10000, 'reference' => 'MP123456', 'note' => 'Paid this morning'])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame(30000, $this->owed($provider));                     // pending: nothing reduced
        $this->assertSame(0, CommissionLedgerEntry::where('entry_type', 'settlement')->count());
        $this->signIn($provider->owner);
        $view = $this->getJson('/api/v1/me/commission')->json('data');
        $this->assertSame([30000, 0, 10000], [$view['outstanding_ugx'], $view['settled_ugx'], $view['settlement']['pending_ugx']]);   // shown as waiting, not as settled
        $this->assertSame('pending', $view['settlement']['payments'][0]['status']);

        $this->verify($this->userWithRoles(['finance']), $payment, 10000, ['note' => 'Seen in the MTN merchant statement'])->assertOk()
            ->assertJsonPath('data.status', 'verified')->assertJsonPath('data.credited_ugx', 10000)->assertJsonPath('data.excess_ugx', 0);
        $this->assertSame(20000, $this->owed($provider));
        $entry = CommissionLedgerEntry::where('entry_type', 'settlement')->first();
        $this->assertSame([-10000, $cycle, $payment, null, 'settle:'.$payment], [$entry->amount_ugx, $entry->cycle_id, $entry->commission_payment_id, $entry->assessment_id, $entry->reference]);
        $s = $this->state($cycle);
        $this->assertSame([$due, 'partially_paid', 20000, 10000], [$s['due_at'], $s['status'], $s['outstanding_ugx'], $s['credits_ugx']]);
        $this->assertTrue(AuditLog::where('action', 'commission.payment_verified')->exists());

        // The rest, in full: the cycle is settled and cannot be paid or charged again
        $second = $this->report($provider, $cycle, ['amount' => 20000, 'reference' => 'MP654321'])->assertCreated()->json('data.id');
        $this->verify($this->userWithRoles(['finance']), $second, 20000)->assertOk();
        $this->assertSame([0, 'settled'], [$this->state($cycle)['outstanding_ugx'], $this->state($cycle)['status']]);
        $this->assertSame(0, $this->owed($provider));
        $this->report($provider, $cycle, ['reference' => 'MP777777'])->assertStatus(409);
        $this->job($provider);
        $this->assertSame('settled', $this->state($cycle)['status']);   // never reopened
        $this->assertSame(2, CommissionSettlementCycle::where('provider_profile_id', $provider->id)->count());
    }

    public function test_rejected_and_unverified_payments_credit_nothing_and_a_rejected_reference_can_be_used_again()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);
        $finance = $this->userWithRoles(['finance']);

        $payment = $this->report($provider, $cycle, ['reference' => 'AB-12345'])->assertCreated()->json('data.id');
        // The same reference again while it is pending or verified (any spelling): refused
        $this->report($provider, $cycle, ['reference' => 'ab-12345'])->assertStatus(409)->assertJsonPath('code', 'duplicate_reference');
        $this->signIn($finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$payment}/reject", [])->assertStatus(422);
        $this->postJson("/api/v1/admin/settlements/payments/{$payment}/reject", ['reason' => 'no'])->assertStatus(422);
        $this->postJson("/api/v1/admin/settlements/payments/{$payment}/reject", ['reason' => 'This reference is not in our statement.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(30000, $this->owed($provider));
        $this->assertSame(0, CommissionLedgerEntry::where('entry_type', 'settlement')->count());
        // The provider reads why, and can report the real reference
        $this->signIn($provider->owner);
        $this->assertSame('This reference is not in our statement.', $this->getJson('/api/v1/me/commission')->json('data.settlement.payments.0.decision_note'));
        $again = $this->report($provider, $cycle, ['reference' => 'AB-12345'])->assertCreated()->json('data.id');
        $this->assertNotSame($payment, $again);
        // A decided payment is decided once
        $this->signIn($finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$payment}/reject", ['reason' => 'Rejecting it twice, by someone else.'])->assertOk();   // same admin: a retry
        $this->verify($finance, $payment, 10000)->assertStatus(409);
        $this->assertSame(0, CommissionLedgerEntry::where('entry_type', 'settlement')->count());
    }

    public function test_verification_needs_the_receipt_confirmed_and_creates_one_credit_however_often_it_is_asked()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);
        $payment = $this->report($provider, $cycle, ['amount' => 10000])->assertCreated()->json('data.id');
        $finance = $this->userWithRoles(['finance']);

        $this->signIn($finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$payment}/verify", ['amount_ugx' => 10000])->assertStatus(422);
        $this->postJson("/api/v1/admin/settlements/payments/{$payment}/verify", ['amount_ugx' => 10000, 'receipt_confirmed' => false])->assertStatus(422);
        $this->assertSame(0, CommissionLedgerEntry::where('entry_type', 'settlement')->count());
        // A different amount than reported needs a reason
        $this->verify($finance, $payment, 9000)->assertStatus(422)->assertJsonPath('code', 'note_required');

        $this->verify($finance, $payment, 10000)->assertOk();
        $this->verify($finance, $payment, 10000)->assertOk();     // the same decision again: nothing new
        $this->verify($this->userWithRoles(['finance']), $payment, 10000)->assertStatus(409);   // someone else, too late
        $this->assertSame(1, CommissionLedgerEntry::where('entry_type', 'settlement')->count());
        $this->assertSame(20000, $this->owed($provider));
        $this->assertSame(1, $this->note($provider->owner, 'Commission payment verified')->count());
        $this->assertSame(1, AuditLog::where('action', 'commission.payment_verified')->count());
    }

    public function test_the_database_itself_refuses_a_second_credit_for_one_payment()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $payment = $this->report($provider, $this->cycleOf($provider), ['amount' => 10000])->assertCreated()->json('data.id');
        $this->verify($this->userWithRoles(['finance']), $payment, 10000)->assertOk();
        $entry = CommissionLedgerEntry::where('entry_type', 'settlement')->first();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('commission_ledger_entries')->insert([
            'assessment_id' => null, 'cycle_id' => $entry->cycle_id, 'commission_payment_id' => $payment, 'provider_profile_id' => $provider->id,
            'entry_type' => 'settlement', 'amount_ugx' => -10000, 'reference' => 'settle:'.$payment, 'created_at' => now(),
        ]);
    }

    public function test_an_overpayment_is_credited_only_up_to_what_was_owed_and_the_rest_becomes_an_exception()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $this->job($provider);   // a second cycle that must not be touched
        $cycle = $this->cycleOf($provider);
        $other = $this->cycleOf($provider, 1);
        $payment = $this->report($provider, $cycle, ['amount' => 50000])->assertCreated()->json('data.id');

        $this->verify($this->userWithRoles(['finance']), $payment, 50000)->assertOk()->assertJsonPath('data.credited_ugx', 30000)->assertJsonPath('data.excess_ugx', 20000);
        $this->assertSame('settled', $this->state($cycle)['status']);
        $this->assertSame(10000, $this->state($other)['outstanding_ugx']);   // not silently allocated to another cycle
        $this->assertSame(10000, $this->owed($provider));                     // never negative
        $exception = CommissionException::first();
        $this->assertSame(['overpayment', 20000, 'open', $payment], [$exception->kind, $exception->amount_ugx, $exception->status, $exception->commission_payment_id]);
        $this->assertTrue(AuditLog::where('action', 'commission.exception_opened')->exists());

        $this->signIn($this->userWithRoles(['finance']));
        $list = $this->getJson('/api/v1/admin/settlements/exceptions')->assertOk()->json('data');
        $this->assertSame([['overpayment', 20000, 'open']], array_map(function ($e) {
            return [$e['kind'], $e['amount_ugx'], $e['status']];
        }, $list));
    }

    public function test_reversing_an_obligation_after_it_was_paid_flags_the_excess_and_keeps_every_record()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        [$id] = $this->job($provider);
        $cycle = $this->cycleOf($provider);
        $payment = $this->report($provider, $cycle, ['amount' => 10000])->assertCreated()->json('data.id');
        $this->verify($this->userWithRoles(['finance']), $payment, 10000)->assertOk();
        $this->assertSame(0, $this->owed($provider));

        ServiceRequest::whereKey($id)->update(['status' => 'cancelled']);
        $this->artisan('workmate:commission-assess')->assertExitCode(0);

        $this->assertSame(-10000, $this->owed($provider));     // the credit stays; nothing is refunded or deleted
        $this->assertSame([['assessment', 10000], ['reversal', -10000]], CommissionAssessment::first()->entries->map(function ($e) {
            return [$e->entry_type, $e->amount_ugx];
        })->all());
        $this->assertSame(1, CommissionLedgerEntry::where('entry_type', 'settlement')->count());
        $exception = CommissionException::where('kind', 'excess_credit')->first();
        $this->assertSame([10000, 'open'], [$exception->amount_ugx, $exception->status]);
        $this->artisan('workmate:commission-assess')->assertExitCode(0);
        $this->assertSame(1, CommissionException::where('kind', 'excess_credit')->count());   // once
        // Not shown to the provider as a debt: nothing is owed
        $this->signIn($provider->owner);
        $this->assertSame(-10000, $this->getJson('/api/v1/me/commission')->json('data.outstanding_ugx'));
    }

    public function test_nobody_can_decide_on_a_payment_from_their_own_business_and_permissions_are_enforced()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);
        $payment = $this->report($provider, $cycle, ['amount' => 10000])->assertCreated()->json('data.id');

        // A finance administrator who also belongs to the paying business
        $insider = $this->userWithRoles(['finance']);
        $provider->members()->create(['user_id' => $insider->id, 'role' => ProviderMember::ROLE_MEMBER]);
        $this->verify($insider, $payment, 10000)->assertStatus(403)->assertJsonPath('code', 'own_payment');
        $this->signIn($insider);
        $this->postJson("/api/v1/admin/settlements/payments/{$payment}/reject", ['reason' => 'Rejecting my own business.'])->assertStatus(403);
        // The provider itself is no administrator
        $this->verify($provider->owner, $payment, 10000)->assertForbidden();
        $this->verify($this->userWithRoles(['support']), $payment, 10000)->assertForbidden();
        $this->signIn($this->userWithRoles(['support']));
        $this->getJson('/api/v1/admin/settlements/cycles')->assertForbidden();
        $this->getJson('/api/v1/admin/settlements/payments')->assertForbidden();
        $this->getJson('/api/v1/admin/settlements/exceptions')->assertForbidden();
        $this->assertSame(0, CommissionLedgerEntry::where('entry_type', 'settlement')->count());
        $this->assertSame(CommissionPaymentReport::PENDING, CommissionPaymentReport::find($payment)->status);

        foreach ([$this->userWithRoles(['finance']), $this->root] as $ok) {
            $this->signIn($ok);
            $this->getJson('/api/v1/admin/settlements/cycles')->assertOk();
        }
    }

    public function test_reports_are_checked_field_by_field_and_cash_needs_approval()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);

        $this->report($provider, $cycle, ['amount' => 50])->assertStatus(422);
        $this->report($provider, $cycle, ['amount' => 'lots'])->assertStatus(422);
        $this->report($provider, $cycle, ['channel' => 'paypal'])->assertStatus(422);
        $this->report($provider, $cycle, ['reference' => ''])->assertStatus(422)->assertJsonPath('code', 'reference_required');
        $this->report($provider, $cycle, ['reference' => 'PIN 1234 <script>'])->assertStatus(422);
        $this->report($provider, $cycle, ['paid_on' => now()->addDays(2)->toDateString()])->assertStatus(422);
        $this->report($provider, $cycle, ['channel' => 'cash', 'reference' => null])->assertStatus(422)->assertJsonPath('code', 'channel_invalid');   // no approved cash process
        $this->assertSame(0, CommissionPaymentReport::count());

        $this->setting('finance.commission_accepts_cash', true);
        $this->report($provider, $cycle, ['channel' => 'cash', 'reference' => null, 'paid_on' => now()->toDateString()])->assertCreated();
        // Only so many wait at once
        for ($i = 0; $i < 4; $i++) {
            $this->report($provider, $cycle)->assertCreated();
        }
        $this->report($provider, $cycle)->assertStatus(429)->assertJsonPath('code', 'too_many_pending');
        $this->assertSame(5, CommissionPaymentReport::count());
    }

    public function test_a_retry_with_the_same_token_reports_once_and_tells_finance_once()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);
        $finance = $this->userWithRoles(['finance']);

        $one = $this->report($provider, $cycle, ['client_token' => 'tok-1', 'reference' => 'RT-1'])->assertCreated()->json('data.id');
        $two = $this->report($provider, $cycle, ['client_token' => 'tok-1', 'reference' => 'RT-1'])->assertCreated()->json('data.id');
        $this->assertSame($one, $two);
        $this->assertSame(1, CommissionPaymentReport::count());
        foreach ([$finance, $this->root] as $admin) {
            $this->assertSame(1, $this->note($admin, 'A commission payment needs checking')->count());
        }
        $this->assertSame(0, $this->note($provider->owner, 'A commission payment needs checking')->count());
        $this->assertSame("/admin/finance/settlements?payment={$one}", $this->note($finance, 'A commission payment needs checking')->first()->url);
    }

    public function test_notifications_carry_no_amounts_references_or_reasons()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);
        $a = $this->report($provider, $cycle, ['amount' => 12345, 'reference' => 'SECRETREF99'])->assertCreated()->json('data.id');
        $b = $this->report($provider, $cycle, ['amount' => 6789, 'reference' => 'OTHERREF55'])->assertCreated()->json('data.id');
        $finance = $this->userWithRoles(['finance']);
        $this->verify($finance, $a, 12345)->assertOk();
        $this->signIn($finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$b}/reject", ['reason' => 'Private reason about SECRETREF99.'])->assertOk();

        $notes = UserNotification::whereIn('user_id', [$provider->owner_id, $finance->id, $this->root->id])->where('tag', 'like', 'commission-%')->get();
        $this->assertGreaterThanOrEqual(5, $notes->count());
        foreach ($notes as $note) {
            foreach (['12345', '12,345', '6789', 'SECRETREF', 'OTHERREF', 'Private reason', 'UGX'] as $leak) {
                $this->assertStringNotContainsStringIgnoringCase($leak, $note->title.' '.$note->body);
            }
        }
        $this->assertSame(1, $this->note($provider->owner, 'Commission payment not accepted')->count());
    }

    /* ---------------- Evidence ---------------- */

    public function test_evidence_is_private_checked_and_audited()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);
        $this->signIn($provider->owner);
        $this->post('/api/v1/me/commission/payments', ['cycle_id' => $cycle, 'amount' => 10000, 'channel' => 'mtn_momo', 'reference' => 'EV-1', 'evidence' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/v1/me/commission/payments', ['cycle_id' => $cycle, 'amount' => 10000, 'channel' => 'mtn_momo', 'reference' => 'EV-1', 'evidence' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, CommissionPaymentReport::count());

        $response = $this->post('/api/v1/me/commission/payments', ['cycle_id' => $cycle, 'amount' => 10000, 'channel' => 'mtn_momo', 'reference' => 'EV-1', 'evidence' => UploadedFile::fake()->image('receipt.png', 40, 40)], ['Accept' => 'application/json'])->assertCreated();
        $id = $response->json('data.id');
        $this->assertSame('image/jpeg', $response->json('data.evidence.mime'));
        $this->assertStringNotContainsString('commission-evidence', json_encode($response->json()));   // no storage path leaves the server
        $files = Storage::disk('local')->allFiles('commission-evidence');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('#^commission-evidence/[0-9a-f-]{36}\.jpg$#', $files[0]);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->getJson("/api/v1/me/commission/payments/{$id}/evidence")->assertOk();
        $this->signIn($this->provider()->owner);
        $this->getJson("/api/v1/me/commission/payments/{$id}/evidence")->assertStatus(404);
        $this->signIn(User::factory()->create());
        $this->getJson("/api/v1/me/commission/payments/{$id}/evidence")->assertStatus(404);
        $this->signIn($this->userWithRoles(['support']));
        $this->getJson("/api/v1/admin/settlements/payments/{$id}/evidence")->assertForbidden();
        $this->signIn($this->userWithRoles(['finance']));
        $this->getJson("/api/v1/admin/settlements/payments/{$id}/evidence")->assertOk();
        $this->assertTrue(AuditLog::where('action', 'commission.payment_evidence_viewed')->exists());
    }

    /* ---------------- Who sees what ---------------- */

    public function test_a_provider_sees_and_reports_only_their_own_settlement()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $other = $this->provider();
        $this->threeJobs($other);
        $mine = $this->cycleOf($provider);
        $theirs = $this->cycleOf($other);
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        $provider->members()->create(['user_id' => $member->id, 'role' => ProviderMember::ROLE_MEMBER]);

        // Another business's cycle: "not found", whether it is reported on or looked at
        $this->report($provider, $theirs)->assertStatus(404);
        $this->assertSame(0, CommissionPaymentReport::count());
        // A manager has no access to the business's finances by default (Phase B9); the owner can give it
        $this->signIn($manager);
        $this->assertNull($this->getJson('/api/v1/me/commission')->assertOk()->json('data'));
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true])->assertOk();

        foreach ([$provider->owner, $manager] as $allowed) {
            $this->signIn($allowed);
            $data = $this->getJson('/api/v1/me/commission')->assertOk()->json('data');
            $this->assertSame([$mine], array_column($data['settlement']['cycles'], 'id'));
            $this->assertSame(30000, $data['settlement']['current']['outstanding_ugx']);
        }
        $this->signIn($manager);
        $this->postJson('/api/v1/me/commission/payments', ['cycle_id' => $mine, 'amount' => 10000, 'channel' => 'airtel_money', 'reference' => 'MGR-1'])->assertCreated();
        foreach ([$member, User::factory()->create()] as $nobody) {
            $this->signIn($nobody);
            $this->assertNull($this->getJson('/api/v1/me/commission')->json('data'));
            $this->postJson('/api/v1/me/commission/payments', ['cycle_id' => $mine, 'amount' => 10000, 'channel' => 'mtn_momo', 'reference' => 'NOPE-1'])->assertStatus(404);
        }
        $this->assertSame(1, CommissionPaymentReport::count());
        $this->signIn($other->owner);
        $this->assertSame([], $this->getJson('/api/v1/me/commission')->json('data.settlement.payments'));
    }

    public function test_instructions_are_shown_only_when_approved_and_exactly_as_written()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->job($provider);
        $this->signIn($provider->owner);
        $this->assertNull($this->getJson('/api/v1/me/commission')->json('data.settlement.instructions'));
        $this->assertSame(['mtn_momo', 'airtel_money', 'bank_transfer'], $this->getJson('/api/v1/me/commission')->json('data.settlement.channels'));
        $this->setting('finance.commission_payment_instructions', 'Pay to merchant code 123456 (WorkMate).');
        $this->assertSame('Pay to merchant code 123456 (WorkMate).', $this->getJson('/api/v1/me/commission')->json('data.settlement.instructions'));
    }

    /* ---------------- Reconciliation and catch-up ---------------- */

    public function test_the_ledger_always_reconciles_with_the_cycles_and_is_never_rewritten()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $this->job($provider);
        $c1 = $this->cycleOf($provider);
        $c2 = $this->cycleOf($provider, 1);
        $p1 = $this->report($provider, $c1, ['amount' => 12000])->assertCreated()->json('data.id');
        $this->verify($this->userWithRoles(['finance']), $p1, 12000)->assertOk();
        $p2 = $this->report($provider, $c2, ['amount' => 4000])->assertCreated()->json('data.id');
        $this->verify($this->userWithRoles(['finance']), $p2, 4000)->assertOk();

        $sumOfCycles = $this->state($c1)['outstanding_ugx'] + $this->state($c2)['outstanding_ugx'];
        $this->assertSame($this->owed($provider), $sumOfCycles);
        $this->assertSame([18000, 6000], [$this->state($c1)['outstanding_ugx'], $this->state($c2)['outstanding_ugx']]);
        $this->signIn($provider->owner);
        $data = $this->getJson('/api/v1/me/commission')->json('data');
        $this->assertSame([40000, 16000, 24000], [$data['assessed_ugx'], $data['settled_ugx'], $data['outstanding_ugx']]);

        foreach ([
            function () {
                CommissionLedgerEntry::where('entry_type', 'settlement')->first()->forceFill(['amount_ugx' => -1])->save();
            },
            function () {
                CommissionLedgerEntry::where('entry_type', 'settlement')->first()->delete();
            },
            function () {
                CommissionSettlementCycle::first()->delete();
            },
            function () use ($p1) {
                CommissionPaymentReport::find($p1)->forceFill(['amount_ugx' => 1])->save();
            },
            function () use ($p1) {
                CommissionPaymentReport::find($p1)->forceFill(['status' => 'pending'])->save();
            },
            function () use ($p1) {
                CommissionPaymentReport::find($p1)->delete();
            },
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("Attempt {$i} changed a protected record");
            } catch (LogicException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_catch_up_puts_earlier_obligations_in_cycles_in_order_and_sends_each_reminder_once()
    {
        $this->activate(false);
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $this->job($provider);
        $this->assertSame(0, CommissionSettlementCycle::count());

        $this->setting('finance.commission_settlement_enabled', true);
        $this->artisan('workmate:commission-settlements')->assertExitCode(0);
        $this->assertSame([2, 4], [CommissionSettlementCycle::count(), DB::table('commission_cycle_obligations')->count()]);
        $this->assertSame([3, 1], [$this->state($this->cycleOf($provider))['jobs'], $this->state($this->cycleOf($provider, 1))['jobs']]);
        $this->artisan('workmate:commission-settlements')->assertExitCode(0);
        $this->assertSame([2, 4], [CommissionSettlementCycle::count(), DB::table('commission_cycle_obligations')->count()]);
        $this->assertSame(1, $this->note($provider->owner, 'Your commission is due')->count());

        // A day before the second cycle's deadline: one reminder, then none; past it: one overdue notice, then none
        $this->travel(6)->days();
        $this->travel(2)->hours();
        $this->artisan('workmate:commission-settlements')->assertExitCode(0);
        $this->artisan('workmate:commission-settlements')->assertExitCode(0);
        $this->assertSame(2, $this->note($provider->owner, 'Commission due soon')->count());   // each open cycle with a deadline within a day, once
        $this->travel(3)->days();
        $this->artisan('workmate:commission-settlements')->assertExitCode(0);
        $this->artisan('workmate:commission-settlements')->assertExitCode(0);
        $this->assertSame(2, $this->note($provider->owner, 'Commission overdue')->count());
        // Nothing here is a penalty or a restriction
        $this->assertSame(0, DB::table('users')->where('status', '!=', 'active')->count());
    }

    public function test_the_catch_up_is_scheduled_and_the_permissions_have_the_right_holders()
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(function ($e) {
            return $e->command;
        })->implode("\n");
        $this->assertStringContainsString('workmate:commission-settlements', $events);

        $holders = function (string $key) {
            return DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')->join('roles as r', 'r.id', '=', 'pr.role_id')
                ->where('p.key', $key)->orderBy('r.key')->pluck('r.key')->all();
        };
        $this->assertSame(['finance', 'super_admin'], $holders('settlements.view'));
        $this->assertSame(['finance', 'super_admin'], $holders('settlements.verify'));

        $migration = require_once base_path('database/migrations/2026_12_01_000076_add_commission_settlement_permissions.php');
        $migration = is_object($migration) ? $migration : new \AddCommissionSettlementPermissions();
        $before = [DB::table('permissions')->count(), DB::table('permission_role')->count()];
        $migration->up();
        $migration->up();
        $this->assertSame($before, [DB::table('permissions')->count(), DB::table('permission_role')->count()]);
    }

    public function test_admins_can_filter_cycles_and_open_one_with_its_ledger_and_payments()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $quiet = $this->provider();
        $this->job($quiet);
        $cycle = $this->cycleOf($provider);
        $payment = $this->report($provider, $cycle, ['amount' => 10000])->assertCreated()->json('data.id');
        $this->verify($this->userWithRoles(['finance']), $payment, 10000)->assertOk();

        $this->signIn($this->userWithRoles(['finance']));
        $all = $this->getJson('/api/v1/admin/settlements/cycles')->assertOk()->json('data');
        $this->assertSame(2, $all['total']);
        $this->assertEquals(['partially_paid' => 1, 'open' => 1], $all['counts']);
        $this->assertCount(1, $this->getJson('/api/v1/admin/settlements/cycles?status=partially_paid')->json('data.items'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/settlements/cycles?provider='.$quiet->id)->json('data.items'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/settlements/cycles?from='.now()->addDay()->toDateString())->json('data.items'));
        $this->getJson('/api/v1/admin/settlements/cycles?status=bogus')->assertStatus(422);

        $detail = $this->getJson("/api/v1/admin/settlements/cycles/{$cycle}")->assertOk()->json('data');
        $this->assertSame([3, 20000, 'partially_paid'], [count($detail['obligations']), $detail['outstanding_ugx'], $detail['status']]);
        $this->assertSame(['assessment', 'assessment', 'assessment', 'settlement'], array_column($detail['ledger'], 'type'));
        $this->assertSame('verified', $detail['payments'][0]['status']);
        $this->getJson('/api/v1/admin/settlements/cycles/99999')->assertNotFound();
        $pending = $this->getJson('/api/v1/admin/settlements/payments')->json('data');
        $this->assertSame([], $pending['items']);
        $this->assertCount(1, $this->getJson('/api/v1/admin/settlements/payments?status=verified')->json('data.items'));
    }
    public function test_the_database_guarantees_every_settlement_credit_has_a_provider_a_cycle_a_payment_and_its_own_reference()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $cycle = $this->cycleOf($provider);
        $payment = $this->report($provider, $cycle, ['amount' => 10000])->assertCreated()->json('data.id');
        $this->verify($this->userWithRoles(['finance']), $payment, 10000)->assertOk();

        // The credit that was written has all of it
        $credit = CommissionLedgerEntry::where('entry_type', 'settlement')->first();
        $this->assertSame([$provider->id, $cycle, $payment, null], [$credit->provider_profile_id, $credit->cycle_id, $credit->commission_payment_id, $credit->assessment_id]);
        $this->assertSame(1, CommissionLedgerEntry::where('reference', $credit->reference)->count());
        // B6's lines still belong to their assessment (the foreign key survived making the column nullable)
        $assessment = CommissionLedgerEntry::where('entry_type', 'assessment')->first();
        $this->assertNotNull($assessment->assessment_id);
        $this->assertNull($assessment->cycle_id);

        $line = function (array $over) use ($provider, $cycle, $payment) {
            return $over + [
                'assessment_id' => null, 'cycle_id' => $cycle, 'commission_payment_id' => $payment, 'provider_profile_id' => $provider->id,
                'entry_type' => 'settlement', 'amount_ugx' => -100, 'reference' => 'bad:'.random_int(1, 999999), 'created_at' => now(),
            ];
        };
        $assessmentId = CommissionAssessment::value('id');
        $refused = [
            'no cycle' => $line(['cycle_id' => null]),
            'no payment' => $line(['commission_payment_id' => null]),
            'a cycle that does not exist' => $line(['cycle_id' => 999999]),
            'a payment that does not exist' => $line(['commission_payment_id' => 999999]),
            'a provider that does not exist' => $line(['provider_profile_id' => 999999]),
            'a credit that adds' => $line(['amount_ugx' => 100]),
            'a credit that is zero' => $line(['amount_ugx' => 0]),
            'a credit tied to an assessment' => $line(['assessment_id' => $assessmentId]),
            'an assessment line with no assessment' => $line(['entry_type' => 'assessment', 'cycle_id' => null, 'commission_payment_id' => null, 'amount_ugx' => 100]),
            'an assessment line tied to a cycle' => $line(['entry_type' => 'assessment', 'assessment_id' => $assessmentId, 'amount_ugx' => 100]),
            'an unknown kind' => $line(['entry_type' => 'refund']),
            'the same reference twice' => $line(['reference' => $credit->reference]),
        ];
        foreach ($refused as $what => $row) {
            try {
                DB::table('commission_ledger_entries')->insert($row);
                $this->fail("The database accepted: {$what}");
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(CommissionLedgerEntry::count(), DB::table('commission_ledger_entries')->count());   // nothing slipped in
    }

    public function test_a_trigger_that_was_reached_stays_on_record_after_a_reversal_takes_the_live_count_back_down()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        [$a] = $this->job($provider);
        $this->job($provider);
        [$c] = $this->job($provider);
        $cycle = $this->cycleOf($provider);
        $reached = $this->state($cycle);
        $this->assertSame(['due', 'three_jobs', 3], [$reached['status'], $reached['trigger'], $reached['jobs']]);
        $this->assertCount(1, $reached['history']);
        $this->assertSame(['three_jobs_reached', 3, true], [$reached['history'][0]['event'], $reached['history'][0]['jobs'], $reached['history'][0]['in_effect']]);
        $when = $reached['history'][0]['at'];
        $this->assertSame(1, $this->note($provider->owner, 'Your commission is due')->count());

        // A reversal: the LIVE state follows the money (two jobs, 20,000, open again)...
        ServiceRequest::whereKey($c)->update(['status' => 'cancelled']);
        $this->artisan('workmate:commission-assess')->assertExitCode(0);
        $now = $this->state($cycle);
        $this->assertSame(['open', 2, 20000], [$now['status'], $now['jobs'], $now['payable_ugx']]);
        // ...but the record that the trigger was reached, and when, is still there, marked as no longer in effect
        $this->assertCount(1, $now['history']);
        $this->assertSame([$when, 'three_jobs_reached', false], [$now['history'][0]['at'], $now['history'][0]['event'], $now['history'][0]['in_effect']]);
        $this->assertSame(1, DB::table('commission_cycle_events')->count());
        $this->assertSame(1, $this->note($provider->owner, 'Your commission is due')->count());   // not announced again
        $this->assertSame(3, CommissionAssessment::count());                                      // every assessment and ledger line is still there
        $this->assertSame(4, CommissionLedgerEntry::count());                                     // three assessments and one reversal

        // The provider and finance can both read it
        $this->signIn($provider->owner);
        $this->assertSame('three_jobs_reached', $this->getJson('/api/v1/me/commission')->json('data.settlement.cycles.0.history.0.event'));
        $this->signIn($this->userWithRoles(['finance']));
        $detail = $this->getJson("/api/v1/admin/settlements/cycles/{$cycle}")->assertOk()->json('data');
        $this->assertSame([false, $when], [$detail['history'][0]['in_effect'], $detail['history'][0]['at']]);

        // A new job joins the open cycle, brings it back to three, and the single event is not duplicated
        $this->job($provider);
        $this->assertSame(['due', 3], [$this->state($cycle)['status'], $this->state($cycle)['jobs']]);
        $this->assertSame(1, DB::table('commission_cycle_events')->count());
        $this->assertTrue($this->state($cycle)['history'][0]['in_effect']);
    }

    public function test_the_catch_up_records_a_trigger_for_cycles_built_before_settlement_was_switched_on()
    {
        $this->activate(false);
        $this->rule();
        $provider = $this->provider();
        $this->threeJobs($provider);
        $this->assertSame(0, DB::table('commission_cycle_events')->count());
        $this->setting('finance.commission_settlement_enabled', true);
        $this->artisan('workmate:commission-settlements')->assertExitCode(0);
        $this->assertSame(1, DB::table('commission_cycle_events')->count());
        $this->artisan('workmate:commission-settlements')->assertExitCode(0);
        $this->assertSame(1, DB::table('commission_cycle_events')->count());
    }
}
