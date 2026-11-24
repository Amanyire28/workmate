<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CommissionAssessment;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionShadowSnapshot;
use App\Models\JobPaymentReport;
use App\Models\PaymentDispute;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\CommissionAssessments;
use App\Services\CommissionShadow;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase B6: commission obligations. A customer-confirmed payment's estimate (B4) becomes a recorded obligation in an append-only
 * ledger, once, and only when the obligation switch is on and the customer confirmed the payment after the chosen start. Nothing is
 * collected, nothing is invented, history is never rewritten.
 */
class CommissionObligationTest extends ApiTestCase
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

    /** Pricing, estimates and obligations on, starting a minute ago (so payments confirmed from now count) */
    private function activate(bool $obligations = true, ?string $from = null): void
    {
        $this->setting('finance.pricing_enabled', true);
        $this->setting('finance.commission_shadow_enabled', true);
        $this->setting('finance.commission_assessment_enabled', $obligations);
        $this->setting('finance.commission_assessment_from', $from ?: now()->subMinute()->toIso8601String());
    }

    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Services '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function priced(?ProviderProfile $provider = null, int $price = 1000000): array
    {
        $provider = $provider ?: $this->provider();
        $customer = User::factory()->create();
        $this->signIn($customer);
        $id = $this->postJson("/api/v1/providers/{$provider->id}/requests", [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow morning',
        ])->assertCreated()->json('data.id');
        ServiceRequest::whereKey($id)->update(['status' => 'in_progress']);
        $this->signIn($provider->owner);
        $version = $this->postJson("/api/v1/me/received-requests/{$id}/agreements", ['amount' => $price, 'scope' => 'Replace the kitchen sink pipes and fit a new tap.'])->assertCreated()->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$version}/accept", ['amount' => $price])->assertOk();

        return [$provider, $customer, $id];
    }

    private function reported(ProviderProfile $provider, int $id, int $amount): int
    {
        $this->signIn($provider->owner);
        $reports = $this->postJson("/api/v1/me/received-requests/{$id}/payments", ['amount' => $amount, 'method' => 'cash'])->assertCreated()->json('data.payments.reports');

        return (int) end($reports)['id'];
    }

    private function confirmPayment(User $customer, int $id, int $payment, int $amount): void
    {
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => $amount])->assertOk();
    }

    private function confirmed(ProviderProfile $provider, User $customer, int $id, int $amount): int
    {
        $payment = $this->reported($provider, $id, $amount);
        $this->confirmPayment($customer, $id, $payment, $amount);

        return $payment;
    }

    private function rule(array $scope, int $bp): int
    {
        $body = array_merge($scope, ['rate_bp' => $bp, 'effective_from' => now()->subSeconds(30)->toIso8601String()]);   // (a minute's tolerance for a clock a little behind)
        $this->signIn($this->root);
        $preview = $this->postJson('/api/v1/admin/commission/policies/simulate', $body)->assertOk()->json('data');

        return $this->postJson('/api/v1/admin/commission/policies', $body + ['token' => $preview['token'], 'confirm_rate_bp' => $bp, 'reason' => 'Testing the rate'])->assertCreated()->json('data.id');
    }

    private function global(int $bp = 500): int
    {
        return $this->rule(['scope_type' => 'global'], $bp);
    }

    private function outstanding(int $provider): int
    {
        return (int) CommissionLedgerEntry::where('provider_profile_id', $provider)->sum('amount_ugx');
    }

    private function catchUp(): void
    {
        $this->artisan('workmate:commission-assess')->assertExitCode(0);
    }

    /* ---------------- Safe by default ---------------- */

    public function test_nothing_is_assessed_by_default_even_with_confirmed_payments_and_estimates()
    {
        $config = config('workmate.settings');
        $this->assertFalse($config['finance.commission_assessment_enabled']['default']);
        $this->assertNull($config['finance.commission_assessment_from']['default']);
        $this->assertFalse(app(CommissionAssessments::class)->enabled());
        $this->assertNull(app(CommissionAssessments::class)->startsAt());

        $this->activate(false);   // pricing and estimates on, obligations off
        $this->global();
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);
        $this->assertSame(5000, CommissionShadowSnapshot::where('payment_report_id', $payment)->value('commission_ugx'));   // the estimate exists
        $this->catchUp();
        $this->assertSame([0, 0], [CommissionAssessment::count(), CommissionLedgerEntry::count()]);   // but no obligation
    }

    public function test_without_a_start_moment_nothing_is_assessed_and_the_past_is_never_charged()
    {
        $this->activate(true, '');
        $this->setting('finance.commission_assessment_from', null);
        $this->global();
        [$provider, $customer, $id] = $this->priced();
        $before = $this->confirmed($provider, $customer, $id, 100000);
        $this->catchUp();
        $this->assertSame(0, CommissionAssessment::count());   // no start chosen

        // A start in the future: still nothing
        $this->setting('finance.commission_assessment_from', now()->addHour()->toIso8601String());
        $this->catchUp();
        $this->assertSame(0, CommissionAssessment::count());

        // A start after a payment was confirmed: that payment is history and never becomes an obligation
        $this->setting('finance.commission_assessment_from', now()->addSecond()->toIso8601String());
        $this->travel(5)->seconds();
        $this->catchUp();
        $this->assertSame(0, CommissionAssessment::where('payment_report_id', $before)->count());
        // ... while a payment confirmed after the start is
        [$p2, $c2, $id2] = $this->priced();
        $after = $this->confirmed($p2, $c2, $id2, 200000);
        $this->assertSame(10000, (int) CommissionAssessment::where('payment_report_id', $after)->value('commission_ugx'));
        $this->assertSame(1, CommissionAssessment::count());
        $this->assertSame(10000, $this->outstanding($p2->id));
        $this->assertSame(0, $this->outstanding($provider->id));
    }

    public function test_an_unreadable_start_moment_assesses_nothing()
    {
        $this->activate();
        $this->setting('finance.commission_assessment_from', 'not a date');
        $this->global();
        [$provider, $customer, $id] = $this->priced();
        $this->confirmed($provider, $customer, $id, 100000);
        $this->assertSame(0, CommissionAssessment::count());
    }

    public function test_pricing_options_off_means_no_new_obligations()
    {
        $this->activate();
        $this->global();
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->reported($provider, $id, 100000);
        $this->setting('finance.pricing_enabled', false);
        // (confirming needs pricing on; confirm first, then switch off and look again)
        $this->setting('finance.pricing_enabled', true);
        $this->confirmPayment($customer, $id, $payment, 100000);
        $this->assertSame(1, CommissionAssessment::count());
        // A second payment confirmed while pricing is on is assessed; with it off nothing new can even be confirmed
        $second = $this->reported($provider, $id, 100000);
        $this->setting('finance.pricing_enabled', false);
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$second}/confirm", ['amount' => 100000])->assertStatus(409);
        $this->catchUp();
        $this->assertSame(1, CommissionAssessment::count());
    }

    /* ---------------- Recording an obligation ---------------- */

    public function test_a_confirmed_payment_becomes_one_obligation_with_the_policy_and_rate_that_applied()
    {
        $this->activate();
        $policy = $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);

        $a = CommissionAssessment::where('payment_report_id', $payment)->first();
        $snapshot = CommissionShadowSnapshot::where('payment_report_id', $payment)->where('current_slot', 1)->first();
        $this->assertSame([100000, 500, 5000, $policy, 1, $provider->id, $id], [$a->amount_ugx, $a->rate_bp, $a->commission_ugx, $a->policy_id, $a->policy_version, $a->provider_profile_id, $a->service_request_id]);
        $this->assertSame($snapshot->id, $a->snapshot_id);
        $entries = CommissionLedgerEntry::where('assessment_id', $a->id)->get();
        $this->assertSame([['assessment', 5000]], $entries->map(function ($e) {
            return [$e->entry_type, $e->amount_ugx];
        })->all());
        $this->assertSame(5000, $this->outstanding($provider->id));
        $this->assertTrue(AuditLog::where('action', 'commission.obligation_assessed')->exists());

        // However often it is processed again: still one assessment, one entry
        $report = JobPaymentReport::find($payment);
        for ($i = 0; $i < 3; $i++) {
            app(CommissionShadow::class)->process($report);
            $this->catchUp();
        }
        $this->assertSame([1, 1], [CommissionAssessment::count(), CommissionLedgerEntry::count()]);
    }

    public function test_partial_payments_are_each_assessed_once_and_never_counted_twice()
    {
        $this->activate();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $first = $this->confirmed($provider, $customer, $id, 100000);
        $second = $this->confirmed($provider, $customer, $id, 33333);
        $this->assertSame([5000, 1667], [CommissionAssessment::where('payment_report_id', $first)->value('commission_ugx'), CommissionAssessment::where('payment_report_id', $second)->value('commission_ugx')]);
        $this->assertSame(6667, $this->outstanding($provider->id));
        $this->catchUp();
        $this->assertSame(2, CommissionAssessment::count());
        $this->assertSame(6667, $this->outstanding($provider->id));
    }

    public function test_payments_that_are_waiting_questioned_or_without_a_rule_create_no_obligation()
    {
        $this->activate();
        [$provider, $customer, $id] = $this->priced();
        // No rule in force yet: nothing is invented
        $noRule = $this->confirmed($provider, $customer, $id, 100000);
        $this->assertSame(0, CommissionAssessment::count());
        $this->assertSame('no_policy', CommissionShadowSnapshot::where('payment_report_id', $noRule)->value('exclusion_reason'));

        $this->global(500);
        // Waiting for the customer
        $waiting = $this->reported($provider, $id, 20000);
        // Questioned by the customer
        $questioned = $this->reported($provider, $id, 30000);
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$questioned}/dispute", ['note' => 'Not right'])->assertOk();
        $this->catchUp();
        $this->assertSame(0, CommissionAssessment::whereIn('payment_report_id', [$waiting, $questioned])->count());
        $this->assertSame(0, CommissionLedgerEntry::count());
    }

    public function test_the_rate_judged_is_kept_when_policies_change_later()
    {
        $this->activate();
        $policy = $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);

        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/commission/policies/{$policy}/retire", ['reason' => 'Ending the old rate'])->assertOk();
        $this->travel(2)->minutes();
        $this->setting('finance.commission_assessment_from', now()->subHour()->toIso8601String());
        $this->global(1000);
        $this->catchUp();

        $a = CommissionAssessment::where('payment_report_id', $payment)->first();
        $this->assertSame([500, 5000, 1], [$a->rate_bp, $a->commission_ugx, $a->policy_version]);
        $this->assertSame(5000, $this->outstanding($provider->id));
        // A later payment uses the new rate
        [$p2, $c2, $id2] = $this->priced();
        $later = $this->confirmed($p2, $c2, $id2, 100000);
        $this->assertSame(10000, (int) CommissionAssessment::where('payment_report_id', $later)->value('commission_ugx'));
    }

    public function test_a_zero_commission_is_no_obligation()
    {
        $this->activate();
        $this->global(5);   // 0.05%: on UGX 100 that rounds to nothing
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100);
        $this->assertSame(0, CommissionShadowSnapshot::where('payment_report_id', $payment)->value('commission_ugx'));
        $this->assertSame(0, CommissionAssessment::count());
    }

    /* ---------------- When a payment stops counting ---------------- */

    public function test_a_cancelled_job_reverses_the_obligation_once_and_keeps_the_original_entry()
    {
        $this->activate();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);
        $this->assertSame(5000, $this->outstanding($provider->id));
        $assessmentBefore = json_encode(CommissionAssessment::where('payment_report_id', $payment)->first()->toArray());

        ServiceRequest::whereKey($id)->update(['status' => 'cancelled']);
        $this->catchUp();
        $this->catchUp();

        $a = CommissionAssessment::where('payment_report_id', $payment)->first();
        $this->assertSame($assessmentBefore, json_encode($a->toArray()));   // the assessment itself is untouched
        $this->assertSame([['assessment', 5000], ['reversal', -5000]], $a->entries->map(function ($e) {
            return [$e->entry_type, $e->amount_ugx];
        })->all());
        $this->assertSame(0, $this->outstanding($provider->id));
        $this->assertSame('job_cancelled', $a->entries->last()->reason);
        $this->assertTrue(AuditLog::where('action', 'commission.obligation_reversed')->exists());
        // Nothing more happens however often it runs
        $this->catchUp();
        $this->assertSame(2, CommissionLedgerEntry::count());
    }

    public function test_a_payment_that_is_no_longer_confirmed_is_reversed()
    {
        $this->activate();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);
        JobPaymentReport::whereKey($payment)->update(['status' => 'disputed']);
        $this->catchUp();
        $this->assertSame(0, $this->outstanding($provider->id));
        $this->assertSame('payment_no_longer_confirmed', CommissionLedgerEntry::where('entry_type', 'reversal')->value('reason'));
    }

    /* ---------------- Disputes (B5) ---------------- */

    public function test_a_dispute_the_two_sides_settle_as_received_becomes_an_obligation_only_after_the_customer_confirms()
    {
        $this->activate();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->reported($provider, $id, 100000);
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/dispute", ['note' => 'Not right'])->assertOk();
        $case = PaymentDispute::where('payment_report_id', $payment)->value('id');
        $this->assertSame(0, CommissionAssessment::count());   // questioned: nothing owed

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$case}/proposal", ['outcome' => 'payment_received'])->assertOk();
        $this->assertSame(0, CommissionAssessment::count());   // one side's suggestion is not a confirmation
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$case}/proposal/accept", ['outcome' => 'payment_received'])->assertOk();

        $this->assertSame(5000, (int) CommissionAssessment::where('payment_report_id', $payment)->value('commission_ugx'));
        $this->assertSame(5000, $this->outstanding($provider->id));
    }

    public function test_administrator_decisions_never_create_an_obligation()
    {
        $this->activate();
        $this->global(500);
        foreach (['received', 'not_established', 'insufficient_evidence'] as $outcome) {
            [$provider, $customer, $id] = $this->priced();
            $payment = $this->reported($provider, $id, 100000);
            $this->signIn($customer);
            $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/dispute", ['note' => 'Not right'])->assertOk();
            $case = PaymentDispute::where('payment_report_id', $payment)->value('id');
            $this->postJson("/api/v1/me/disputes/{$case}/escalate", ['reason' => 'no_agreement', 'note' => 'We cannot agree.'])->assertOk();
            $this->signIn($this->root);
            $this->postJson("/api/v1/admin/disputes/{$case}/review")->assertOk();
            $this->postJson("/api/v1/admin/disputes/{$case}/decision", ['outcome' => $outcome, 'reason' => 'A reasoned decision for this test.'])->assertOk();
            $this->catchUp();
        }
        $this->assertSame([0, 0], [CommissionAssessment::count(), CommissionLedgerEntry::count()]);
    }

    /* ---------------- Integrity ---------------- */

    public function test_the_ledger_and_assessments_can_never_be_rewritten_and_a_reference_is_used_once()
    {
        $this->activate();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $this->confirmed($provider, $customer, $id, 100000);

        foreach ([
            function () {
                CommissionAssessment::first()->forceFill(['commission_ugx' => 1])->save();
            },
            function () {
                CommissionAssessment::first()->delete();
            },
            function () {
                CommissionLedgerEntry::first()->forceFill(['amount_ugx' => 1])->save();
            },
            function () {
                CommissionLedgerEntry::first()->delete();
            },
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("Attempt {$i} changed a protected record");
            } catch (LogicException $e) {
                $this->assertTrue(true);
            }
        }
        // A second ledger line with the same reference is refused by the database
        $a = CommissionAssessment::first();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('commission_ledger_entries')->insert(['assessment_id' => $a->id, 'provider_profile_id' => $a->provider_profile_id, 'entry_type' => 'assessment', 'amount_ugx' => 5000, 'reference' => 'assess:'.$a->payment_report_id, 'created_at' => now()]);
    }

    /* ---------------- Who sees what ---------------- */

    public function test_a_provider_sees_only_their_own_obligations_and_unconfirmed_money_is_not_in_them()
    {
        $this->activate();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $this->confirmed($provider, $customer, $id, 100000);
        $this->reported($provider, $id, 20000);   // waiting
        [$other, $otherCustomer, $otherId] = $this->priced();
        $this->confirmed($other, $otherCustomer, $otherId, 300000);

        $manager = User::factory()->create();
        $member = User::factory()->create();
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        $provider->members()->create(['user_id' => $member->id, 'role' => ProviderMember::ROLE_MEMBER]);

        // A manager has no access to the business's finances by default (Phase B9); the owner can give it
        $this->signIn($manager);
        $this->assertNull($this->getJson('/api/v1/me/commission')->assertOk()->json('data'));
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true])->assertOk();

        foreach ([$provider->owner, $manager] as $allowed) {
            $this->signIn($allowed);
            $data = $this->getJson('/api/v1/me/commission')->assertOk()->json('data');
            $this->assertSame([100000, 5000, 0, 5000], [$data['confirmed_payments_ugx'], $data['assessed_ugx'], $data['reversed_ugx'], $data['outstanding_ugx']]);
            $this->assertCount(1, $data['items']);
            $this->assertSame(['5%', 5000, 'outstanding'], [$data['items'][0]['rate'], $data['items'][0]['commission_ugx'], $data['items'][0]['status']]);
            $this->assertSame(['waiting_for_customer' => 1, 'questioned' => 0], $data['not_counted']);   // counted, never owed
            $this->assertStringNotContainsString('20000', json_encode($data));
            $this->assertStringNotContainsString('300000', json_encode($data));
        }
        foreach ([$member, $customer, User::factory()->create()] as $nobody) {
            $this->signIn($nobody);
            $this->assertNull($this->getJson('/api/v1/me/commission')->assertOk()->json('data'));
        }
        $this->signIn($other->owner);
        $this->assertSame(15000, $this->getJson('/api/v1/me/commission')->json('data.outstanding_ugx'));
    }

    public function test_the_provider_view_is_empty_while_obligations_are_off()
    {
        $this->activate(false);
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $this->confirmed($provider, $customer, $id, 100000);
        $this->signIn($provider->owner);
        $this->assertNull($this->getJson('/api/v1/me/commission')->assertOk()->json('data'));
    }

    public function test_administrators_read_obligations_with_the_commission_permission_and_cannot_change_them()
    {
        $this->activate();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);
        $a = CommissionAssessment::first();

        foreach ([$this->userWithRoles(['finance']), $this->root] as $reader) {
            $this->signIn($reader);
            $list = $this->getJson('/api/v1/admin/commission/assessments')->assertOk()->json('data');
            $this->assertSame([1, 5000, 5000], [$list['totals']['assessments'], $list['totals']['assessed_ugx'], $list['totals']['outstanding_ugx']]);
            $this->assertSame([$provider->id, 'outstanding', '5%', 1], [$list['items'][0]['provider_id'], $list['items'][0]['status'], $list['items'][0]['rate'], $list['items'][0]['policy_version']]);
            $this->assertStringContainsString('not collected', $list['label']);
            $detail = $this->getJson("/api/v1/admin/commission/assessments/{$a->id}")->assertOk()->json('data');
            $this->assertSame([['assessment', 5000]], array_map(function ($e) {
                return [$e['type'], $e['amount_ugx']];
            }, $detail['entries']));
            $this->getJson('/api/v1/admin/commission/assessments?provider='.$provider->id)->assertOk();
            $this->postJson('/api/v1/admin/commission/assessments', [])->assertStatus(405);
        }
        $this->getJson('/api/v1/admin/commission/assessments/99999')->assertNotFound();
        foreach ([$this->userWithRoles(['support']), $provider->owner, $customer] as $denied) {
            $this->signIn($denied);
            $this->getJson('/api/v1/admin/commission/assessments')->assertForbidden();
            $this->getJson("/api/v1/admin/commission/assessments/{$a->id}")->assertForbidden();
        }
    }

    public function test_the_catch_up_is_scheduled_and_does_nothing_while_off()
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(function ($e) {
            return $e->command;
        })->implode("\n");
        $this->assertStringContainsString('workmate:commission-assess', $events);
        $this->artisan('workmate:commission-assess')->expectsOutput('Commission obligations are switched off: nothing to do.')->assertExitCode(0);
    }

    public function test_a_missed_obligation_is_recorded_by_the_catch_up_when_the_switch_is_turned_on_later()
    {
        $this->setting('finance.pricing_enabled', true);
        $this->setting('finance.commission_shadow_enabled', true);
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $this->setting('finance.commission_assessment_from', now()->subMinute()->toIso8601String());
        $payment = $this->confirmed($provider, $customer, $id, 100000);   // confirmed after the start, while obligations are still off
        $this->assertSame(0, CommissionAssessment::count());
        $this->setting('finance.commission_assessment_enabled', true);
        $this->catchUp();
        $this->assertSame(5000, (int) CommissionAssessment::where('payment_report_id', $payment)->value('commission_ugx'));
        $this->catchUp();
        $this->assertSame(1, CommissionAssessment::count());
    }
}
