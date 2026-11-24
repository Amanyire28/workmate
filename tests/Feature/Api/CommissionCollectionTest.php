<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
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
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\SimulatedGateway;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Phase B10: collecting commission. Financial settings keep their real dependencies; a provider pays any part of what is owed (never more,
 * counting what is already on its way) by manual payment with proof or, in development and tests only, a simulated mobile money gateway;
 * every route to a credit is the same ledger path; repeats and races change nothing; nothing is credited by a proof or a request alone.
 */
class CommissionCollectionTest extends ApiTestCase
{
    /** @var User */
    private $root;

    /** @var User */
    private $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->root = $this->userWithRoles(['super_admin']);
        $this->finance = $this->userWithRoles(['finance']);
    }

    private function setting(string $key, $value): void
    {
        app(PlatformSettings::class)->set($key, $value, $this->root);
    }

    private function activate(bool $settlement = true): void
    {
        $this->setting('finance.pricing_enabled', true);
        $this->setting('finance.commission_shadow_enabled', true);
        $this->setting('finance.commission_assessment_from', now()->subHour()->toIso8601String());
        $this->setting('finance.commission_assessment_enabled', true);
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


    /** The business's own view of paying: GET /me/finance/pay */
    private function pay(User $user)
    {
        $this->signIn($user);

        return $this->getJson('/api/v1/me/finance/pay');
    }

    private function manual(ProviderProfile $provider, int $amount, array $extra = [], bool $proof = true)
    {
        $this->signIn($provider->owner);
        $fields = $extra + ['amount' => $amount, 'channel' => 'manual', 'reference' => 'BANK-'.uniqid(), 'paid_on' => now()->toDateString()];
        if ($proof) {
            $fields['evidence'] = UploadedFile::fake()->image('proof.png', 40, 40);
        }

        return $this->post('/api/v1/me/commission/payments', $fields, ['Accept' => 'application/json']);
    }

    private function verifyPayment(int $id, int $amount, array $extra = [])
    {
        $this->signIn($this->finance);

        return $this->postJson("/api/v1/admin/settlements/payments/{$id}/verify", $extra + ['amount_ugx' => $amount, 'receipt_confirmed' => true]);
    }

    private function start(User $user, int $amount, array $extra = [])
    {
        $this->signIn($user);

        return $this->postJson('/api/v1/me/finance/pay/mobile-money', $extra + ['method' => 'mtn_momo', 'amount' => $amount, 'phone' => '0772 123 456']);
    }

    private function phone(User $user, int $attempt, string $outcome)
    {
        $this->signIn($user);

        return $this->postJson("/api/v1/me/finance/pay/attempts/{$attempt}/simulate/{$outcome}");
    }

    /** A signed callback straight to the public endpoint, the way a gateway would send it */
    private function gatewayCallback(int $attempt, string $status, ?int $amount = null, ?string $signature = null)
    {
        $row = DB::table('commission_payment_attempts')->where('id', $attempt)->first();
        [$body, $sig] = (new SimulatedGateway())->callbackFor($row->reference, $row->gateway_reference, $amount ?? (int) $row->amount_ugx, $status);

        return $this->call('POST', '/api/v1/payments/callbacks/simulator', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $signature ?? $sig], $body);
    }

    private function settleSetup(): ProviderProfile
    {
        $this->activate();
        $this->rule();
        $this->setting('finance.commission_payment_instructions', 'Pay to merchant code 123456 (WorkMate).');
        $provider = $this->provider();
        $this->threeJobs($provider);   // 30,000 owed on one due cycle

        return $provider;
    }

    /* ---------------- Financial settings ---------------- */

    public function test_the_financial_settings_keep_their_real_dependencies_and_refuse_bad_combinations()
    {
        $set = function (string $key, $value) {
            $this->signIn($this->root);

            return $this->patchJson('/api/v1/admin/settings/'.$key, ['value' => $value, 'reason' => 'Testing']);
        };
        // Obligations need pricing, estimates and a valid start moment, in that sense, and each says so briefly
        $set('finance.commission_assessment_enabled', true)->assertStatus(422)->assertJsonPath('message', 'Turn on flexible pricing first.');
        $set('finance.pricing_enabled', true)->assertOk();
        $set('finance.commission_assessment_enabled', true)->assertStatus(422)->assertJsonPath('message', 'Turn on commission estimates first.');
        $set('finance.commission_shadow_enabled', true)->assertOk();
        $set('finance.commission_assessment_enabled', true)->assertStatus(422)->assertJsonPath('message', 'Choose the start date and time first.');
        // A start moment must be a real date and time; one typed without an offset is Kampala time and is stored with its offset
        $set('finance.commission_assessment_from', 'not a date')->assertStatus(422)->assertJsonPath('message', 'Choose a valid date and time.');
        $set('finance.commission_assessment_from', '2030-01-15T09:30')->assertOk()->assertJsonPath('data.value', '2030-01-15T09:30:00+03:00');
        $set('finance.commission_assessment_enabled', true)->assertOk();
        // While obligations are on, what they stand on cannot be removed; turning them off first frees it
        $set('finance.pricing_enabled', false)->assertStatus(422)->assertJsonPath('message', 'Turn off commission obligations first.');
        $set('finance.commission_shadow_enabled', false)->assertStatus(422);
        $set('finance.commission_assessment_from', '')->assertStatus(422)->assertJsonPath('message', 'Turn off commission obligations before clearing the start date.');
        // Settlement stands alone: it can be on with obligations off (providers still pay what was recorded) and needs nothing else
        $set('finance.commission_settlement_enabled', true)->assertOk();
        $set('finance.commission_assessment_enabled', false)->assertOk();
        $set('finance.commission_settlement_enabled', true)->assertOk();
        $set('finance.pricing_enabled', false)->assertOk();
        $set('finance.commission_accepts_cash', true)->assertOk();
        $set('finance.commission_payment_instructions', "Line one\nLine two")->assertOk()->assertJsonPath('data.value', "Line one\nLine two");
        $set('finance.settlement_three_job_window_hours', 12)->assertOk();
        // Every change is in the audit log, and nothing was activated without being asked for
        $this->assertGreaterThanOrEqual(8, AuditLog::where('action', 'settings.updated')->count());
        $this->assertSame('2030-01-15T09:30:00+03:00', app(PlatformSettings::class)->get('finance.commission_assessment_from'));
    }

    public function test_only_the_financial_settings_permission_sees_or_changes_them()
    {
        foreach (['finance', 'operations'] as $role) {
            $this->signIn($this->userWithRoles([$role]));
            $keys = array_column($this->getJson('/api/v1/admin/settings')->assertOk()->json('data'), 'key');
            $this->assertSame([], array_values(array_filter($keys, function ($k) {
                return strpos($k, 'finance.') === 0;
            })), $role);
            $this->patchJson('/api/v1/admin/settings/finance.pricing_enabled', ['value' => true])->assertStatus(404);
        }
        $this->signIn($this->root);
        $keys = array_column($this->getJson('/api/v1/admin/settings')->json('data'), 'key');
        $this->assertCount(10, array_filter($keys, function ($k) {
            return strpos($k, 'finance.') === 0;
        }));
    }

    public function test_the_start_moment_is_compared_on_the_database_clock()
    {
        $this->activate();
        $stored = app(PlatformSettings::class)->get('finance.commission_assessment_from');
        $this->assertStringEndsWith('+03:00', $stored);
        $start = app(\App\Services\CommissionAssessments::class)->startsAt();
        $this->assertSame(config('app.timezone'), $start->getTimezone()->getName());
        $this->assertEqualsWithDelta(now()->subHour()->timestamp, $start->timestamp, 2);
    }

    /* ---------------- Who can pay, and how much ---------------- */

    public function test_the_pay_button_appears_only_with_something_owed_settlement_on_and_permission_to_pay()
    {
        $this->activate();
        $this->rule();
        $provider = $this->provider();
        $manager = $this->userWithRoles([]);
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        // Nothing owed: no button
        $this->assertFalse($this->pay($provider->owner)->assertOk()->json('data.show_pay_button'));
        $this->threeJobs($provider);
        $data = $this->pay($provider->owner)->json('data');
        $this->assertTrue($data['show_pay_button']);
        $this->assertSame([30000, 0, 30000], [$data['payable_ugx'], $data['reserved_ugx'], $data['available_ugx']]);
        // A manager sees the finances only when the owner allows it, and pays only when the owner allows that too
        $this->pay($manager)->assertStatus(403);
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true])->assertOk();
        $this->assertFalse($this->pay($manager)->assertOk()->json('data.show_pay_button'));
        $this->manual($provider, 1000)->assertCreated();   // the owner can
        $this->signIn($manager);
        $this->post('/api/v1/me/commission/payments', ['amount' => 1000, 'channel' => 'manual', 'reference' => 'M-1', 'paid_on' => now()->toDateString(), 'evidence' => UploadedFile::fake()->image('p.png')], ['Accept' => 'application/json'])->assertStatus(403)->assertJsonPath('code', 'payment_not_allowed');
        $this->start($manager, 1000)->assertStatus(403);
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => true, 'can_pay' => true])->assertOk();
        $this->assertTrue($this->pay($manager)->json('data.show_pay_button'));
        // Taking the finances away takes paying away too
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/finance/access', ['user_id' => $manager->id, 'allowed' => false])->assertOk();
        $this->assertFalse((bool) ProviderMember::where('user_id', $manager->id)->value('can_pay_commission'));
        $this->pay($manager)->assertStatus(403);
        // Settlement off: no button, and nothing can start (history stays readable)
        $this->setting('finance.commission_settlement_enabled', false);
        $this->assertFalse($this->pay($provider->owner)->assertOk()->json('data.show_pay_button'));
        $this->start($provider->owner, 1000)->assertStatus(422)->assertJsonPath('message', 'Service currently not available.');
        // Everyone else
        $this->pay(User::factory()->create())->assertStatus(403);
    }

    public function test_a_payment_can_never_exceed_what_is_owed_counting_what_is_already_on_its_way()
    {
        $provider = $this->settleSetup();
        $this->manual($provider, 30001)->assertStatus(422)->assertJsonPath('code', 'amount_too_high');
        $first = $this->manual($provider, 20000)->assertCreated()->json('data.id');
        // 20,000 is waiting to be checked, so only 10,000 more can be offered, by either route
        $this->assertSame([30000, 20000, 10000], array_values(Arr_only($this->pay($provider->owner)->json('data'), ['payable_ugx', 'reserved_ugx', 'available_ugx'])));
        $this->manual($provider, 10001)->assertStatus(422)->assertJsonPath('code', 'amount_too_high');
        $this->start($provider->owner, 10001)->assertStatus(422)->assertJsonPath('code', 'amount_too_high');
        $attempt = $this->start($provider->owner, 10000)->assertCreated()->json('data.id');
        $this->start($provider->owner, 100)->assertStatus(422);      // nothing left to offer
        $this->manual($provider, 100)->assertStatus(422);
        // Nothing has been credited by any of this
        $this->assertSame(30000, $this->owed($provider));
        // A rejected proof gives its amount back
        $this->signIn($this->finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$first}/reject", ['reason' => 'The reference does not match'])->assertOk();
        $this->assertSame(20000, $this->pay($provider->owner)->json('data.available_ugx'));
        $this->assertNotNull($attempt);
    }

    /* ---------------- Manual payment with proof ---------------- */

    public function test_manual_payment_needs_proof_and_credits_nothing_until_finance_verifies_it()
    {
        $provider = $this->settleSetup();
        $this->manual($provider, 10000, [], false)->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $this->manual($provider, 10000, ['paid_on' => null])->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $id = $this->manual($provider, 10000)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.source', 'manual')->json('data.id');
        $this->assertSame(30000, $this->owed($provider));
        foreach ([$this->finance, $this->root] as $admin) {
            $this->assertSame(1, UserNotification::where('user_id', $admin->id)->where('title', 'A commission payment needs checking')->count());
        }
        $this->assertSame("/admin/finance/payments?payment={$id}", UserNotification::where('user_id', $this->finance->id)->where('title', 'A commission payment needs checking')->value('url'));
        // Verifying is the independent confirmation: the receipt box must be ticked
        $this->signIn($this->finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$id}/verify", ['amount_ugx' => 10000, 'receipt_confirmed' => false])->assertStatus(422);
        $res = $this->verifyPayment($id, 10000)->assertOk();
        $this->assertSame(20000, $this->owed($provider));
        $this->assertMatchesRegularExpression('/^WMR-\d{8}-\d{6}$/', $res->json('data.receipt_number'));
        // Asking again changes nothing; deciding differently is refused
        $this->verifyPayment($id, 10000)->assertOk();
        $this->assertSame(20000, $this->owed($provider));
        $this->signIn($this->finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$id}/reject", ['reason' => 'Changed my mind'])->assertStatus(409);
        $this->assertSame(1, CommissionLedgerEntry::where('commission_payment_id', $id)->count());
        // The provider is told, once, and the figures agree everywhere
        $this->assertSame(1, UserNotification::where('user_id', $provider->owner->id)->where('title', 'Commission payment verified')->count());
        $this->assertSame(20000, $this->pay($provider->owner)->json('data.payable_ugx'));
        $this->assertSame(20000, $this->getJson('/api/v1/me/finance/summary?period=all')->json('data.commission.as_of.outstanding_ugx'));
        $this->assertSame(20000, $this->getJson('/api/v1/me/commission')->json('data.outstanding_ugx'));
        // The totals the provider sees since they started, and what is waiting
        $this->manual($provider, 5000)->assertCreated();
        $asOf = $this->getJson('/api/v1/me/finance/summary?period=all')->json('data.commission.as_of');
        $this->assertSame([30000, 10000, 20000, 5000], [$asOf['assessed_total_ugx'], $asOf['verified_total_ugx'], $asOf['outstanding_ugx'], $asOf['pending_ugx']]);
    }

    public function test_a_rejected_proof_shows_its_reason_and_can_be_sent_again()
    {
        $provider = $this->settleSetup();
        $id = $this->manual($provider, 10000, ['reference' => 'BANK-777'])->assertCreated()->json('data.id');
        $this->signIn($this->finance);
        $this->postJson("/api/v1/admin/settlements/payments/{$id}/reject", ['reason' => 'The photo is unreadable'])->assertOk();
        $this->signIn($provider->owner);
        $mine = collect($this->getJson('/api/v1/me/finance/commission-payments')->assertOk()->json('data.items'))->firstWhere('id', $id);
        $this->assertSame(['rejected', 'The photo is unreadable'], [$mine['status'], $mine['decision_note']]);
        $this->assertSame(30000, $this->owed($provider));
        // The same reference can be sent again, with better proof
        $again = $this->manual($provider, 10000, ['reference' => 'BANK-777'])->assertCreated()->json('data.id');
        $this->verifyPayment($again, 10000)->assertOk();
        $this->assertSame(20000, $this->owed($provider));
        // …but a reference that is already pending or verified is refused
        $this->manual($provider, 1000, ['reference' => 'BANK-777'])->assertStatus(409)->assertJsonPath('code', 'duplicate_reference');
    }

    public function test_a_payment_is_spread_over_the_open_settlements_oldest_first_and_cleared_when_all_is_paid()
    {
        $this->activate();
        $this->rule();
        $this->setting('finance.commission_payment_instructions', 'Pay to merchant code 123456 (WorkMate).');
        $provider = $this->provider();
        $this->threeJobs($provider);
        $this->job($provider);   // a second settlement with 10,000
        $first = $this->cycleOf($provider);
        $second = $this->cycleOf($provider, 1);
        $this->assertSame(40000, $this->pay($provider->owner)->json('data.payable_ugx'));
        $id = $this->manual($provider, 35000)->assertCreated()->json('data.id');
        $this->verifyPayment($id, 35000)->assertOk()->assertJsonPath('data.credited_ugx', 35000)->assertJsonPath('data.excess_ugx', 0);
        $lines = CommissionLedgerEntry::where('commission_payment_id', $id)->orderBy('id')->get();
        $this->assertSame([[$first, -30000], [$second, -5000]], $lines->map(function ($l) {
            return [(int) $l->cycle_id, (int) $l->amount_ugx];
        })->all());
        $this->assertSame('settled', $this->state($first)['status']);
        $this->assertSame(5000, $this->state($second)['outstanding_ugx']);
        $this->assertSame(5000, $this->owed($provider));
        // Paying the rest clears it, the provider is told, and the button goes
        $last = $this->manual($provider, 5000)->assertCreated()->json('data.id');
        $this->verifyPayment($last, 5000)->assertOk();
        $this->assertSame(0, $this->owed($provider));
        $this->assertSame(1, UserNotification::where('user_id', $provider->owner->id)->where('title', 'Commission cleared')->count());
        $this->assertFalse($this->pay($provider->owner)->json('data.show_pay_button'));
        $this->manual($provider, 100)->assertStatus(409)->assertJsonPath('code', 'cycle_settled');
        // Ledger and cycles agree
        $this->assertSame(0, array_sum(array_map(function ($c) {
            return max($c['outstanding_ugx'], 0);
        }, [$this->state($first), $this->state($second)])));
    }

    public function test_money_beyond_what_was_reported_is_never_applied_by_itself()
    {
        $provider = $this->settleSetup();
        $id = $this->manual($provider, 10000)->assertCreated()->json('data.id');
        $this->verifyPayment($id, 25000, ['note' => 'More money arrived than reported'])->assertOk()->assertJsonPath('data.credited_ugx', 10000)->assertJsonPath('data.excess_ugx', 15000);
        $this->assertSame(20000, $this->owed($provider));
        $this->assertSame(15000, (int) CommissionException::where('commission_payment_id', $id)->value('amount_ugx'));
        $this->assertSame(1, UserNotification::where('user_id', $this->root->id)->where('title', 'A payment needs attention')->count());
    }

    /* ---------------- Mobile money through the (simulated) gateway ---------------- */

    public function test_a_mobile_money_payment_goes_pending_then_verified_through_the_same_ledger_path()
    {
        $provider = $this->settleSetup();
        $res = $this->start($provider->owner, 12000)->assertCreated();
        $this->assertSame(['processing', 'MTN Mobile Money', '0772 *** 456', 12000], [$res->json('data.status'), $res->json('data.method_label'), $res->json('data.phone'), $res->json('data.amount_ugx')]);
        $this->assertMatchesRegularExpression('/^WMC-[0-9A-F]{10}$/', $res->json('data.reference'));
        $this->assertStringNotContainsString('0772123456', json_encode($res->json()));
        $this->assertSame(1, UserNotification::where('user_id', $provider->owner->id)->where('title', 'Payment request sent')->count());
        $id = $res->json('data.id');
        $this->assertSame(30000, $this->owed($provider));                 // a request alone credits nothing
        $this->assertSame(12000, $this->pay($provider->owner)->json('data.reserved_ugx'));

        $done = $this->phone($provider->owner, $id, 'successful')->assertOk()->json('data');
        $this->assertSame('successful', $done['status']);
        $this->assertSame(18000, $this->owed($provider));
        $report = CommissionPaymentReport::where('attempt_id', $id)->firstOrFail();
        $this->assertSame(['gateway', 'verified', 12000, 12000, null], [$report->source, $report->status, (int) $report->credited_ugx, (int) $report->verified_amount_ugx, $report->decided_by_user_id]);
        $this->assertNotNull($report->receipt_number);
        $this->assertSame(0, $this->pay($provider->owner)->json('data.reserved_ugx'));
        $this->signIn($provider->owner);
        $this->assertSame('successful', $this->getJson("/api/v1/me/finance/pay/attempts/{$id}")->json('data.status'));
        // The receipt agrees with the ledger
        $receipt = $this->getJson("/api/v1/me/finance/receipts/{$report->id}")->assertOk()->json('data');
        $this->assertSame([12000, 18000, $report->receipt_number], [$receipt['applied_ugx'], $receipt['balance_after_ugx'], $receipt['receipt_number']]);
        $pdf = $this->get("/api/v1/me/finance/receipts/{$report->id}.pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        // Audited, and the administrator sees one successful, automatically verified payment
        $this->assertSame(1, AuditLog::where('action', 'commission.payment_verified')->where('subject_id', $report->id)->count());
        $this->signIn($this->finance);
        $row = collect($this->getJson('/api/v1/admin/commission-payments?q='.$report->receipt_number)->assertOk()->json('data.items'))->first();
        $this->assertSame(['successful', 'automatic', 'gateway'], [$row['status'], $row['verification'], $row['source']]);
    }

    public function test_repeated_and_concurrent_confirmations_never_credit_twice()
    {
        $provider = $this->settleSetup();
        $id = $this->start($provider->owner, 10000)->assertCreated()->json('data.id');
        $this->gatewayCallback($id, 'successful')->assertOk();
        $this->gatewayCallback($id, 'successful')->assertOk();
        $this->gatewayCallback($id, 'successful')->assertOk();
        $this->assertSame(1, CommissionPaymentReport::where('attempt_id', $id)->count());
        $this->assertSame(1, CommissionLedgerEntry::where('provider_profile_id', $provider->id)->where('entry_type', 'settlement')->count());
        $this->assertSame(20000, $this->owed($provider));
        $this->assertSame(1, UserNotification::where('user_id', $provider->owner->id)->where('title', 'Commission payment verified')->count());
        // The same gateway reference can become a payment only once, whatever the attempt
        $this->assertSame(1, CommissionPaymentReport::where('active_reference', 'like', 'mtn_momo:sim-%')->count());
        // A repeated request with the same token is the same attempt
        $one = $this->start($provider->owner, 5000, ['client_token' => 'tok-9'])->assertCreated()->json('data.id');
        $two = $this->start($provider->owner, 5000, ['client_token' => 'tok-9'])->json('data.id');
        $this->assertSame($one, $two);
        $this->assertSame(1, DB::table('commission_payment_attempts')->where('client_token', 'tok-9')->count());
    }

    public function test_a_failed_cancelled_or_expired_request_credits_nothing_and_frees_the_amount()
    {
        $provider = $this->settleSetup();
        $failed = $this->start($provider->owner, 5000)->assertCreated()->json('data.id');
        $this->phone($provider->owner, $failed, 'failed')->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame('failed', DB::table('commission_payment_attempts')->where('id', $failed)->value('status'));
        $this->assertSame(1, UserNotification::where('user_id', $provider->owner->id)->where('title', 'Payment failed')->count());
        $cancelled = $this->start($provider->owner, 5000)->assertCreated()->json('data.id');
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/finance/pay/attempts/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $expired = $this->start($provider->owner, 5000)->assertCreated()->json('data.id');
        $this->assertSame(5000, $this->pay($provider->owner)->json('data.reserved_ugx'));
        $this->travel(20)->minutes();
        $this->assertSame(0, $this->pay($provider->owner)->json('data.reserved_ugx'));        // an old request no longer holds the amount
        $this->artisan('workmate:expire-commission-attempts')->assertExitCode(0);
        $this->artisan('workmate:expire-commission-attempts')->assertExitCode(0);
        $this->assertSame('expired', DB::table('commission_payment_attempts')->where('id', $expired)->value('status'));
        $this->assertSame(30000, $this->owed($provider));
        $this->assertSame(0, CommissionPaymentReport::count());
        $this->assertSame(30000, $this->pay($provider->owner)->json('data.available_ugx'));
        $this->assertSame(['created', 'sent', 'expired'], DB::table('commission_payment_attempt_events')->where('attempt_id', $expired)->orderBy('id')->pluck('event')->all());
    }

    public function test_money_confirmed_late_or_for_another_amount_opens_an_exception_and_is_never_applied()
    {
        $provider = $this->settleSetup();
        $cancelled = $this->start($provider->owner, 5000)->assertCreated()->json('data.id');
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/finance/pay/attempts/{$cancelled}/cancel")->assertOk();
        $this->gatewayCallback($cancelled, 'successful')->assertOk();
        $this->assertSame(0, CommissionPaymentReport::count());
        $this->assertSame(30000, $this->owed($provider));
        $this->assertSame([CommissionException::LATE_PAYMENT, 5000], [CommissionException::first()->kind, (int) CommissionException::first()->amount_ugx]);

        $other = $this->start($provider->owner, 8000)->assertCreated()->json('data.id');
        $this->gatewayCallback($other, 'successful', 9000)->assertOk();
        $this->assertSame('failed', DB::table('commission_payment_attempts')->where('id', $other)->value('status'));
        $this->assertSame(30000, $this->owed($provider));
        $this->assertSame(2, CommissionException::count());
        $this->assertGreaterThanOrEqual(1, UserNotification::where('user_id', $this->root->id)->where('title', 'A payment needs attention')->count());
    }

    public function test_a_callback_that_cannot_be_verified_is_refused_and_changes_nothing()
    {
        $provider = $this->settleSetup();
        $id = $this->start($provider->owner, 10000)->assertCreated()->json('data.id');
        $this->gatewayCallback($id, 'successful', null, 'forged')->assertStatus(401);
        $this->gatewayCallback($id, 'successful', null, '')->assertStatus(401);
        $this->postJson('/api/v1/payments/callbacks/nobody', ['x' => 1])->assertStatus(404);
        $this->assertSame(30000, $this->owed($provider));
        $this->assertSame('processing', DB::table('commission_payment_attempts')->where('id', $id)->value('status'));
        $this->assertGreaterThanOrEqual(2, AuditLog::where('action', 'commission.gateway_callback_refused')->count());
    }

    public function test_unavailable_methods_say_only_that_and_the_simulator_cannot_exist_in_production()
    {
        $provider = $this->settleSetup();
        $methods = collect($this->pay($provider->owner)->json('data.methods'))->pluck('available', 'id')->all();
        $this->assertSame(['mtn_momo' => true, 'airtel_money' => true, 'manual' => true], $methods);   // the simulator, in this environment
        // As in production: no gateway exists, so nothing can start and nobody can approve anything
        $this->app['env'] = 'production';
        $this->assertSame([], app(GatewayRegistry::class)->all());
        $methods = collect($this->pay($provider->owner)->json('data.methods'))->pluck('available', 'id')->all();
        $this->assertSame(['mtn_momo' => false, 'airtel_money' => false, 'manual' => true], $methods);
        $res = $this->start($provider->owner, 5000)->assertStatus(422);
        $this->assertSame('Service currently not available.', $res->json('message'));
        $this->start($provider->owner, 5000, ['method' => 'airtel_money'])->assertStatus(422)->assertJsonPath('message', 'Service currently not available.');
        $this->start($provider->owner, 5000, ['method' => 'paypal'])->assertStatus(422)->assertJsonPath('message', 'Service currently not available.');
        $this->assertSame(0, DB::table('commission_payment_attempts')->count());
        // A gateway callback has nothing to talk to
        $this->postJson('/api/v1/payments/callbacks/simulator', [])->assertStatus(404);
        // Manual payment needs WorkMate's instructions: without them it is unavailable too, and the screen says nothing more
        $this->setting('finance.commission_payment_instructions', '');
        $methods = collect($this->pay($provider->owner)->json('data.methods'))->pluck('available', 'id')->all();
        $this->assertFalse($methods['manual']);
    }

    public function test_a_phone_number_must_be_a_ugandan_mobile_and_is_never_returned_whole()
    {
        $provider = $this->settleSetup();
        foreach (['', '12345', '0212345678', '+1 202 555 0100', 'abc'] as $bad) {
            $this->start($provider->owner, 5000, ['phone' => $bad])->assertStatus(422)->assertJsonPath('code', 'phone_invalid');
        }
        foreach (['0772123456', '+256772123456', '256 772 123 456'] as $good) {
            $body = $this->start($provider->owner, 100, ['phone' => $good])->assertCreated()->json('data');
            $this->assertSame('0772 *** 456', $body['phone']);
        }
        $this->assertStringNotContainsString('772123456', json_encode(DB::table('commission_payment_attempts')->get()));
    }

    /* ---------------- Isolation ---------------- */

    public function test_another_business_can_never_see_pay_or_read_these_payments()
    {
        $provider = $this->settleSetup();
        $other = $this->provider();
        $attempt = $this->start($provider->owner, 5000)->assertCreated()->json('data.id');
        $this->gatewayCallback($attempt, 'successful');
        $report = CommissionPaymentReport::where('attempt_id', $attempt)->value('id');
        $this->signIn($other->owner);
        $this->getJson("/api/v1/me/finance/pay/attempts/{$attempt}")->assertStatus(404);
        $this->postJson("/api/v1/me/finance/pay/attempts/{$attempt}/cancel")->assertStatus(404);
        $this->postJson("/api/v1/me/finance/pay/attempts/{$attempt}/simulate/successful")->assertStatus(404);
        $this->getJson("/api/v1/me/finance/receipts/{$report}")->assertStatus(404);
        $this->get("/api/v1/me/finance/receipts/{$report}.pdf")->assertStatus(404);
        $this->assertFalse($this->pay($other->owner)->json('data.show_pay_button'));
        // Nobody pays another business's balance, whatever they send
        $this->assertSame(0, $this->pay($other->owner)->json('data.payable_ugx'));
        $this->start($other->owner, 5000)->assertStatus(409)->assertJsonPath('code', 'nothing_owed');
        // A customer, anonymous callers and administrators without the right permission
        $this->pay(User::factory()->create())->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me/finance/pay')->assertStatus(401);
        $this->signIn($this->userWithRoles(['operations']));
        $this->getJson('/api/v1/admin/commission-payments')->assertStatus(403);
    }

    /* ---------------- The administrator's workspace ---------------- */

    public function test_commission_payments_lists_every_kind_with_filters_search_sorting_paging_and_detail()
    {
        $provider = $this->settleSetup();
        $manual = $this->manual($provider, 4000, ['reference' => 'BANK-AAA'])->assertCreated()->json('data.id');
        $failed = $this->start($provider->owner, 3000)->assertCreated()->json('data.id');
        $this->phone($provider->owner, $failed, 'failed');
        $pending = $this->start($provider->owner, 2000)->assertCreated()->json('data.id');
        $paid = $this->start($provider->owner, 1000)->assertCreated()->json('data.id');
        $this->phone($provider->owner, $paid, 'successful');

        $this->signIn($this->finance);
        $s = $this->getJson('/api/v1/admin/commission-payments/summary')->assertOk()->json('data');
        $this->assertSame([1000, 1, 4000, 1, 2000, 1, 1], [$s['verified_ugx'], $s['verified_count'], $s['awaiting_ugx'], $s['awaiting_count'], $s['gateway_pending_ugx'], $s['gateway_pending_count'], $s['failed_count']]);
        $this->assertSame(29000, $s['outstanding_ugx']);                       // the same figure as the ledger
        $all = $this->getJson('/api/v1/admin/commission-payments')->assertOk()->json('data');
        $this->assertSame(4, $all['total']);
        $by = function (array $q) {
            return $this->getJson('/api/v1/admin/commission-payments?'.http_build_query($q))->assertOk()->json('data');
        };
        $this->assertSame(['awaiting_verification'], array_column($by(['status' => 'awaiting_verification'])['items'], 'status'));
        $this->assertSame(['failed'], array_column($by(['status' => 'failed'])['items'], 'status'));
        $this->assertSame(2, $by(['source' => 'gateway', 'status' => 'processing'])['total'] + $by(['source' => 'gateway', 'status' => 'successful'])['total']);
        $this->assertSame(1, $by(['q' => 'BANK-AAA'])['total']);
        $this->assertSame(4, $by(['q' => 'Okello'])['total']);
        $this->assertSame(0, $by(['q' => 'nobody-like-this'])['total']);
        $this->assertSame([4000, 3000, 2000, 1000], array_column($by(['sort' => 'amount_ugx', 'dir' => 'desc'])['items'], 'amount_ugx'));
        $this->assertSame(4, $by(['provider' => $provider->id])['total']);
        $this->assertSame(0, $by(['provider' => $provider->id + 999])['total']);
        $this->assertSame(0, $by(['from' => now()->addDays(2)->toDateString()])['total']);
        $this->getJson('/api/v1/admin/commission-payments?status=bogus')->assertStatus(422);
        // Detail: provider, payment, evidence, transactions, verification, allocations, audit
        $d = $this->getJson("/api/v1/admin/commission-payments/report/{$manual}")->assertOk()->json('data');
        $this->assertSame([$provider->id, 4000, true], [$d['provider']['id'], $d['payment']['amount_ugx'], $d['evidence'] !== null]);
        $this->assertNotSame([], $d['audit']);
        $this->assertStringNotContainsString('commission-evidence', json_encode($d));
        $this->getJson("/api/v1/admin/commission-payments/attempt/{$pending}")->assertOk()->assertJsonPath('data.status', 'processing');
        $paidReport = CommissionPaymentReport::where('attempt_id', $paid)->value('id');
        $v = $this->getJson("/api/v1/admin/commission-payments/report/{$paidReport}")->json('data');
        $this->assertSame([['cycle_id' => $this->cycleOf($provider), 'amount_ugx' => 1000]], array_map(function ($a) {
            return ['cycle_id' => $a['cycle_id'], 'amount_ugx' => $a['amount_ugx']];
        }, $v['allocations']));
        $this->assertSame('Gateway confirmation', $v['verification'][0]['by']);
        $this->getJson('/api/v1/admin/commission-payments/report/999999')->assertStatus(404);
    }

    public function test_the_payments_export_needs_the_reports_permission_and_is_safe_in_a_spreadsheet()
    {
        $provider = $this->settleSetup();
        $this->manual($provider, 4000)->assertCreated();
        ProviderProfile::whereKey($provider->id)->update(['display_name' => '=HYPERLINK("http://evil")']);
        $this->signIn($this->userWithRoles(['finance']));
        $csv = $this->get('/api/v1/admin/commission-payments-export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Payment reference', $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        // Reading the list is not enough to export it
        $reader = $this->userWithRoles([]);
        $role = \App\Models\Role::create(['key' => 'payments_reader', 'name' => 'Payments reader']);
        $role->permissions()->attach(\App\Models\Permission::where('key', 'settlements.view')->pluck('id'));
        $reader->roles()->attach($role->id);
        $this->signIn($reader);
        $this->getJson('/api/v1/admin/commission-payments')->assertOk();
        $this->getJson('/api/v1/admin/commission-payments-export')->assertStatus(403);
    }

    public function test_the_provider_payment_history_and_the_admin_list_show_the_same_payments()
    {
        $provider = $this->settleSetup();
        $a = $this->manual($provider, 4000)->assertCreated()->json('data.id');
        $this->verifyPayment($a, 4000)->assertOk();
        $b = $this->start($provider->owner, 6000)->assertCreated()->json('data.id');
        $this->phone($provider->owner, $b, 'successful');
        $this->signIn($provider->owner);
        $mine = $this->getJson('/api/v1/me/finance/commission-payments')->assertOk()->json('data.items');
        $this->signIn($this->finance);
        $admin = $this->getJson('/api/v1/admin/commission-payments?status=successful')->json('data.items');
        $this->assertEqualsCanonicalizing(array_column($mine, 'amount_ugx'), array_column($admin, 'amount_ugx'));
        $this->assertSame(20000, $this->owed($provider));
        $this->signIn($this->finance);
        $this->assertSame(20000, $this->getJson('/api/v1/admin/financial/overview?provider='.$provider->id)->json('data.as_of.outstanding_ugx'));
        $this->assertSame(10000, $this->getJson('/api/v1/admin/commission-payments/summary')->json('data.verified_ugx'));
    }
}

function Arr_only(array $data, array $keys): array
{
    return array_intersect_key($data, array_flip($keys));
}
