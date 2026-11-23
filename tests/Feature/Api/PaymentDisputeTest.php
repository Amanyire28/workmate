<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CommissionShadowPolicy;
use App\Models\CommissionShadowSnapshot;
use App\Models\JobPaymentEvent;
use App\Models\JobPaymentReport;
use App\Models\PaymentDispute;
use App\Models\PaymentDisputeEvent;
use App\Models\PaymentDisputeEvidence;
use App\Models\PaymentDisputeMessage;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * Phase B5: payment dispute resolution. A customer's dispute opens one case; the two sides talk, attach evidence, and can only
 * settle it by BOTH agreeing; either can escalate; an administrator takes it, asks, and records a reasoned decision. Nothing
 * here collects money or creates a commission, debt or invoice, and no administrator can stand in for the customer's confirmation.
 */
class PaymentDisputeTest extends ApiTestCase
{
    /** @var User */
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->root = $this->userWithRoles(['super_admin']);
        app(PlatformSettings::class)->set('finance.pricing_enabled', true, $this->root);
    }

    /* ---------------- Helpers ---------------- */

    private function provider(): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => 'Okello Services '.$owner->id, 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    /** A job under way with a price agreed: [provider, customer, request id] */
    private function priced(int $price = 150000): array
    {
        $provider = $this->provider();
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

        return [$provider, $customer, $id];
    }

    private function reported(ProviderProfile $provider, int $id, int $amount): int
    {
        $this->signIn($provider->owner);
        $reports = $this->postJson("/api/v1/me/received-requests/{$id}/payments", ['amount' => $amount, 'method' => 'cash'])->assertCreated()->json('data.payments.reports');

        return (int) end($reports)['id'];
    }

    /** A reported payment the customer then disputed: [provider, customer, request id, payment id, dispute id] */
    private function disputed(int $amount = 100000): array
    {
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->reported($provider, $id, $amount);
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/dispute", ['note' => 'I paid less than that.'])->assertOk();
        $dispute = (int) PaymentDispute::where('payment_report_id', $payment)->value('id');

        return [$provider, $customer, $id, $payment, $dispute];
    }

    private function escalated(): array
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'no_agreement', 'note' => 'We cannot agree on the amount.'])->assertOk();

        return [$provider, $customer, $id, $payment, $d];
    }

    private function underReview(?User $admin = null): array
    {
        [$provider, $customer, $id, $payment, $d] = $this->escalated();
        $this->signIn($admin ?: $this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/review")->assertOk();

        return [$provider, $customer, $id, $payment, $d];
    }

    private function note(User $user, string $title)
    {
        return UserNotification::where('user_id', $user->id)->where('title', $title);
    }

    /* ---------------- Opening ---------------- */

    public function test_disputing_a_payment_opens_exactly_one_case_and_the_payment_stays_disputed()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();

        $this->assertSame(1, PaymentDispute::count());
        $case = PaymentDispute::find($d);
        $this->assertSame([PaymentDispute::OPEN, $payment, $id, $customer->id], [$case->status, $case->payment_report_id, $case->service_request_id, $case->customer_id]);
        $this->assertSame(JobPaymentReport::DISPUTED, JobPaymentReport::find($payment)->status);   // the one record of payment status
        $this->assertSame(['opened'], PaymentDisputeEvent::where('payment_dispute_id', $d)->pluck('event')->all());

        // The same answer again (a retry) opens nothing new
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/dispute", ['note' => 'I paid less than that.'])->assertOk();
        $this->assertSame(1, PaymentDispute::count());
        $this->assertSame(1, PaymentDisputeEvent::count());

        // Both sides see the case in their payment lists
        $this->assertSame($d, $this->getJson("/api/v1/me/requests/{$id}/payments")->json('data.reports.0.dispute.id'));
        $this->signIn($provider->owner);
        $this->assertSame($d, $this->getJson("/api/v1/me/received-requests/{$id}/payments")->json('data.reports.0.dispute.id'));
    }

    public function test_disputes_made_before_cases_existed_are_found_by_the_catch_up_without_escalation_or_notifications()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        DB::table('payment_dispute_events')->delete();
        DB::table('payment_disputes')->delete();
        UserNotification::query()->delete();
        $snap = function () use ($payment) {
            return json_encode(JobPaymentReport::find($payment)->only(['status', 'amount_ugx', 'responded_at']));
        };
        $before = $snap();

        // Looking at the queue does not go hunting (no scan on every visit)
        $this->signIn($this->root);
        $this->getJson('/api/v1/admin/disputes?scope=all')->assertOk()->assertJsonPath('data.counts.open', 0);
        $this->assertSame(0, PaymentDispute::count());

        $this->artisan('workmate:disputes-backfill')->assertExitCode(0);
        $case = PaymentDispute::where('payment_report_id', $payment)->first();
        $this->assertSame([PaymentDispute::OPEN, $customer->id, null], [$case->status, (int) $case->customer_id, $case->escalated_at]);
        $this->assertSame(['opened'], PaymentDisputeEvent::where('payment_dispute_id', $case->id)->pluck('event')->all());
        $this->assertSame(0, UserNotification::count());                       // nobody is told about history
        $this->assertSame($before, $snap());   // the report is untouched
        $this->getJson('/api/v1/admin/disputes?scope=all')->assertJsonPath('data.counts.open', 1);
        $this->getJson('/api/v1/admin/disputes')->assertJsonPath('data.items', []);   // found, but not in "with WorkMate": it was never escalated

        // Again, and again: nothing new, and a report that already has a case is never given a second
        $this->artisan('workmate:disputes-backfill')->assertExitCode(0);
        $this->artisan('workmate:disputes-backfill')->assertExitCode(0);
        $this->assertSame(1, PaymentDispute::count());
        $this->assertSame(1, PaymentDisputeEvent::count());
        // A report that is not disputed never gets one
        $this->reported($provider, $id, 20000);
        $this->artisan('workmate:disputes-backfill')->assertExitCode(0);
        $this->assertSame(1, PaymentDispute::count());
    }

    public function test_the_catch_up_is_scheduled()
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(function ($e) {
            return $e->command;
        })->implode("\n");
        $this->assertStringContainsString('workmate:disputes-backfill', $events);
    }

    /* ---------------- Who can see it ---------------- */

    public function test_only_the_customer_and_the_provider_business_can_open_the_case()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        $provider->members()->create(['user_id' => $member->id, 'role' => ProviderMember::ROLE_MEMBER]);

        foreach ([$customer, $provider->owner, $manager] as $allowed) {
            $this->signIn($allowed);
            $this->getJson("/api/v1/me/disputes/{$d}")->assertOk()->assertJsonPath('data.id', $d);
        }
        $this->signIn($customer);
        $this->getJson("/api/v1/me/disputes/{$d}")->assertJsonPath('data.you', 'customer');
        $this->signIn($manager);
        $this->getJson("/api/v1/me/disputes/{$d}")->assertJsonPath('data.you', 'provider');

        $otherProvider = $this->provider();
        foreach ([User::factory()->create(), $otherProvider->owner, $member] as $stranger) {
            $this->signIn($stranger);
            $this->getJson("/api/v1/me/disputes/{$d}")->assertNotFound();
            $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'Let me in'])->assertStatus(404);
            $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'other', 'note' => 'I want it escalated'])->assertStatus(404);
            $this->postJson("/api/v1/me/disputes/{$d}/proposal", ['outcome' => 'payment_received'])->assertStatus(404);
        }
        $this->assertSame(0, PaymentDisputeMessage::count());
        $this->assertSame(PaymentDispute::OPEN, PaymentDispute::find($d)->status);
    }

    public function test_the_case_is_not_available_while_pricing_options_are_off()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        app(PlatformSettings::class)->set('finance.pricing_enabled', false, $this->root);
        $this->signIn($customer);
        $this->getJson("/api/v1/me/disputes/{$d}")->assertStatus(409);
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'Hello'])->assertStatus(409);
        $this->assertSame(0, PaymentDisputeMessage::count());
    }

    /* ---------------- Talking and evidence ---------------- */

    public function test_the_two_sides_exchange_messages_and_the_case_moves_to_discussion()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'He paid me 100,000 in cash on Monday.', 'client_token' => 'tok-1'])->assertCreated()
            ->assertJsonPath('data.status', 'discussion')->assertJsonPath('data.messages.0.by', 'provider')->assertJsonPath('data.messages.0.mine', true);
        // A dropped connection and a retry: the same message, nothing doubled, nobody told twice
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'He paid me 100,000 in cash on Monday.', 'client_token' => 'tok-1'])->assertCreated();
        $this->assertSame(1, PaymentDisputeMessage::count());
        $this->assertSame(1, $this->note($customer, 'New message about a payment')->count());

        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'I only handed over 60,000.'])->assertCreated();
        $view = $this->getJson("/api/v1/me/disputes/{$d}")->assertOk()->json('data');
        $this->assertSame(['provider', 'customer'], array_column($view['messages'], 'by'));
        $this->assertSame([false, true], array_column($view['messages'], 'mine'));
        $this->assertSame('Aisha', $view['parties']['customer']);
        $this->assertSame(['opened', 'discussion_started', 'message', 'message'], array_column($view['timeline'], 'event'));
        $this->postJson("/api/v1/me/disputes/{$d}/messages", [])->assertStatus(422);
    }

    public function test_evidence_is_validated_stored_privately_and_served_only_to_the_two_sides()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $this->signIn($customer);

        // Not a picture or a PDF
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')]])->assertStatus(422);
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['files' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')]])->assertStatus(422);
        // Too large
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['files' => [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]])->assertStatus(422);
        // Says it is a PDF, is not
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['files' => [UploadedFile::fake()->createWithContent('fake.pdf', 'not really a pdf')]])->assertStatus(422);
        // Too many at once
        $six = array_map(function ($i) {
            return UploadedFile::fake()->image("p{$i}.jpg");
        }, range(1, 6));
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['files' => $six])->assertStatus(422);
        $this->assertSame(0, PaymentDisputeEvidence::count());
        $this->assertSame([], Storage::disk('local')->allFiles(PaymentDisputeEvidence::DIRECTORY));

        $pdf = UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $response = $this->post("/api/v1/me/disputes/{$d}/messages", ['body' => 'My mobile money receipt.', 'files' => [UploadedFile::fake()->image('screenshot.png', 40, 40), $pdf]], ['Accept' => 'application/json'])
            ->assertCreated();
        $files = $response->json('data.messages.0.files');
        $this->assertCount(2, $files);
        $this->assertSame(['image/jpeg', 'application/pdf'], array_column($files, 'mime'));
        // The answer carries a checked link, never a storage path or a public address
        $this->assertStringNotContainsString(PaymentDisputeEvidence::DIRECTORY, json_encode($response->json()));
        $this->assertStringNotContainsString('/storage/', json_encode($response->json()));
        $stored = Storage::disk('local')->allFiles(PaymentDisputeEvidence::DIRECTORY);
        $this->assertCount(2, $stored);
        $this->assertSame([], Storage::disk('public')->allFiles());   // nothing on the public disk
        foreach ($stored as $path) {
            $this->assertMatchesRegularExpression('#^dispute-evidence/[0-9a-f-]{36}\.(jpg|pdf)$#', $path);   // random name, no original name
        }

        // The two sides can open it; nobody else can
        $url = $files[0]['url'];
        $this->getJson($url)->assertOk();
        $this->signIn($provider->owner);
        $this->getJson($url)->assertOk();
        foreach ([User::factory()->create(), $this->provider()->owner] as $stranger) {
            $this->signIn($stranger);
            $this->getJson($url)->assertStatus(404);
        }
        // A file of another case, a made-up name and a path trick are all "not found"
        $this->signIn($customer);
        $this->getJson("/api/v1/me/disputes/{$d}/evidence/".str_repeat('a', 8).'-aaaa-aaaa-aaaa-'.str_repeat('a', 12))->assertStatus(404);
        $this->getJson("/api/v1/me/disputes/{$d}/evidence/..%2F..%2F.env")->assertStatus(404);
        [, , , , $other] = $this->disputed();
        $this->signIn($customer);
        $this->getJson("/api/v1/me/disputes/{$other}/evidence/".$files[0]['uuid'])->assertStatus(404);
    }

    /* ---------------- Escalation ---------------- */

    public function test_either_side_can_escalate_alone_once_with_a_reason_and_the_history_is_kept()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'Please be reasonable.'])->assertCreated();

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", [])->assertStatus(422);
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'because', 'note' => 'Nope'])->assertStatus(422);
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'no_response', 'note' => 'ok'])->assertStatus(422);   // too short
        $this->assertSame(PaymentDispute::DISCUSSION, PaymentDispute::find($d)->status);

        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'no_response', 'note' => 'The customer stopped answering me.'])->assertOk()
            ->assertJsonPath('data.status', 'escalated')->assertJsonPath('data.escalation.by', 'provider')->assertJsonPath('data.can.escalate', false);
        // Again, by either side: still one escalation, one notification
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'no_response', 'note' => 'The customer stopped answering me.'])->assertOk();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'other', 'note' => 'I also want WorkMate to look.'])->assertOk()->assertJsonPath('data.escalation.by', 'provider');

        $this->assertSame(1, PaymentDisputeEvent::where('payment_dispute_id', $d)->where('event', 'escalated')->count());
        $this->assertSame(1, $this->note($customer, 'WorkMate was asked to help')->count());
        $this->assertSame(0, $this->note($provider->owner, 'WorkMate was asked to help')->count());
        $this->assertSame(1, PaymentDispute::count());
        // What was said before is still there, and both can still add to it
        $this->assertSame(1, PaymentDisputeMessage::count());
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'More information for WorkMate.'])->assertCreated();
        $this->assertTrue(AuditLog::where('action', 'payment_dispute.escalated')->where('actor_id', $provider->owner_id)->exists());
    }

    /* ---------------- Settling it between the two sides ---------------- */

    public function test_both_sides_agreeing_that_the_payment_was_received_confirms_it_as_the_customers_own_confirmation()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal", ['outcome' => 'payment_received'])->assertOk()->assertJsonPath('data.proposal.mine', true);
        // One side alone changes nothing, and can not accept its own suggestion
        $this->assertSame(JobPaymentReport::DISPUTED, JobPaymentReport::find($payment)->status);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal/accept", ['outcome' => 'payment_received'])->assertStatus(409);
        $this->assertSame(1, $this->note($customer, 'A way to settle it was suggested')->count());

        $this->signIn($customer);
        // They must agree to exactly what was suggested
        $this->postJson("/api/v1/me/disputes/{$d}/proposal/accept", ['outcome' => 'payment_not_received'])->assertStatus(409);
        $this->assertSame(JobPaymentReport::DISPUTED, JobPaymentReport::find($payment)->status);

        $this->postJson("/api/v1/me/disputes/{$d}/proposal/accept", ['outcome' => 'payment_received'])->assertOk()->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution.type', 'agreed_received');
        $report = JobPaymentReport::find($payment);
        $this->assertSame([JobPaymentReport::CONFIRMED, $customer->id], [$report->status, (int) $report->responded_by_user_id]);
        $this->assertSame(100000, $report->amount_ugx);   // what was reported never changes
        $this->assertTrue(JobPaymentEvent::where('payment_id', $payment)->where('event', 'confirmed')->where('actor_role', 'customer')->exists());
        $this->assertFalse(PaymentDispute::find($d)->financial_correction_required);

        // Again, by either: nothing new
        $this->postJson("/api/v1/me/disputes/{$d}/proposal/accept", ['outcome' => 'payment_received'])->assertOk();
        $this->assertSame(1, JobPaymentEvent::where('payment_id', $payment)->where('event', 'confirmed')->count());
        // A closed case takes nothing more
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'Anything else?'])->assertStatus(409);
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'other', 'note' => 'Too late to ask now.'])->assertStatus(409);
    }

    public function test_both_sides_agreeing_the_payment_was_not_received_withdraws_the_report()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal", ['outcome' => 'payment_not_received'])->assertOk();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal/accept", ['outcome' => 'payment_not_received'])->assertOk()->assertJsonPath('data.resolution.effect', 'withdrawn');

        $report = JobPaymentReport::find($payment);
        $this->assertSame([JobPaymentReport::CANCELLED, $provider->owner_id], [$report->status, (int) $report->cancelled_by_user_id]);
        $this->assertSame(PaymentDispute::RESOLVED, PaymentDispute::find($d)->status);
        // A corrected report is the existing way forward
        $this->assertCount(1, JobPaymentReport::where('status', JobPaymentReport::CANCELLED)->get());
    }

    public function test_a_suggestion_can_be_declined_or_replaced_and_changes_nothing_until_agreed()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal", ['outcome' => 'payment_received'])->assertOk();
        $this->postJson("/api/v1/me/disputes/{$d}/proposal", ['outcome' => 'bogus'])->assertStatus(422);
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal/decline")->assertOk()->assertJsonPath('data.proposal', null);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal/accept", ['outcome' => 'payment_received'])->assertStatus(409);
        $this->assertSame(JobPaymentReport::DISPUTED, JobPaymentReport::find($payment)->status);
        $this->assertSame(PaymentDispute::OPEN, PaymentDispute::find($d)->status);
    }

    public function test_the_provider_taking_a_disputed_report_back_ends_its_case()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/payments/{$payment}/cancel", ['reason' => 'I made a mistake with the amount.'])->assertOk();
        $case = PaymentDispute::find($d);
        $this->assertSame([PaymentDispute::RESOLVED, 'provider_withdrew'], [$case->status, $case->resolution_type]);
        $this->assertSame(JobPaymentReport::CANCELLED, JobPaymentReport::find($payment)->status);
    }

    /* ---------------- The administrator ---------------- */

    public function test_administrators_need_the_right_permission_for_each_step()
    {
        [$provider, $customer, $id, $payment, $d] = $this->escalated();
        $finance = $this->userWithRoles(['finance']);
        $support = $this->userWithRoles(['support']);

        $this->signIn($support);
        $this->getJson('/api/v1/admin/disputes')->assertForbidden();
        $this->getJson("/api/v1/admin/disputes/{$d}")->assertForbidden();
        $this->postJson("/api/v1/admin/disputes/{$d}/review")->assertForbidden();
        $this->signIn($customer);
        $this->getJson('/api/v1/admin/disputes')->assertForbidden();   // a customer is not an administrator
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'received', 'reason' => 'I am the provider, trust me.'])->assertForbidden();

        // Finance can read and review but cannot decide; only the Super Administrator can
        $this->signIn($finance);
        $this->getJson('/api/v1/admin/disputes')->assertOk();
        $this->postJson("/api/v1/admin/disputes/{$d}/review")->assertOk();
        $this->postJson("/api/v1/admin/disputes/{$d}/info-requests", ['to' => 'both', 'message' => 'Please send your receipts.'])->assertOk();
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'insufficient_evidence', 'reason' => 'Finance cannot decide this one.'])->assertForbidden();
        $this->assertSame(PaymentDispute::UNDER_REVIEW, PaymentDispute::find($d)->status);
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'insufficient_evidence', 'reason' => 'Neither side could show proof.'])->assertOk();
    }

    public function test_the_queue_counts_and_filters_cases_and_detail_shows_the_whole_picture_without_contact_details()
    {
        [$provider, $customer, $id, $payment, $d] = $this->escalated();
        $this->disputed();   // a second case that is still between the two sides
        $this->signIn($this->root);

        $queue = $this->getJson('/api/v1/admin/disputes')->assertOk()->json('data');
        $this->assertSame(['open' => 1, 'escalated' => 1, 'under_review' => 0, 'resolved' => 0], $queue['counts']);
        $this->assertSame([$d], array_column($queue['items'], 'id'));   // only what reached WorkMate, by default
        $this->assertCount(2, $this->getJson('/api/v1/admin/disputes?scope=all')->json('data.items'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/disputes?status=open')->json('data.items'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/disputes?status=escalated')->json('data.items'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/disputes?status=resolved')->json('data.items'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/disputes?q=Aisha')->json('data.items'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/disputes?q=Nobody')->json('data.items'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/disputes?from='.now()->addDay()->toDateString())->json('data.items'));
        $this->getJson('/api/v1/admin/disputes?status=bogus')->assertStatus(422);

        $detail = $this->getJson("/api/v1/admin/disputes/{$d}")->assertOk()->json('data');
        $this->assertSame(100000, $detail['payment']['amount']);
        $this->assertSame(150000, $detail['job']['agreed_amount']);
        $this->assertSame(150000, $detail['job']['final_price']);
        $this->assertSame('escalated', $detail['status']);
        $this->assertSame('no_agreement', $detail['escalation']['reason']);
        $this->assertSame(100000, $detail['payments']['reports'][0]['amount']);
        $json = json_encode($detail);
        foreach ([$customer->email, $provider->owner->email, $customer->phone, 'password'] as $private) {
            if ($private) {
                $this->assertStringNotContainsString((string) $private, $json);
            }
        }
    }

    public function test_a_case_is_reviewed_before_it_is_decided_and_review_cannot_start_twice()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();   // not escalated
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/review")->assertStatus(409);
        $this->postJson("/api/v1/admin/disputes/{$d}/info-requests", ['to' => 'both', 'message' => 'Too early to ask this.'])->assertStatus(409);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'insufficient_evidence', 'reason' => 'Skipping the review stage.'])->assertStatus(409);
        $this->assertSame(PaymentDispute::OPEN, PaymentDispute::find($d)->status);

        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'no_agreement', 'note' => 'We cannot agree on it.'])->assertOk();
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'insufficient_evidence', 'reason' => 'Skipping the review stage.'])->assertStatus(409);   // escalated, not yet reviewed
        $this->postJson("/api/v1/admin/disputes/{$d}/review")->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->postJson("/api/v1/admin/disputes/{$d}/review")->assertOk();
        $this->assertSame(1, PaymentDisputeEvent::where('payment_dispute_id', $d)->where('event', 'review_started')->count());
        $this->assertSame(1, $this->note($customer, 'WorkMate is reviewing')->count());
        $this->assertSame($this->root->id, (int) PaymentDispute::find($d)->reviewer_id);
    }

    public function test_the_administrator_can_ask_for_more_and_only_the_asked_side_is_told()
    {
        [$provider, $customer, $id, $payment, $d] = $this->underReview();
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/info-requests", ['to' => 'nobody', 'message' => 'Please send your receipts.'])->assertStatus(422);
        $this->postJson("/api/v1/admin/disputes/{$d}/info-requests", ['to' => 'provider', 'message' => 'Hmm', 'client_token' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/admin/disputes/{$d}/info-requests", ['to' => 'provider', 'message' => 'Please send the mobile money receipt.', 'client_token' => 'ask-1'])->assertOk();
        $this->postJson("/api/v1/admin/disputes/{$d}/info-requests", ['to' => 'provider', 'message' => 'Please send the mobile money receipt.', 'client_token' => 'ask-1'])->assertOk();

        $this->assertSame(1, PaymentDisputeMessage::where('kind', 'info_request')->count());
        $this->assertSame(1, $this->note($provider->owner, 'WorkMate needs more information')->count());
        $this->assertSame(0, $this->note($customer, 'WorkMate needs more information')->count());
        $this->assertSame('under_review', PaymentDispute::find($d)->status);   // the stage does not move

        $this->signIn($provider->owner);
        $view = $this->getJson("/api/v1/me/disputes/{$d}")->json('data');
        $this->assertSame(['info_request'], array_column($view['messages'], 'kind'));
        $this->assertSame('WorkMate', $view['messages'][0]['name']);
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'Here is the receipt.', 'files' => []])->assertCreated();
    }

    public function test_deciding_payment_not_established_withdraws_the_report_in_an_attributed_audited_step()
    {
        [$provider, $customer, $id, $payment, $d] = $this->underReview();
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'not_established'])->assertStatus(422);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'not_established', 'reason' => 'Too short'])->assertStatus(422);   // 9 characters
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'banana', 'reason' => 'A long enough reason.'])->assertStatus(422);
        $this->assertSame(PaymentDispute::UNDER_REVIEW, PaymentDispute::find($d)->status);

        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'not_established', 'reason' => 'The provider could not show any receipt or reference.'])->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution.type', 'not_established')->assertJsonPath('data.resolution.effect', 'withdrawn');

        $report = JobPaymentReport::find($payment);
        $this->assertSame([JobPaymentReport::CANCELLED, $this->root->id, 100000], [$report->status, (int) $report->cancelled_by_user_id, $report->amount_ugx]);
        $event = JobPaymentEvent::where('payment_id', $payment)->where('event', 'cancelled')->first();
        $this->assertSame(['admin', 'disputed', 'cancelled'], [$event->actor_role, $event->from_status, $event->to_status]);
        $this->assertTrue(AuditLog::where('action', 'payment_dispute.decided')->where('actor_id', $this->root->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'marketplace.payment_cancelled')->where('actor_id', $this->root->id)->exists());
        $this->assertFalse(PaymentDispute::find($d)->financial_correction_required);

        // Both sides are told, and read the reason
        $this->assertSame(1, $this->note($customer, 'WorkMate made a decision')->count());
        $this->assertSame(1, $this->note($provider->owner, 'WorkMate made a decision')->count());
        $this->signIn($customer);
        $this->assertSame('The provider could not show any receipt or reference.', $this->getJson("/api/v1/me/disputes/{$d}")->json('data.resolution.reason'));
    }

    public function test_an_administrator_cannot_confirm_a_payment_on_the_customers_behalf()
    {
        foreach ([['received', null], ['partial', 60000]] as [$outcome, $amount]) {
            [$provider, $customer, $id, $payment, $d] = $this->underReview();
            $this->signIn($this->root);
            $body = ['outcome' => $outcome, 'reason' => 'WorkMate found the evidence convincing.'] + ($amount ? ['amount' => $amount] : []);
            $this->postJson("/api/v1/admin/disputes/{$d}/decision", $body)->assertOk()->assertJsonPath('data.resolution.financial_correction_required', true)
                ->assertJsonPath('data.resolution.effect', 'none');

            $report = JobPaymentReport::find($payment);
            $this->assertSame(JobPaymentReport::DISPUTED, $report->status);   // not rewritten, not confirmed, nobody's confirmation invented
            $this->assertNull($report->cancelled_at);
            $this->assertSame(0, JobPaymentEvent::where('payment_id', $payment)->where('event', 'confirmed')->count());
            $this->assertTrue(PaymentDispute::find($d)->financial_correction_required);
            $this->assertSame($amount, PaymentDispute::find($d)->resolution_amount_ugx);
        }
    }

    public function test_a_partial_decision_needs_a_sensible_amount_and_insufficient_evidence_changes_nothing()
    {
        [$provider, $customer, $id, $payment, $d] = $this->underReview();
        $this->signIn($this->root);
        foreach ([null, 0, 50, 100000, 100001, 'lots', 1.5] as $bad) {
            $body = ['outcome' => 'partial', 'reason' => 'WorkMate found part of it was paid.'] + ($bad === null ? [] : ['amount' => $bad]);
            $this->postJson("/api/v1/admin/disputes/{$d}/decision", $body)->assertStatus(422);
        }
        $this->assertSame(PaymentDispute::UNDER_REVIEW, PaymentDispute::find($d)->status);

        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'insufficient_evidence', 'reason' => 'Neither side could show proof either way.'])->assertOk()
            ->assertJsonPath('data.resolution.financial_correction_required', false)->assertJsonPath('data.resolution.effect', 'none');
        $this->assertSame(JobPaymentReport::DISPUTED, JobPaymentReport::find($payment)->status);
    }

    public function test_two_administrators_deciding_the_same_case_cannot_make_conflicting_decisions()
    {
        [$provider, $customer, $id, $payment, $d] = $this->underReview();
        $second = $this->userWithRoles(['super_admin']);

        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'not_established', 'reason' => 'No proof of payment was shown.'])->assertOk();
        // The same decision again by the same person: a retry, nothing doubled
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'not_established', 'reason' => 'No proof of payment was shown.'])->assertOk();
        // A different decision, or a different administrator: refused
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'received', 'reason' => 'Changed my mind about it.'])->assertStatus(409);
        $this->signIn($second);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'not_established', 'reason' => 'No proof of payment was shown.'])->assertStatus(409);

        $this->assertSame(1, PaymentDisputeEvent::where('payment_dispute_id', $d)->where('event', 'decided')->count());
        $this->assertSame(1, $this->note($customer, 'WorkMate made a decision')->count());
        $this->assertSame(JobPaymentReport::CANCELLED, JobPaymentReport::find($payment)->status);
        $this->assertSame('not_established', PaymentDispute::find($d)->resolution_type);
    }

    public function test_a_decided_case_blocks_a_late_agreement_and_an_agreed_case_blocks_a_late_decision()
    {
        [$provider, $customer, $id, $payment, $d] = $this->underReview();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal", ['outcome' => 'payment_received'])->assertOk();
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'not_established', 'reason' => 'WorkMate decided it was not paid.'])->assertOk();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal/accept", ['outcome' => 'payment_received'])->assertStatus(409);   // closed (and its proposal was cleared)
        $this->assertSame(JobPaymentReport::CANCELLED, JobPaymentReport::find($payment)->status);

        [$provider, $customer, $id, $payment, $d2] = $this->underReview();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d2}/proposal", ['outcome' => 'payment_received'])->assertOk();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d2}/proposal/accept", ['outcome' => 'payment_received'])->assertOk();
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d2}/decision", ['outcome' => 'not_established', 'reason' => 'Too late, the two sides agreed.'])->assertStatus(409);
        $this->assertSame(JobPaymentReport::CONFIRMED, JobPaymentReport::find($payment)->status);
    }

    public function test_admin_evidence_access_is_permission_checked_and_recorded()
    {
        [$provider, $customer, $id, $payment, $d] = $this->escalated();
        $this->signIn($customer);
        $url = $this->post("/api/v1/me/disputes/{$d}/messages", ['files' => [UploadedFile::fake()->image('proof.png', 30, 30)]], ['Accept' => 'application/json'])->assertCreated()->json('data.messages.0.files.0');
        $adminUrl = '/api/v1/admin/disputes/'.$d.'/evidence/'.$url['uuid'];

        $this->signIn($customer);
        $this->getJson($adminUrl)->assertForbidden();
        $this->signIn($this->userWithRoles(['support']));
        $this->getJson($adminUrl)->assertForbidden();
        $this->signIn($this->userWithRoles(['finance']));
        $this->getJson($adminUrl)->assertOk();
        $this->assertTrue(AuditLog::where('action', 'payment_dispute.evidence_viewed')->exists());
        $this->signIn($this->root);
        $this->getJson('/api/v1/admin/disputes/'.$d.'/evidence/'.str_repeat('b', 8).'-bbbb-bbbb-bbbb-'.str_repeat('b', 12))->assertStatus(404);
    }

    /* ---------------- Integrity and safety ---------------- */

    public function test_the_record_of_a_dispute_can_never_be_rewritten()
    {
        [$provider, $customer, $id, $payment, $d] = $this->underReview();
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'Hello.'])->assertCreated();
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'insufficient_evidence', 'reason' => 'Neither side could show proof.'])->assertOk();

        $attempts = [
            function () use ($d) {
                PaymentDispute::find($d)->forceFill(['status' => 'open'])->save();
            },
            function () use ($d) {
                PaymentDispute::find($d)->forceFill(['resolution_reason' => 'edited'])->save();
            },
            function () use ($d) {
                PaymentDispute::find($d)->delete();
            },
            function () {
                PaymentDisputeEvent::first()->forceFill(['note' => 'edited'])->save();
            },
            function () {
                PaymentDisputeEvent::first()->delete();
            },
            function () {
                PaymentDisputeMessage::first()->forceFill(['body' => 'edited'])->save();
            },
            function () {
                PaymentDisputeMessage::first()->delete();
            },
            function () use ($payment) {
                JobPaymentReport::find($payment)->forceFill(['amount_ugx' => 1])->save();
            },
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                $this->fail("Attempt {$i} changed a protected record");
            } catch (LogicException $e) {
                $this->assertTrue(true);
            }
        }
        // Even before it closes, what a case is about cannot move
        [, , , , $open] = $this->disputed();
        try {
            PaymentDispute::find($open)->forceFill(['payment_report_id' => 1])->save();
            $this->fail('A case was pointed at another payment');
        } catch (LogicException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_notifications_carry_no_amounts_no_message_text_and_point_to_the_case()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed(123456);
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'Secret detail: reference ABC123456 for 123,456 shillings.'])->assertCreated();
        $this->postJson("/api/v1/me/disputes/{$d}/proposal", ['outcome' => 'payment_received'])->assertOk();
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'other', 'note' => 'Please look at the secret detail.'])->assertOk();
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/review")->assertOk();
        $this->postJson("/api/v1/admin/disputes/{$d}/info-requests", ['to' => 'both', 'message' => 'Send the secret detail please.'])->assertOk();
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'received', 'reason' => 'The secret detail was convincing: 123,456.'])->assertOk();

        $notes = UserNotification::whereIn('user_id', [$customer->id, $provider->owner_id])->where('tag', 'like', 'dispute-%')->get();
        $this->assertGreaterThanOrEqual(5, $notes->count());
        foreach ($notes as $note) {
            $text = $note->title.' '.$note->body;
            foreach (['123', '456', 'ABC', 'secret', 'UGX'] as $leak) {
                $this->assertStringNotContainsStringIgnoringCase($leak, $text);
            }
            $this->assertStringContainsString("dispute={$d}", $note->url);
            $this->assertStringContainsString("request={$id}", $note->url);
        }
        // A person's own action shows them (their avatar slot), WorkMate's actions have no person
        $fromProvider = $notes->first(function ($n) {
            return $n->title === 'New message about a payment';
        });
        $this->assertSame($provider->owner_id, (int) $fromProvider->actor_id);
        $fromAdmin = $notes->first(function ($n) {
            return $n->title === 'WorkMate made a decision';
        });
        $this->assertNull($fromAdmin->actor_id);
        // The person who acted is not told about their own action
        $this->assertSame(0, UserNotification::where('user_id', $provider->owner_id)->where('title', 'New message about a payment')->count());
    }

    public function test_b3_still_works_and_b4_shadow_estimates_only_follow_an_explicitly_agreed_confirmation()
    {
        app(PlatformSettings::class)->set('finance.commission_shadow_enabled', true, $this->root);
        $policies = app(\App\Services\CommissionPolicies::class);
        $input = ['scope_type' => 'global', 'rate_bp' => 500, 'effective_from' => now()->subSeconds(20)->toIso8601String()];
        $policies->create($this->root, $input, $policies->simulate($this->root, $input)['token'], 500, 'Testing the rate');

        // An administrator's decisions (any of them) never create an estimate
        foreach (['received', 'not_established', 'insufficient_evidence'] as $outcome) {
            [, , , $payment, $d] = $this->underReview();
            $this->signIn($this->root);
            $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => $outcome, 'reason' => 'A reasoned decision for this test.'])->assertOk();
            $this->assertSame(0, CommissionShadowSnapshot::where('payment_report_id', $payment)->count(), $outcome);
        }
        $this->assertSame(0, CommissionShadowSnapshot::count());

        // Both sides agreeing it was received IS the customer confirming: it is estimated like any confirmed payment
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal", ['outcome' => 'payment_received'])->assertOk();
        $this->assertSame(0, CommissionShadowSnapshot::count());
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/proposal/accept", ['outcome' => 'payment_received'])->assertOk();
        $snapshot = CommissionShadowSnapshot::where('payment_report_id', $payment)->where('current_slot', 1)->first();
        $this->assertSame([CommissionShadowSnapshot::CALCULATED, 5000, 'shadow'], [$snapshot->status, $snapshot->commission_ugx, $snapshot->mode]);
        // Still nothing owed
        foreach (['provider_debts', 'commission_invoices', 'provider_balances', 'invoices', 'provider_payables', 'settlements'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
    }

    public function test_the_feature_switches_stay_off_and_the_new_permissions_have_the_right_holders()
    {
        $settings = config('workmate.settings');
        $this->assertFalse($settings['finance.pricing_enabled']['default']);
        $this->assertFalse($settings['finance.commission_shadow_enabled']['default']);
        $this->assertFalse(app(\App\Services\CommissionShadow::class)->enabled());   // nothing in the dispute work turned it on
        $this->assertSame(0, CommissionShadowPolicy::count());

        $holders = function (string $key) {
            return DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')->join('roles as r', 'r.id', '=', 'pr.role_id')
                ->where('p.key', $key)->orderBy('r.key')->pluck('r.key')->all();
        };
        $this->assertSame(['finance', 'super_admin'], $holders('disputes.view'));
        $this->assertSame(['finance', 'super_admin'], $holders('disputes.review'));
        $this->assertSame(['super_admin'], $holders('disputes.resolve'));
    }

    public function test_the_permission_migration_is_safe_to_run_again()
    {
        $migration = require_once base_path('database/migrations/2026_11_29_000073_add_payment_dispute_permissions.php');
        $migration = is_object($migration) ? $migration : new \AddPaymentDisputePermissions();
        $before = [DB::table('permissions')->count(), DB::table('permission_role')->count(), DB::table('roles')->count()];
        $id = DB::table('permissions')->where('key', 'disputes.view')->value('id');
        DB::table('permissions')->where('id', $id)->update(['description' => 'Edited by an administrator']);

        $migration->up();
        $migration->up();

        $this->assertSame($before, [DB::table('permissions')->count(), DB::table('permission_role')->count(), DB::table('roles')->count()]);
        $this->assertSame('Edited by an administrator', DB::table('permissions')->where('id', $id)->value('description'));
    }
    public function test_escalating_tells_only_the_people_who_review_disputes_without_private_details()
    {
        $finance = $this->userWithRoles(['finance']);
        $support = $this->userWithRoles(['support']);
        $suspended = $this->userWithRoles(['finance']);
        $suspended->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        [$provider, $customer, $id, $payment, $d] = $this->disputed(123456);
        $this->signIn($customer);
        $this->postJson("/api/v1/me/disputes/{$d}/messages", ['body' => 'Secret detail ABC123456.'])->assertCreated();
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'no_agreement', 'note' => 'The secret detail is in my messages.'])->assertOk();
        // The same request again, and the other side too: still one notification each
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'no_agreement', 'note' => 'The secret detail is in my messages.'])->assertOk();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/disputes/{$d}/escalate", ['reason' => 'other', 'note' => 'I also want WorkMate to look.'])->assertOk();

        $title = 'A payment question needs review';
        foreach ([$finance, $this->root] as $reviewer) {
            $this->assertSame(1, $this->note($reviewer, $title)->count(), $reviewer->id);
        }
        foreach ([$support, $suspended, $customer, $provider->owner] as $other) {
            $this->assertSame(0, $this->note($other, $title)->count(), $other->id);
        }
        $note = $this->note($finance, $title)->first();
        $this->assertSame("/admin/finance/payment-disputes?case={$d}", $note->url);
        $this->assertSame($customer->id, (int) $note->actor_id);
        foreach (['123', '456', 'ABC', 'secret', 'UGX', 'Aisha'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $note->title.' '.$note->body);
        }
        // The link opens the case for someone allowed to see it
        $this->signIn($finance);
        $this->getJson("/api/v1/admin/disputes/{$d}")->assertOk();
    }

    public function test_received_and_partial_decisions_are_visibly_pending_a_financial_correction_and_change_nothing()
    {
        foreach ([['received', null], ['partial', 60000]] as [$outcome, $amount]) {
            [$provider, $customer, $id, $payment, $d] = $this->underReview();
            $this->signIn($this->root);
            $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => $outcome, 'reason' => 'WorkMate found the evidence convincing.'] + ($amount ? ['amount' => $amount] : []))->assertOk();

            // Each side reads the decision AND the unchanged report, separately
            foreach ([$customer, $provider->owner] as $reader) {
                $this->signIn($reader);
                $view = $this->getJson("/api/v1/me/disputes/{$d}")->assertOk()->json('data');
                $this->assertTrue($view['resolution']['financial_correction_required']);
                $this->assertSame($outcome, $view['resolution']['type']);
                $this->assertSame('disputed', $view['payment']['status']);   // the record, not the decision
            }
            $this->signIn($this->root);
            $row = collect($this->getJson('/api/v1/admin/disputes?status=resolved')->json('data.items'))->firstWhere('id', $d);
            $this->assertTrue($row['financial_correction_required']);
            $this->assertTrue($this->getJson("/api/v1/admin/disputes/{$d}")->json('data.admin.financial_correction_required'));
            $this->assertSame(JobPaymentReport::DISPUTED, JobPaymentReport::find($payment)->status);
            $this->assertSame(0, JobPaymentEvent::where('payment_id', $payment)->where('event', 'confirmed')->count());
            $this->assertSame(0, CommissionShadowSnapshot::count());
        }
    }

    public function test_a_provider_withdrawing_after_a_decision_is_recorded_and_the_decision_stands()
    {
        [$provider, $customer, $id, $payment, $d] = $this->underReview();
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/disputes/{$d}/decision", ['outcome' => 'partial', 'amount' => 60000, 'reason' => 'Only part of it was paid, per the receipt.'])->assertOk();
        $caseNow = function () use ($d) {
            return json_encode(PaymentDispute::find($d)->only(['status', 'resolution_type', 'resolution_reason', 'resolution_amount_ugx', 'resolved_by_user_id', 'resolved_at']));
        };
        $decided = $caseNow();

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/payments/{$payment}/cancel", ['reason' => 'Reporting the right amount instead.'])->assertOk();

        // The decision is exactly as it was; the report's own state moved, in its own attributed step; and the history says so
        $this->assertSame($decided, $caseNow());
        $this->assertSame(JobPaymentReport::CANCELLED, JobPaymentReport::find($payment)->status);
        $this->assertSame(1, PaymentDisputeEvent::where('payment_dispute_id', $d)->where('event', 'report_withdrawn')->count());
        $this->assertTrue(AuditLog::where('action', 'payment_dispute.report_withdrawn_after_decision')->where('actor_id', $provider->owner_id)->exists());
        $this->assertSame(1, JobPaymentEvent::where('payment_id', $payment)->where('event', 'cancelled')->where('actor_role', 'provider')->count());
        $view = $this->getJson("/api/v1/me/disputes/{$d}")->assertOk()->json('data');
        $this->assertSame('cancelled', $view['payment']['status']);
        $this->assertSame('partial', $view['resolution']['type']);
        $this->assertContains('report_withdrawn', array_column($view['timeline'], 'event'));
        // Taking it back again changes nothing more
        $this->postJson("/api/v1/me/received-requests/{$id}/payments/{$payment}/cancel", ['reason' => 'Reporting the right amount instead.'])->assertOk();
        $this->assertSame(1, PaymentDisputeEvent::where('payment_dispute_id', $d)->where('event', 'report_withdrawn')->count());
        $this->assertSame(0, CommissionShadowSnapshot::count());
    }

    public function test_a_withdrawal_that_closes_the_case_is_not_recorded_as_after_a_decision()
    {
        [$provider, $customer, $id, $payment, $d] = $this->disputed();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/payments/{$payment}/cancel", ['reason' => 'I made a mistake with it.'])->assertOk();
        $this->assertSame(0, PaymentDisputeEvent::where('event', 'report_withdrawn')->count());
        $this->assertSame('provider_withdrew', PaymentDispute::find($d)->resolution_type);
    }
}
