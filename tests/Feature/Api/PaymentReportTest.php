<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\JobFinalPrice;
use App\Models\JobPaymentEvent;
use App\Models\JobPaymentReport;
use App\Models\JobPriceAgreement;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Phase B3: the final price and payments a provider REPORTS having received directly from the customer. A report counts
 * as confirmed only after the customer explicitly confirms it. WorkMate takes no payment, and none of this creates a
 * commission, a debt or any obligation.
 */
class PaymentReportTest extends ApiTestCase
{
    private function switchOn(bool $on = true): void
    {
        app(PlatformSettings::class)->set('finance.pricing_enabled', $on, $this->userWithRoles(['super_admin']));
    }

    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, [
            'type' => 'individual', 'display_name' => 'Okello Services '.$owner->id,
            'professions' => [['profession' => 'plumber'], ['profession' => 'electrician']], 'base_area' => 'ntinda',
        ]);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    private function request(ProviderProfile $provider, User $customer, array $extra = []): int
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/providers/{$provider->id}/requests", $extra + [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow morning',
        ])->assertCreated()->json('data.id');
    }

    /** The job is accepted and under way (payments can be reported) */
    private function underway(int $id): void
    {
        ServiceRequest::whereKey($id)->update(['status' => 'in_progress']);
    }

    /** A price proposed by the provider and accepted by the customer (the B2 workflow) */
    private function agree(ProviderProfile $provider, User $customer, int $id, int $amount = 150000): int
    {
        $this->signIn($provider->owner);
        $version = $this->postJson("/api/v1/me/received-requests/{$id}/agreements", ['amount' => $amount, 'scope' => 'Replace the kitchen sink pipes and fit a new tap.'])->assertCreated()->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$version}/accept", ['amount' => $amount])->assertOk();

        return $version;
    }

    private function report(User $by, int $id, array $extra = [])
    {
        $this->signIn($by);

        return $this->postJson("/api/v1/me/received-requests/{$id}/payments", $extra + ['amount' => 50000, 'method' => 'cash']);
    }

    private function confirm(User $customer, int $id, int $payment, $amount)
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => $amount]);
    }

    private function dispute(User $customer, int $id, int $payment, array $body = [])
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/dispute", $body);
    }

    private function cancel(User $by, int $id, int $payment, array $body = ['reason' => 'I entered the wrong amount.'])
    {
        $this->signIn($by);

        return $this->postJson("/api/v1/me/received-requests/{$id}/payments/{$payment}/cancel", $body);
    }

    /** pricing on; a provider, a customer, a job under way with a price agreed: [provider, customer, id] */
    private function priced(int $amount = 150000): array
    {
        $this->switchOn();
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->request($provider, $customer);
        $this->underway($id);
        $this->agree($provider, $customer, $id, $amount);

        return [$provider, $customer, $id];
    }

    private function notifications(User $user, string $title)
    {
        return UserNotification::where('user_id', $user->id)->where('title', $title);
    }

    private function totals(User $customer, int $id): array
    {
        $this->signIn($customer);

        return collect($this->getJson('/api/v1/me/requests')->json('data'))->firstWhere('id', $id)['payments']['totals'];
    }

    /* ---------------- The switch ---------------- */

    public function test_while_pricing_is_off_nothing_can_be_reported_decided_or_read_and_nothing_is_stored()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->request($provider, $customer);
        $this->underway($id);

        $this->report($provider->owner, $id)->assertStatus(409)->assertJson(['code' => 'pricing_unavailable']);
        $this->cancel($provider->owner, $id, 1)->assertStatus(409)->assertJson(['code' => 'pricing_unavailable']);
        $this->getJson("/api/v1/me/received-requests/{$id}/payments")->assertStatus(409);
        $this->confirm($customer, $id, 1, 50000)->assertStatus(409)->assertJson(['code' => 'pricing_unavailable']);
        $this->dispute($customer, $id, 1)->assertStatus(409);
        $this->getJson("/api/v1/me/requests/{$id}/payments")->assertStatus(409);

        // The existing responses carry no payments block while it is off
        $this->assertNull($this->getJson('/api/v1/me/requests')->json('data.0.payments'));
        $this->signIn($provider->owner);
        $this->assertNull($this->getJson('/api/v1/me/received-requests')->json('data.0.payments'));

        // Finishing a job still works exactly as before, and locks nothing
        $this->postJson("/api/v1/me/received-requests/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame([0, 0, 0], [JobPaymentReport::count(), JobPaymentEvent::count(), JobFinalPrice::count()]);
    }

    public function test_switching_pricing_off_hides_payments_but_keeps_them()
    {
        [$provider, $customer, $id] = $this->priced();
        $this->report($provider->owner, $id)->assertCreated();
        $this->switchOn(false);
        $this->signIn($customer);
        $this->assertNull($this->getJson('/api/v1/me/requests')->json('data.0.payments'));
        $this->confirm($customer, $id, JobPaymentReport::first()->id, 50000)->assertStatus(409);
        $this->assertSame(JobPaymentReport::PENDING, JobPaymentReport::first()->status);
        $this->switchOn();
        $this->assertSame(50000, $this->totals($customer, $id)['pending']);
    }

    /* ---------------- Reporting a payment ---------------- */

    public function test_the_owner_reports_a_payment_and_the_customer_is_told_without_the_amount()
    {
        [$provider, $customer, $id] = $this->priced();

        $this->report($provider->owner, $id, ['amount' => 50000, 'method' => 'mobile_money', 'paid_on' => now(\App\Services\Scheduling::TIMEZONE)->toDateString(), 'reference' => 'MP2611.1234.A5', 'note' => 'Deposit'])->assertCreated()
            ->assertJsonPath('data.payments.reports.0.amount', 50000)
            ->assertJsonPath('data.payments.reports.0.status', 'pending_confirmation')
            ->assertJsonPath('data.payments.totals.pending', 50000)
            ->assertJsonPath('data.payments.totals.confirmed', 0)
            ->assertJsonPath('data.payments.totals.remaining', 150000)
            ->assertJsonPath('data.payments.final_price.amount', 150000);

        $r = JobPaymentReport::first();
        $this->assertSame([$id, $provider->id, $customer->id, $provider->owner_id, 50000, 'mobile_money', 'MP2611.1234.A5', 'pending_confirmation'],
            [$r->service_request_id, $r->provider_profile_id, $r->customer_id, $r->reported_by_user_id, $r->amount_ugx, $r->method, $r->reference, $r->status]);
        $this->assertSame(JobFinalPrice::first()->id, $r->final_price_id);
        $this->assertSame(JobPriceAgreement::where('agreed_slot', 1)->value('id'), $r->agreement_id);
        $this->assertSame(['reported'], JobPaymentEvent::where('payment_id', $r->id)->pluck('event')->all());
        $this->assertTrue(AuditLog::where('action', 'marketplace.payment_reported')->where('actor_id', $provider->owner_id)->exists());

        // Reporting a payment does not touch the job
        $this->assertSame('in_progress', ServiceRequest::find($id)->status);

        $note = $this->notifications($customer, 'Payment reported')->first();
        $this->assertNotNull($note);
        $this->assertSame($provider->owner_id, (int) $note->actor_id);
        $this->assertStringNotContainsString('50', $note->body);
        $this->assertStringNotContainsString('mobile', strtolower($note->body));
        $this->assertStringContainsString('request='.$id, $note->url);

        // A provider's statement is not a verified payment: nothing is confirmed
        $this->assertSame(0, $this->totals($customer, $id)['confirmed']);
    }

    public function test_reported_payments_are_checked_by_the_server()
    {
        [$provider, $customer, $id] = $this->priced();
        $owner = $provider->owner;
        $today = now(\App\Services\Scheduling::TIMEZONE)->toDateString();

        $this->report($owner, $id, ['amount' => 99])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->report($owner, $id, ['amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->report($owner, $id, ['amount' => 100000001])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->report($owner, $id, ['amount' => 1500.5])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->report($owner, $id, ['amount' => '5000abc'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->report($owner, $id, ['amount' => -5000])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->report($owner, $id, ['amount' => null])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->report($owner, $id, ['method' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->report($owner, $id, ['method' => null])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->report($owner, $id, ['paid_on' => '20-11-2026'])->assertStatus(422)->assertJsonValidationErrors('paid_on');
        $this->report($owner, $id, ['paid_on' => now()->addDays(3)->toDateString()])->assertStatus(422)->assertJson(['code' => 'date_future']);
        $this->report($owner, $id, ['paid_on' => now()->subYears(2)->toDateString()])->assertStatus(422)->assertJson(['code' => 'date_before_job']);
        $this->report($owner, $id, ['reference' => 'PIN 1234 <b>'])->assertStatus(422)->assertJson(['code' => 'reference_invalid']);
        $this->report($owner, $id, ['reference' => str_repeat('A', 61)])->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->report($owner, $id, ['note' => str_repeat('a', 301)])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->report($owner, $id, ['client_token' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('client_token');
        $this->report($owner, $id, ['corrects' => 999])->assertStatus(422)->assertJson(['code' => 'corrects_invalid']);
        $this->assertSame(0, JobPaymentReport::count());

        // The limits themselves are fine; the optional fields can be left out
        // The minimum is UGX 100: small part payments and change are real
        $this->report($owner, $id, ['amount' => 100, 'paid_on' => $today])->assertCreated();
        $this->assertSame(100, JobPaymentReport::first()->amount_ugx);
        $this->assertNull(JobPaymentReport::first()->reference);
    }

    public function test_only_the_owner_or_a_manager_can_report_and_only_for_a_job_that_was_accepted()
    {
        [$provider, $customer, $id] = $this->priced();
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        $provider->members()->create(['user_id' => $member->id, 'role' => ProviderMember::ROLE_MEMBER]);

        $this->report($member, $id)->assertStatus(403)->assertJson(['code' => 'not_authorised']);
        $this->report($customer, $id)->assertNotFound();
        $this->report($this->provider()->owner, $id)->assertNotFound();
        $this->report(User::factory()->create(), $id)->assertNotFound();
        $this->assertSame(0, JobPaymentReport::count());
        $this->report($manager, $id)->assertCreated();
        $this->assertSame($manager->id, JobPaymentReport::first()->reported_by_user_id);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json'])->postJson("/api/v1/me/received-requests/{$id}/payments", ['amount' => 5000, 'method' => 'cash'])->assertStatus(401);

        // A job still waiting for the provider's answer, or cancelled, takes no new report; one under way or done does
        foreach (['open', 'cancelled'] as $status) {
            ServiceRequest::whereKey($id)->update(['status' => $status]);
            $this->report($provider->owner, $id, ['amount' => 5000])->assertStatus(409)->assertJson(['code' => 'request_status_conflict']);
        }
        foreach (['scheduled', 'completed'] as $status) {
            ServiceRequest::whereKey($id)->update(['status' => $status]);
            $this->report($provider->owner, $id, ['amount' => 5000 + JobPaymentReport::count()])->assertCreated();
        }

        // Requests from before bookings existed, and suspended providers, are refused
        ServiceRequest::whereKey($id)->update(['status' => 'in_progress', 'booking_version' => ServiceRequest::BOOKING_LEGACY]);
        $this->report($provider->owner, $id, ['amount' => 6000])->assertStatus(409)->assertJson(['code' => 'legacy_request']);
        ServiceRequest::whereKey($id)->update(['booking_version' => ServiceRequest::BOOKING_V2]);
        $provider->forceFill(['status' => ProviderProfile::STATUS_SUSPENDED])->save();
        $this->report($provider->owner, $id, ['amount' => 6000])->assertStatus(403)->assertJson(['code' => 'provider_not_active']);
    }

    public function test_the_same_try_again_returns_the_first_report_and_an_identical_waiting_one_is_a_duplicate()
    {
        [$provider, $customer, $id] = $this->priced();
        $token = '8a9f3a52-1b8e-4a3e-9d0b-3a2f4f6c9e10';

        $first = $this->report($provider->owner, $id, ['client_token' => $token])->assertCreated()->json('data.payments.reports.0.id');
        $second = $this->report($provider->owner, $id, ['client_token' => $token])->assertOk()->json('data.payments.reports.0.id');
        $this->report($provider->owner, $id, ['client_token' => $token, 'amount' => 99000])->assertOk();
        $this->assertSame($first, $second);
        $this->assertSame([1, 1, 1], [JobPaymentReport::count(), JobPaymentEvent::where('event', 'reported')->count(), $this->notifications($customer, 'Payment reported')->count()]);

        // Without a token, the same waiting report is refused rather than counted twice
        $this->report($provider->owner, $id)->assertStatus(409)->assertJson(['code' => 'duplicate_report']);
        $this->assertSame(1, JobPaymentReport::count());
    }

    /* ---------------- The final price ---------------- */

    public function test_the_final_price_is_the_agreed_price_locked_never_typed_and_a_job_without_one_gets_none()
    {
        $this->switchOn();
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->request($provider, $customer);
        $this->underway($id);

        // No agreement: payments can still be reported, but no price is invented and there is no balance
        $res = $this->report($provider->owner, $id, ['amount' => 70000])->assertCreated();
        $res->assertJsonPath('data.payments.final_price', null)->assertJsonPath('data.payments.agreed_amount', null)->assertJsonPath('data.payments.totals.remaining', null);
        $this->assertSame([0, 0], [JobFinalPrice::count(), JobPriceAgreement::count()]);
        $this->report($provider->owner, $id, ['amount' => 9000000])->assertCreated();   // nothing to compare with, so no cap

        // Finishing a job with no agreement locks nothing and fabricates nothing
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/complete")->assertOk();
        $this->assertSame([0, 0], [JobFinalPrice::count(), JobPriceAgreement::count()]);
    }

    public function test_a_finished_job_locks_the_agreed_price_as_its_final_price_without_any_payment()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $this->assertSame(0, JobFinalPrice::count());

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/complete")->assertOk()->assertJsonPath('data.payments.final_price.amount', 150000)
            ->assertJsonPath('data.payments.totals.reported', 0);
        $price = JobFinalPrice::first();
        $this->assertSame([$id, 150000, 1, 'agreement', 'current', 1], [$price->service_request_id, $price->amount_ugx, $price->revision_no, $price->source, $price->status, $price->current_slot]);
        $this->assertSame(JobPriceAgreement::where('agreed_slot', 1)->value('id'), $price->agreement_id);
        $this->assertSame(['final_price_set'], JobPaymentEvent::pluck('event')->all());

        // Completion implies no payment: nothing was reported or confirmed, and nothing is owed
        $this->assertSame(0, JobPaymentReport::count());
        $this->assertSame(0, $this->totals($customer, $id)['confirmed']);
        $this->assertSame(150000, $this->totals($customer, $id)['remaining']);
    }

    public function test_an_accepted_amendment_moves_the_final_price_to_a_new_version_and_keeps_the_old_one()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $first = $this->report($provider->owner, $id, ['amount' => 50000])->assertCreated()->json('data.payments.reports.0.id');
        $oldFinal = JobFinalPrice::first()->id;

        // An amendment the customer has not accepted changes nothing
        $this->signIn($provider->owner);
        $v2 = $this->postJson("/api/v1/me/received-requests/{$id}/agreements", ['amount' => 175000, 'scope' => 'Replace the pipes, a tap and a new drain.'])->assertCreated()->json('data.pricing.open.id');
        $this->assertSame(150000, $this->totals($customer, $id)['remaining']);
        $this->assertSame(1, JobFinalPrice::count());

        // Accepted: a new final price version, the old one is superseded and still on record
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v2}/accept", ['amount' => 175000])->assertOk()->assertJsonPath('data.payments.final_price.amount', 175000)->assertJsonPath('data.payments.final_price.revision_no', 2);
        $this->assertSame(['superseded', 'current'], JobFinalPrice::orderBy('revision_no')->pluck('status')->all());
        $this->assertSame([150000, 175000], JobFinalPrice::orderBy('revision_no')->pluck('amount_ugx')->all());
        $this->assertSame(1, JobFinalPrice::whereNotNull('current_slot')->count());
        // The earlier agreed price is preserved too, and the earlier report still points at the price it was made against
        $this->assertSame(150000, JobPriceAgreement::where('revision_no', 1)->value('amount_ugx'));
        $this->assertSame($oldFinal, JobPaymentReport::find($first)->final_price_id);
        $this->assertSame(['final_price_set', 'final_price_superseded', 'final_price_set'], JobPaymentEvent::whereNull('payment_id')->pluck('event')->all());
        $this->assertSame(175000, $this->totals($customer, $id)['remaining']);
    }

    public function test_a_final_price_is_not_changed_by_a_proposal_that_is_not_accepted_and_nobody_can_edit_it()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $this->report($provider->owner, $id)->assertCreated();
        $price = JobFinalPrice::first();
        foreach (['amount_ugx' => 1000, 'agreement_id' => 99, 'revision_no' => 7, 'service_request_id' => 99, 'source' => 'x'] as $field => $value) {
            try {
                $price->forceFill([$field => $value])->save();
                $this->fail("{$field} must not be changeable");
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be changed', $e->getMessage());
            }
            $price = JobFinalPrice::first();
        }
        try {
            $price->delete();
            $this->fail('A final price must not be deletable');
        } catch (LogicException $e) {
            $this->assertTrue(true);
        }
        $this->assertSame(150000, JobFinalPrice::first()->amount_ugx);
    }

    /* ---------------- Part payments and totals ---------------- */

    public function test_part_payments_are_totalled_by_the_server_and_only_confirmed_ones_count_as_confirmed()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $a = $this->report($provider->owner, $id, ['amount' => 50000])->json('data.payments.reports.0.id');
        $b = $this->report($provider->owner, $id, ['amount' => 40000, 'method' => 'mobile_money'])->assertCreated()->json('data.payments.reports.1.id');
        $c = $this->report($provider->owner, $id, ['amount' => 30000, 'method' => 'bank_transfer'])->assertCreated()->json('data.payments.reports.2.id');

        $this->assertSame(['reported' => 120000, 'confirmed' => 0, 'pending' => 120000, 'disputed' => 0, 'remaining' => 150000, 'over_by' => 0], $this->totals($customer, $id));

        $this->confirm($customer, $id, $a, 50000)->assertOk();
        $this->dispute($customer, $id, $b, ['note' => 'I paid 30,000, not 40,000.'])->assertOk();
        $this->assertSame(['reported' => 120000, 'confirmed' => 50000, 'pending' => 30000, 'disputed' => 40000, 'remaining' => 100000, 'over_by' => 0], $this->totals($customer, $id));

        // A withdrawn report counts for nothing; a disputed or waiting one is never counted as confirmed
        $this->cancel($provider->owner, $id, $c)->assertOk();
        $this->assertSame(['reported' => 90000, 'confirmed' => 50000, 'pending' => 0, 'disputed' => 40000, 'remaining' => 100000, 'over_by' => 0], $this->totals($customer, $id));

        // The provider sees the same numbers
        $this->signIn($provider->owner);
        $this->assertSame(100000, $this->getJson('/api/v1/me/received-requests')->json('data.0.payments.totals.remaining'));
        $this->assertTrue(is_int($this->getJson('/api/v1/me/received-requests')->json('data.0.payments.totals.confirmed')));
    }

    public function test_reporting_more_than_is_left_to_pay_is_refused_not_quietly_accepted()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $this->report($provider->owner, $id, ['amount' => 100000])->assertCreated();

        $res = $this->report($provider->owner, $id, ['amount' => 60000, 'method' => 'cash'])->assertStatus(422)->assertJson(['code' => 'exceeds_final_price']);
        $this->assertStringContainsString('50,000', $res->json('message'));
        $this->assertSame(1, JobPaymentReport::count());
        $this->report($provider->owner, $id, ['amount' => 50000, 'method' => 'bank_transfer'])->assertCreated();
        $this->report($provider->owner, $id, ['amount' => 500, 'method' => 'other'])->assertStatus(422)->assertJson(['code' => 'exceeds_final_price']);

        // Taking a waiting one back frees that amount to be reported again, correctly
        $first = JobPaymentReport::orderBy('id')->first()->id;
        $this->cancel($provider->owner, $id, $first)->assertOk();
        $this->report($provider->owner, $id, ['amount' => 100000, 'method' => 'cash', 'corrects' => $first])->assertCreated();
    }

    public function test_confirmed_payments_above_a_lowered_price_are_shown_as_over_not_hidden()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $a = $this->report($provider->owner, $id, ['amount' => 140000])->json('data.payments.reports.0.id');
        $this->confirm($customer, $id, $a, 140000)->assertOk();

        // The customer accepts a lower price: the confirmed payments now exceed it, and that is said plainly
        $this->signIn($provider->owner);
        $v2 = $this->postJson("/api/v1/me/received-requests/{$id}/agreements", ['amount' => 120000, 'scope' => 'Replace the pipes only, no tap.'])->assertCreated()->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v2}/accept", ['amount' => 120000])->assertOk();
        $totals = $this->totals($customer, $id);
        $this->assertSame([0, 20000], [$totals['remaining'], $totals['over_by']]);
        // ...and nothing more can be reported against it
        $this->report($provider->owner, $id, ['amount' => 1000])->assertStatus(422)->assertJson(['code' => 'exceeds_final_price']);
    }

    /* ---------------- The customer's decision ---------------- */

    public function test_the_customer_confirms_a_payment_and_the_provider_business_is_told()
    {
        [$provider, $customer, $id] = $this->priced();
        $manager = User::factory()->create();
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        $payment = $this->report($provider->owner, $id)->json('data.payments.reports.0.id');

        $this->confirm($customer, $id, $payment, 50000)->assertOk()
            ->assertJsonPath('data.payments.reports.0.status', 'confirmed')
            ->assertJsonPath('data.payments.totals.confirmed', 50000)
            ->assertJsonPath('data.payments.totals.remaining', 100000);

        $r = JobPaymentReport::find($payment);
        $this->assertSame([JobPaymentReport::CONFIRMED, $customer->id], [$r->status, $r->responded_by_user_id]);
        $this->assertNotNull($r->responded_at);
        $this->assertSame(['reported', 'confirmed'], JobPaymentEvent::where('payment_id', $payment)->orderBy('id')->pluck('event')->all());
        $this->assertTrue(AuditLog::where('action', 'marketplace.payment_confirmed')->where('actor_id', $customer->id)->exists());
        $this->assertSame(1, $this->notifications($provider->owner, 'Payment confirmed')->count());
        $this->assertSame(1, $this->notifications($manager, 'Payment confirmed')->count());
        $this->assertSame(0, $this->notifications($customer, 'Payment confirmed')->count());
        $this->assertSame($customer->id, (int) $this->notifications($provider->owner, 'Payment confirmed')->first()->actor_id);
        $this->assertStringNotContainsString('50', $this->notifications($provider->owner, 'Payment confirmed')->first()->body);

        // Confirming does not touch the job, and creates no commission or debt
        $this->assertSame('in_progress', ServiceRequest::find($id)->status);
    }

    public function test_confirming_needs_the_amount_being_looked_at_and_it_must_match()
    {
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->report($provider->owner, $id)->json('data.payments.reports.0.id');

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", [])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->confirm($customer, $id, $payment, 40000)->assertStatus(409)->assertJson(['code' => 'stale_report']);
        $this->confirm($customer, $id, $payment, '50000.5')->assertStatus(422);
        $this->assertSame(JobPaymentReport::PENDING, JobPaymentReport::find($payment)->status);
    }

    public function test_confirming_twice_changes_and_notifies_nothing_more()
    {
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->report($provider->owner, $id)->json('data.payments.reports.0.id');

        $this->confirm($customer, $id, $payment, 50000)->assertOk();
        $this->confirm($customer, $id, $payment, 50000)->assertOk()->assertJsonPath('data.payments.reports.0.status', 'confirmed');
        $this->confirm($customer, $id, $payment, 50000)->assertOk();
        $this->assertSame(['reported', 'confirmed'], JobPaymentEvent::where('payment_id', $payment)->pluck('event')->all());
        $this->assertSame(1, $this->notifications($provider->owner, 'Payment confirmed')->count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.payment_confirmed')->count());
    }

    public function test_a_payment_cannot_be_confirmed_and_disputed_both_and_a_dispute_stays()
    {
        [$provider, $customer, $id] = $this->priced();
        $a = $this->report($provider->owner, $id, ['amount' => 50000])->json('data.payments.reports.0.id');
        $b = $this->report($provider->owner, $id, ['amount' => 40000])->json('data.payments.reports.1.id');

        // Confirmed first: it can't then be disputed
        $this->confirm($customer, $id, $a, 50000)->assertOk();
        $this->dispute($customer, $id, $a)->assertStatus(409)->assertJson(['code' => 'already_decided']);
        $this->assertSame(JobPaymentReport::CONFIRMED, JobPaymentReport::find($a)->status);

        // Disputed first: it can't then be confirmed (it stays disputed), and doing it again changes nothing
        $this->dispute($customer, $id, $b, ['note' => 'That is not what I paid.'])->assertOk()
            ->assertJsonPath('data.payments.reports.1.status', 'disputed')->assertJsonPath('data.payments.reports.1.response_note', 'That is not what I paid.');
        $this->dispute($customer, $id, $b, ['note' => 'Changed my mind'])->assertOk();
        $this->assertSame('That is not what I paid.', JobPaymentReport::find($b)->response_note);
        $this->confirm($customer, $id, $b, 40000)->assertStatus(409)->assertJson(['code' => 'already_decided']);
        $this->assertSame(JobPaymentReport::DISPUTED, JobPaymentReport::find($b)->status);
        $this->assertSame(1, $this->notifications($provider->owner, 'Payment questioned')->count());
        $this->assertSame(1, JobPaymentEvent::where('payment_id', $b)->where('event', 'disputed')->count());
        $this->assertSame(0, $this->totals($customer, $id)['confirmed'] - 50000);
    }

    public function test_nobody_but_the_customer_of_the_request_can_confirm_or_dispute()
    {
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->report($provider->owner, $id)->json('data.payments.reports.0.id');
        $stranger = User::factory()->create();
        $otherProvider = $this->provider();

        foreach ([$stranger, $otherProvider->owner, $provider->owner] as $outsider) {
            $this->confirm($outsider, $id, $payment, 50000)->assertNotFound();
            $this->dispute($outsider, $id, $payment)->assertNotFound();
            $this->signIn($outsider);
            $this->getJson("/api/v1/me/requests/{$id}/payments")->assertNotFound();
        }
        $this->assertSame(JobPaymentReport::PENDING, JobPaymentReport::find($payment)->status);

        // A report of one job can't be used through another job of the same customer
        $other = $this->request($provider, $customer, ['description' => 'A second job: the bath tap is broken.']);
        $this->confirm($customer, $other, $payment, 50000)->assertNotFound();
        $this->assertSame(JobPaymentReport::PENDING, JobPaymentReport::find($payment)->status);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json'])->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => 50000])->assertStatus(401);
    }

    public function test_the_customer_can_still_answer_after_the_job_was_cancelled_and_the_history_stays()
    {
        [$provider, $customer, $id] = $this->priced();
        $a = $this->report($provider->owner, $id, ['amount' => 50000])->json('data.payments.reports.0.id');
        $b = $this->report($provider->owner, $id, ['amount' => 40000])->json('data.payments.reports.1.id');

        ServiceRequest::whereKey($id)->update(['status' => 'cancelled']);
        $this->confirm($customer, $id, $a, 50000)->assertOk();
        $this->dispute($customer, $id, $b)->assertOk();
        // The records are all still there, and no new one can be added
        $this->assertSame(2, JobPaymentReport::count());
        $this->report($provider->owner, $id, ['amount' => 5000, 'method' => 'other'])->assertStatus(409);
        $this->signIn($customer);
        $this->assertCount(2, $this->getJson("/api/v1/me/requests/{$id}/payments")->json('data.reports'));
    }

    /* ---------------- Withdrawing and correcting ---------------- */

    public function test_the_provider_withdraws_a_report_with_a_reason_and_reports_a_corrected_one()
    {
        [$provider, $customer, $id] = $this->priced();
        $wrong = $this->report($provider->owner, $id, ['amount' => 60000])->json('data.payments.reports.0.id');

        $this->cancel($provider->owner, $id, $wrong, [])->assertStatus(422);
        $this->cancel($provider->owner, $id, $wrong, ['reason' => 'ab'])->assertStatus(422);
        $this->assertSame(JobPaymentReport::PENDING, JobPaymentReport::find($wrong)->status);

        $this->cancel($provider->owner, $id, $wrong, ['reason' => 'I entered the wrong amount.'])->assertOk()
            ->assertJsonPath('data.payments.reports.0.status', 'cancelled')->assertJsonPath('data.payments.reports.0.cancel_reason', 'I entered the wrong amount.');
        $this->cancel($provider->owner, $id, $wrong, ['reason' => 'Again'])->assertOk();
        $r = JobPaymentReport::find($wrong);
        $this->assertSame([JobPaymentReport::CANCELLED, $provider->owner_id, 60000], [$r->status, $r->cancelled_by_user_id, $r->amount_ugx]);
        $this->assertSame(1, JobPaymentEvent::where('payment_id', $wrong)->where('event', 'cancelled')->count());
        $this->assertSame(1, $this->notifications($customer, 'Payment report taken back')->count());

        // The corrected report points back to the withdrawn one; both stay on record
        $this->report($provider->owner, $id, ['amount' => 50000, 'corrects' => $wrong])->assertCreated()->assertJsonPath('data.payments.reports.1.corrects_id', $wrong);
        $this->signIn($customer);
        $this->assertSame(['cancelled', 'pending_confirmation'], array_column($this->getJson("/api/v1/me/requests/{$id}/payments")->json('data.reports'), 'status'));
        $this->assertSame(['reported', 'cancelled'], JobPaymentEvent::where('payment_id', $wrong)->orderBy('id')->pluck('event')->all());

        // A report that was not withdrawn can't be named as the one corrected
        $live = JobPaymentReport::orderByDesc('id')->first()->id;
        $this->report($provider->owner, $id, ['amount' => 5000, 'method' => 'other', 'corrects' => $live])->assertStatus(422)->assertJson(['code' => 'corrects_invalid']);
    }

    public function test_a_disputed_report_can_be_withdrawn_but_a_confirmed_one_is_not_the_providers_to_take_back()
    {
        [$provider, $customer, $id] = $this->priced();
        $confirmed = $this->report($provider->owner, $id, ['amount' => 50000])->json('data.payments.reports.0.id');
        $disputed = $this->report($provider->owner, $id, ['amount' => 40000])->json('data.payments.reports.1.id');
        $this->confirm($customer, $id, $confirmed, 50000)->assertOk();
        $this->dispute($customer, $id, $disputed, ['note' => 'Not right'])->assertOk();

        $this->cancel($provider->owner, $id, $confirmed)->assertStatus(409)->assertJson(['code' => 'already_confirmed']);
        $this->assertSame(JobPaymentReport::CONFIRMED, JobPaymentReport::find($confirmed)->status);

        // Withdrawing a disputed claim keeps the customer's note on the record
        $this->cancel($provider->owner, $id, $disputed, ['reason' => 'The customer was right.'])->assertOk();
        $r = JobPaymentReport::find($disputed);
        $this->assertSame([JobPaymentReport::CANCELLED, 'Not right', $customer->id], [$r->status, $r->response_note, $r->responded_by_user_id]);
        $this->assertSame(['reported', 'disputed', 'cancelled'], JobPaymentEvent::where('payment_id', $disputed)->orderBy('id')->pluck('event')->all());

        // A customer can't answer a report that was taken back
        $this->confirm($customer, $id, $disputed, 40000)->assertStatus(409)->assertJson(['code' => 'report_cancelled']);

        // Only the owner or a manager of that business, and nobody else
        $member = User::factory()->create();
        $provider->members()->create(['user_id' => $member->id, 'role' => ProviderMember::ROLE_MEMBER]);
        $pending = $this->report($provider->owner, $id, ['amount' => 20000])->json('data.payments.reports.2.id');
        $this->cancel($member, $id, $pending)->assertStatus(403);
        $this->cancel($customer, $id, $pending)->assertNotFound();
        $this->cancel($this->provider()->owner, $id, $pending)->assertNotFound();
        $this->assertSame(JobPaymentReport::PENDING, JobPaymentReport::find($pending)->status);
    }

    public function test_no_administrator_can_resolve_or_change_a_payment_and_nothing_automatic_confirms_one()
    {
        [$provider, $customer, $id] = $this->priced();
        $this->report($provider->owner, $id)->assertCreated();
        $payment = JobPaymentReport::first()->id;
        $admin = $this->userWithRoles(['finance']);
        $this->signIn($admin);
        // There is no admin way to confirm, dispute, cancel or resolve
        $this->postJson("/api/v1/admin/requests/{$id}/payments/{$payment}/resolve")->assertNotFound();
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => 50000])->assertNotFound();
        $this->postJson("/api/v1/me/received-requests/{$id}/payments/{$payment}/cancel", ['reason' => 'Admin override'])->assertNotFound();

        // Time passing, and the job finishing or being cancelled, confirm nothing
        $this->travel(60)->days();
        $this->artisan('workmate:expire-price-proposals')->assertExitCode(0);
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/complete")->assertOk();
        $this->assertSame(JobPaymentReport::PENDING, JobPaymentReport::find($payment)->status);
        $this->assertSame(0, $this->totals($customer, $id)['confirmed']);
    }

    /* ---------------- The job's own lifecycle ---------------- */

    public function test_reporting_or_confirming_never_changes_the_job_and_finishing_never_reports_a_payment()
    {
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->report($provider->owner, $id)->json('data.payments.reports.0.id');
        $this->confirm($customer, $id, $payment, 50000)->assertOk();
        $this->assertSame('in_progress', ServiceRequest::find($id)->status);

        $second = $this->request($provider, $customer, ['description' => 'A second job: the bath tap is broken.']);
        $this->underway($second);
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$second}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(0, JobPaymentReport::where('service_request_id', $second)->count());
        // Completing does not turn a report into anything: the first job's report is as it was
        $this->assertSame(JobPaymentReport::CONFIRMED, JobPaymentReport::find($payment)->status);
    }

    public function test_the_hired_provider_of_a_posted_job_reports_and_that_customer_answers()
    {
        $this->switchOn();
        $provider = $this->provider();
        $customer = User::factory()->create();
        $this->signIn($customer);
        $job = $this->postJson('/api/v1/jobs', ['category' => 'plumbing', 'title' => 'Fix a pipe', 'description' => 'Under the sink.', 'area' => 'ntinda', 'workers_needed' => 1])->assertCreated()->json('data.id');
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/jobs/{$job}/applications", ['message' => 'I can fix this tomorrow morning.', 'start_time' => 'Tomorrow'])->assertCreated();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/jobs/{$job}/applications/".Quotation::first()->id.'/hire')->assertOk();
        $this->agree($provider, $customer, $job, 90000);

        $payment = $this->report($provider->owner, $job, ['amount' => 90000, 'method' => 'cash'])->assertCreated()->json('data.payments.reports.0.id');
        $this->signIn($customer);
        $listed = collect($this->getJson('/api/v1/me/requests')->json('data'))->firstWhere('id', $job);
        $this->assertSame(['posted', 90000, 'pending_confirmation'], [$listed['kind'], $listed['payments']['final_price']['amount'], $listed['payments']['reports'][0]['status']]);
        $this->assertStringContainsString('tab=posted', $this->notifications($customer, 'Payment reported')->first()->url);
        $this->confirm($customer, $job, $payment, 90000)->assertOk()->assertJsonPath('data.payments.totals.remaining', 0);
    }

    /* ---------------- The history ---------------- */

    public function test_each_side_reads_the_whole_history_and_only_finance_administrators_read_it_without_changing_it()
    {
        [$provider, $customer, $id] = $this->priced();
        $a = $this->report($provider->owner, $id, ['amount' => 50000, 'reference' => 'MP.001'])->json('data.payments.reports.0.id');
        $this->confirm($customer, $id, $a, 50000)->assertOk();

        $this->signIn($customer);
        $history = $this->getJson("/api/v1/me/requests/{$id}/payments")->assertOk()->json('data');
        $this->assertSame([150000, 150000], [$history['agreed_amount'], $history['final_prices'][0]['amount']]);
        $this->assertSame(['final_price_set', 'reported', 'confirmed'], array_column($history['events'], 'event'));
        // The price was locked by the provider's first report; then the report; then the customer's confirmation
        $this->assertSame(['provider', 'provider', 'customer'], array_column($history['events'], 'by'));
        $this->assertSame(50000, $history['totals']['confirmed']);

        $this->signIn($provider->owner);
        $this->assertSame($history['events'], $this->getJson("/api/v1/me/received-requests/{$id}/payments")->assertOk()->json('data.events'));

        $this->signIn($this->userWithRoles(['finance']));
        $this->getJson("/api/v1/admin/requests/{$id}/payments")->assertOk()->assertJsonPath('data.reports.0.reference', 'MP.001');
        $this->signIn($this->userWithRoles(['support']));
        $this->getJson("/api/v1/admin/requests/{$id}/payments")->assertStatus(403);
        $this->signIn($customer);
        $this->getJson("/api/v1/admin/requests/{$id}/payments")->assertStatus(403);
    }

    public function test_one_requests_payments_never_appear_on_another_and_lists_carry_only_the_signed_in_sides()
    {
        [$provider, $customer, $id] = $this->priced();
        $this->report($provider->owner, $id)->assertCreated();
        $stranger = User::factory()->create();
        $other = $this->provider();

        $this->signIn($stranger);
        $this->assertSame([], $this->getJson('/api/v1/me/requests')->json('data'));
        $this->signIn($other->owner);
        $this->assertSame([], $this->getJson('/api/v1/me/received-requests')->json('data'));
        $this->getJson("/api/v1/me/received-requests/{$id}/payments")->assertNotFound();

        $second = $this->request($provider, $customer, ['description' => 'A second job: the bath tap is broken.']);
        $this->signIn($customer);
        $mine = collect($this->getJson('/api/v1/me/requests')->json('data'))->keyBy('id');
        $this->assertCount(1, $mine[$id]['payments']['reports']);
        $this->assertCount(0, $mine[$second]['payments']['reports']);
    }

    public function test_payment_records_and_their_history_can_never_be_changed_or_removed()
    {
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->report($provider->owner, $id, ['reference' => 'MP.001', 'note' => 'Deposit'])->json('data.payments.reports.0.id');
        $this->confirm($customer, $id, $payment, 50000)->assertOk();

        $report = JobPaymentReport::find($payment);
        foreach (['amount_ugx' => 1000, 'method' => 'other', 'paid_on' => '2020-01-01', 'reference' => 'X', 'note' => 'Edited', 'service_request_id' => 99,
            'customer_id' => 99, 'reported_by_user_id' => 99, 'corrects_id' => 5, 'final_price_id' => 99, 'client_token' => 'x'] as $field => $value) {
            try {
                $report->forceFill([$field => $value])->save();
                $this->fail("{$field} must not be changeable");
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be changed', $e->getMessage());
            }
            $report = JobPaymentReport::find($payment);
        }
        foreach ([$report, JobPaymentEvent::first()] as $row) {
            try {
                $row->delete();
                $this->fail('A payment record must not be deletable');
            } catch (LogicException $e) {
                $this->assertTrue(true);
            }
        }
        try {
            $event = JobPaymentEvent::first();
            $event->forceFill(['amount_ugx' => 1])->save();
            $this->fail('History must not be changeable');
        } catch (LogicException $e) {
            $this->assertTrue(true);
        }
        $this->assertSame(50000, JobPaymentReport::find($payment)->amount_ugx);
        $this->assertSame(50000, (int) JobPaymentReport::find($payment)->getRawOriginal('amount_ugx'));
    }

    /* ---------------- Final-price integrity: amendments, finished jobs and consent ---------------- */

    /** The provider proposes an amendment; returns its version id */
    private function amend(ProviderProfile $provider, int $id, int $amount)
    {
        $this->signIn($provider->owner);

        return $this->postJson("/api/v1/me/received-requests/{$id}/agreements", ['amount' => $amount, 'scope' => 'The same job with the changed scope of work.']);
    }

    public function test_a_finished_job_cannot_acquire_a_new_agreed_price_and_keeps_the_one_it_had()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $amendment = $this->amend($provider, $id, 175000)->assertCreated()->json('data.pricing.open.id');

        // Finishing the job closes the amendment that was still being talked about, and locks the agreed price
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/complete")->assertOk()->assertJsonPath('data.payments.final_price.amount', 150000);
        $this->assertSame(['cancelled', 'job_completed'], [JobPriceAgreement::find($amendment)->status, JobPriceAgreement::find($amendment)->closed_reason]);

        // It can't be accepted afterwards, and nothing new can be proposed
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$amendment}/accept", ['amount' => 175000])->assertStatus(409);
        $this->amend($provider, $id, 180000)->assertStatus(409)->assertJson(['code' => 'request_status_conflict']);

        // The agreed price and the final price are exactly what they were
        $this->assertSame([150000, 1], [JobPriceAgreement::where('agreed_slot', 1)->value('amount_ugx'), JobPriceAgreement::whereNotNull('agreed_slot')->count()]);
        $this->assertSame([150000, 1, 1], [JobFinalPrice::first()->amount_ugx, JobFinalPrice::count(), JobFinalPrice::first()->revision_no]);
    }

    public function test_a_cancelled_job_cannot_acquire_a_new_agreed_price_and_keeps_the_one_it_had()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        ServiceRequest::whereKey($id)->update(['status' => 'scheduled', 'scheduled_start_at' => now()->addDay(), 'scheduled_end_at' => now()->addDay()->addHours(2)]);
        $amendment = $this->amend($provider, $id, 175000)->assertCreated()->json('data.pricing.open.id');

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/cancel", ['reason' => 'No longer needed'])->assertOk();
        $this->assertSame(['cancelled', 'request_cancelled'], [JobPriceAgreement::find($amendment)->status, JobPriceAgreement::find($amendment)->closed_reason]);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$amendment}/accept", ['amount' => 175000])->assertStatus(409);
        $this->amend($provider, $id, 180000)->assertStatus(409);
        $this->assertSame(150000, JobPriceAgreement::where('agreed_slot', 1)->value('amount_ugx'));
        // A cancelled job never acquires a final price, and earns no new payment report
        $this->assertSame(0, JobFinalPrice::count());
        $this->report($provider->owner, $id, ['amount' => 5000])->assertStatus(409);
    }

    public function test_even_if_a_job_were_marked_finished_by_some_other_route_an_open_price_still_cannot_be_accepted()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $amendment = $this->amend($provider, $id, 175000)->assertCreated()->json('data.pricing.open.id');

        // The status is changed behind the application's back, so the proposal was never closed
        ServiceRequest::whereKey($id)->update(['status' => 'completed']);
        $this->assertSame('pending_customer', JobPriceAgreement::find($amendment)->status);

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$amendment}/accept", ['amount' => 175000])->assertStatus(409)->assertJson(['code' => 'request_status_conflict']);
        $this->assertSame(150000, JobPriceAgreement::where('agreed_slot', 1)->value('amount_ugx'));
        $this->assertSame(0, JobFinalPrice::count());
    }

    public function test_a_confirmed_payment_is_never_modified_by_a_later_price_amendment()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $payment = $this->report($provider->owner, $id, ['amount' => 140000])->json('data.payments.reports.0.id');
        $this->confirm($customer, $id, $payment, 140000)->assertOk();
        $before = JobPaymentReport::find($payment)->getAttributes();
        $eventsBefore = JobPaymentEvent::where('payment_id', $payment)->pluck('id')->all();

        // A lower price is accepted: the confirmed payment is exactly as it was, and now plainly exceeds the price
        $v2 = $this->amend($provider, $id, 120000)->assertCreated()->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v2}/accept", ['amount' => 120000])->assertOk();

        $this->assertSame($before, JobPaymentReport::find($payment)->getAttributes());
        $this->assertSame($eventsBefore, JobPaymentEvent::where('payment_id', $payment)->pluck('id')->all());
        $this->assertSame([140000, 'confirmed'], [JobPaymentReport::find($payment)->amount_ugx, JobPaymentReport::find($payment)->status]);
        $totals = $this->totals($customer, $id);
        $this->assertSame([140000, 0, 20000], [$totals['confirmed'], $totals['remaining'], $totals['over_by']]);
        // The report still points at the final price version it was made against
        $this->assertSame(JobFinalPrice::where('revision_no', 1)->value('id'), JobPaymentReport::find($payment)->final_price_id);
    }

    public function test_a_price_amendment_cannot_bypass_the_customers_consent()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $this->report($provider->owner, $id, ['amount' => 50000])->assertCreated();
        $amendment = $this->amend($provider, $id, 200000)->assertCreated()->json('data.pricing.open.id');

        // Nobody but the customer can accept it: not the provider (any role in the business), not an administrator
        $manager = User::factory()->create();
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        foreach ([$provider->owner, $manager, $this->userWithRoles(['super_admin']), User::factory()->create()] as $actor) {
            $this->signIn($actor);
            $this->postJson("/api/v1/me/requests/{$id}/agreements/{$amendment}/accept", ['amount' => 200000])->assertNotFound();
        }
        // A wrong or stale amount is refused for the customer too
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$amendment}/accept", ['amount' => 150000])->assertStatus(409);

        // Until the customer accepts, nothing changed: the agreed and final prices are the old ones
        $this->assertSame([150000, 1, 150000], [JobPriceAgreement::where('agreed_slot', 1)->value('amount_ugx'), JobFinalPrice::count(), JobFinalPrice::first()->amount_ugx]);
        $this->assertSame(0, JobPaymentEvent::whereNull('payment_id')->where('event', 'final_price_superseded')->count());
        // More than the old price can't be reported against the amendment that was not accepted
        $this->report($provider->owner, $id, ['amount' => 120000, 'method' => 'cash'])->assertStatus(422)->assertJson(['code' => 'exceeds_final_price']);

        // The customer's explicit acceptance is what moves it
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$amendment}/accept", ['amount' => 200000])->assertOk()->assertJsonPath('data.payments.final_price.amount', 200000);
        $this->report($provider->owner, $id, ['amount' => 120000, 'method' => 'cash'])->assertCreated();
    }

    public function test_every_change_of_the_final_price_is_recorded_with_who_when_and_from_to()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $this->report($provider->owner, $id, ['amount' => 50000])->assertCreated();   // locks version 1
        $v2 = $this->amend($provider, $id, 175000)->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v2}/accept", ['amount' => 175000])->assertOk();

        $logs = AuditLog::where('action', 'marketplace.final_price_set')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([[], 150000], [$logs[0]->before ?: [], $logs[0]->after['amount_ugx']]);
        $this->assertSame([150000, 175000, 2], [$logs[1]->before['amount_ugx'], $logs[1]->after['amount_ugx'], $logs[1]->after['revision']]);
        // The second version was locked by the customer's acceptance, and says which agreement it follows
        $this->assertSame([$provider->owner_id, $customer->id], [(int) $logs[0]->actor_id, (int) $logs[1]->actor_id]);
        $this->assertSame(JobPriceAgreement::where('revision_no', 2)->value('id'), $logs[1]->after['agreement_id']);

        $events = JobPaymentEvent::whereNull('payment_id')->orderBy('id')->get();
        $this->assertSame(['final_price_set', 'final_price_superseded', 'final_price_set'], $events->pluck('event')->all());
        $this->assertSame(['provider', 'customer', 'customer'], $events->pluck('actor_role')->all());
        $this->assertSame([150000, 150000, 175000], $events->pluck('amount_ugx')->all());
    }

    public function test_the_agreed_price_history_survives_several_amendments_and_the_final_price_follows_only_accepted_ones()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $this->report($provider->owner, $id, ['amount' => 10000])->assertCreated();
        foreach ([160000, 170000] as $amount) {
            $version = $this->amend($provider, $id, $amount)->json('data.pricing.open.id');
            $this->signIn($customer);
            $this->postJson("/api/v1/me/requests/{$id}/agreements/{$version}/accept", ['amount' => $amount])->assertOk();
        }
        // An amendment turned down changes nothing
        $declined = $this->amend($provider, $id, 300000)->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$declined}/reject")->assertOk();

        $this->assertSame([150000, 160000, 170000, 300000], JobPriceAgreement::orderBy('revision_no')->pluck('amount_ugx')->all());
        $this->assertSame(['superseded', 'superseded', 'agreed', 'rejected'], JobPriceAgreement::orderBy('revision_no')->pluck('status')->all());
        $this->assertSame([150000, 160000, 170000], JobFinalPrice::orderBy('revision_no')->pluck('amount_ugx')->all());
        $this->assertSame(['superseded', 'superseded', 'current'], JobFinalPrice::orderBy('revision_no')->pluck('status')->all());
        $this->assertSame([1, 1], [JobFinalPrice::whereNotNull('current_slot')->count(), JobPriceAgreement::whereNotNull('agreed_slot')->count()]);
        // Nothing is confirmed yet, so what is left is the whole final price: the last one the customer accepted
        $this->assertSame(170000, $this->totals($customer, $id)['remaining']);
    }

    public function test_a_job_finished_while_pricing_was_off_gets_the_agreed_price_locked_by_its_first_payment_report_later()
    {
        [$provider, $customer, $id] = $this->priced(150000);
        $this->switchOn(false);
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(0, JobFinalPrice::count());

        // Pricing is switched on again: the first report locks the price the customer agreed (it could not have changed since)
        $this->switchOn();
        $this->report($provider->owner, $id, ['amount' => 150000])->assertCreated()->assertJsonPath('data.payments.final_price.amount', 150000);
        $this->assertSame([1, 150000], [JobFinalPrice::count(), JobFinalPrice::first()->amount_ugx]);
        $this->amend($provider, $id, 99000)->assertStatus(409);
    }

    /* ---------------- Scope ---------------- */

    public function test_this_phase_adds_no_commission_ledger_gateway_wallet_or_debt()
    {
        foreach (['commission_policies', 'ledger_entries', 'settlement_cycles', 'provider_subscriptions', 'wallets', 'payouts', 'job_payments'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "$table must not exist");
        }
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->report($provider->owner, $id, ['amount' => 150000])->assertCreated()->json('data.payments.reports.0.id');
        $this->confirm($customer, $id, $payment, 150000)->assertOk();
        // Everything confirmed and paid: still no amount owed to or by anyone, and the job is untouched
        $this->assertSame(['confirmed' => 150000, 'remaining' => 0, 'over_by' => 0], array_intersect_key($this->totals($customer, $id), array_flip(['confirmed', 'remaining', 'over_by'])));
        $this->assertSame('in_progress', ServiceRequest::find($id)->status);
    }
}
