<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\JobPriceAgreement;
use App\Models\JobPricingEvent;
use App\Models\Profession;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Phase B2: price proposals, the customer's decision, revisions and the history. A price is agreed only by the
 * customer's explicit "Accept price" on the version they are looking at: never by time, a page visit or a
 * notification. None of it charges money or creates a commission or debt.
 */
class PriceAgreementTest extends ApiTestCase
{
    private function switchOn(bool $on = true): void
    {
        app(PlatformSettings::class)->set('finance.pricing_enabled', $on, $this->userWithRoles(['super_admin']));
    }

    private function provider(array $professions = ['plumber', 'electrician']): ProviderProfile
    {
        $owner = User::factory()->create();
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, [
            'type' => 'individual', 'display_name' => 'Okello Services '.$owner->id,
            'professions' => array_map(function ($p) {
                return ['profession' => $p];
            }, $professions),
            'base_area' => 'ntinda',
        ]);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    /** A request a customer sent to the provider; returns its id */
    private function request(ProviderProfile $provider, User $customer, array $extra = []): int
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/providers/{$provider->id}/requests", $extra + [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow morning',
        ])->assertCreated()->json('data.id');
    }

    private function propose(User $by, int $request, array $extra = [])
    {
        $this->signIn($by);

        return $this->postJson("/api/v1/me/received-requests/{$request}/agreements", $extra + [
            'amount' => 150000, 'scope' => 'Replace the kitchen sink pipes and fit a new tap.', 'exclusions' => 'Tiles and wall repairs.',
        ]);
    }

    private function accept(User $customer, int $request, int $agreement, $amount)
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/me/requests/{$request}/agreements/{$agreement}/accept", ['amount' => $amount]);
    }

    /** A fixture: pricing on, a provider, a customer and a request; returns [provider, customer, request id] */
    private function fixture(): array
    {
        $this->switchOn();
        $provider = $this->provider();
        $customer = User::factory()->create();

        return [$provider, $customer, $this->request($provider, $customer)];
    }

    private function notifications(User $user, string $title)
    {
        return UserNotification::where('user_id', $user->id)->where('title', $title);
    }

    /* ---------------- The switch ---------------- */

    public function test_while_pricing_is_off_nothing_can_be_proposed_decided_or_read_and_nothing_is_stored()
    {
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->request($provider, $customer);

        $this->propose($provider->owner, $id)->assertStatus(409)->assertJson(['code' => 'pricing_unavailable']);
        $this->postJson("/api/v1/me/received-requests/{$id}/agreements/1/withdraw")->assertStatus(409)->assertJson(['code' => 'pricing_unavailable']);
        $this->getJson("/api/v1/me/received-requests/{$id}/pricing")->assertStatus(409);

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/1/accept", ['amount' => 150000])->assertStatus(409)->assertJson(['code' => 'pricing_unavailable']);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/1/reject")->assertStatus(409);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/1/changes")->assertStatus(409);
        $this->getJson("/api/v1/me/requests/{$id}/pricing")->assertStatus(409);

        // The existing responses carry no price block while it is off
        $this->assertNull($this->getJson('/api/v1/me/requests')->json('data.0.pricing'));
        $this->signIn($provider->owner);
        $this->assertNull($this->getJson('/api/v1/me/received-requests')->json('data.0.pricing'));

        $this->assertSame(0, JobPriceAgreement::count());
        $this->assertSame(0, JobPricingEvent::count());
    }

    public function test_switching_pricing_off_hides_existing_proposals_but_keeps_them()
    {
        [$provider, $customer, $id] = $this->fixture();
        $this->propose($provider->owner, $id)->assertCreated();
        $this->switchOn(false);

        $this->signIn($customer);
        $this->assertNull($this->getJson('/api/v1/me/requests')->json('data.0.pricing'));
        $this->assertSame(1, JobPriceAgreement::count());
        $this->switchOn();
        $this->signIn($customer);
        $this->assertSame(150000, $this->getJson('/api/v1/me/requests')->json('data.0.pricing.open.amount'));
    }

    public function test_the_expiry_setting_and_the_default_exist_and_pricing_stays_off_by_default()
    {
        $this->assertFalse(app(PlatformSettings::class)->get('finance.pricing_enabled'));
        $this->assertSame(7, app(PlatformSettings::class)->get('finance.agreement_expiry_days'));
    }

    /* ---------------- Proposing ---------------- */

    public function test_the_owner_proposes_a_price_and_the_customer_is_told()
    {
        [$provider, $customer, $id] = $this->fixture();

        $this->propose($provider->owner, $id)->assertCreated()
            ->assertJsonPath('data.pricing.open.amount', 150000)
            ->assertJsonPath('data.pricing.open.status', 'pending_customer')
            ->assertJsonPath('data.pricing.open.revision_no', 1)
            ->assertJsonPath('data.pricing.open.exclusions', 'Tiles and wall repairs.')
            ->assertJsonPath('data.pricing.agreed', null);

        $version = JobPriceAgreement::first();
        $this->assertSame([$id, $provider->id, 150000, 1, 1, null], [$version->service_request_id, $version->provider_profile_id, $version->amount_ugx, $version->revision_no, $version->open_slot, $version->agreed_slot]);
        $this->assertSame('Replace the kitchen sink pipes and fit a new tap.', $version->scope_text);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $version->expires_at->timestamp, 5);
        $this->assertSame($provider->owner_id, $version->proposed_by_user_id);
        // What the job was, for later phases: its category and place are recorded with the price
        $this->assertSame('Kisaasi', $version->area_name);
        $this->assertSame(Category::where('slug', 'plumbing')->value('id'), $version->category_id);

        $this->assertSame(['proposed'], JobPricingEvent::pluck('event')->all());
        $this->assertTrue(AuditLog::where('action', 'marketplace.price_proposed')->where('actor_id', $provider->owner_id)->exists());

        // The customer is told (without the amount on the lock screen), with the provider as the person
        $note = $this->notifications($customer, 'New price proposed')->first();
        $this->assertNotNull($note);
        $this->assertSame($provider->owner_id, (int) $note->actor_id);
        $this->assertStringNotContainsString('150', $note->body);
        $this->assertStringContainsString('request='.$id, $note->url);

        // Both sides see it; the provider's own list too
        $this->signIn($customer);
        $this->assertSame(150000, $this->getJson('/api/v1/me/requests')->json('data.0.pricing.open.amount'));
        $this->signIn($provider->owner);
        $this->assertSame(150000, $this->getJson('/api/v1/me/received-requests')->json('data.0.pricing.open.amount'));
    }

    public function test_proposed_prices_are_checked_by_the_server()
    {
        [$provider, $customer, $id] = $this->fixture();
        $owner = $provider->owner;

        $this->propose($owner, $id, ['amount' => 999])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->propose($owner, $id, ['amount' => 100000001])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->propose($owner, $id, ['amount' => 12500.5])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->propose($owner, $id, ['amount' => '150000abc'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->propose($owner, $id, ['amount' => -5000])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->propose($owner, $id, ['amount' => null])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->propose($owner, $id, ['scope' => 'short'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->propose($owner, $id, ['scope' => str_repeat('a', 2001)])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->propose($owner, $id, ['exclusions' => str_repeat('a', 1001)])->assertStatus(422)->assertJsonValidationErrors('exclusions');
        $this->propose($owner, $id, ['client_token' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors('client_token');
        $this->assertSame(0, JobPriceAgreement::count());

        // The limits themselves are fine, and an empty exclusion is stored as none
        $this->propose($owner, $id, ['amount' => 1000, 'exclusions' => ''])->assertCreated();
        $this->assertNull(JobPriceAgreement::first()->exclusions_text);
        $this->propose($owner, $id, ['amount' => 100000000])->assertCreated();
        $this->assertSame([1000, 100000000], JobPriceAgreement::orderBy('revision_no')->pluck('amount_ugx')->all());
    }

    public function test_only_the_owner_or_a_manager_of_the_receiving_business_can_propose()
    {
        [$provider, $customer, $id] = $this->fixture();
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        $provider->members()->create(['user_id' => $member->id, 'role' => ProviderMember::ROLE_MEMBER]);

        $this->propose($member, $id)->assertStatus(403)->assertJson(['code' => 'not_authorised']);
        $this->propose($customer, $id)->assertNotFound();
        $this->propose($this->provider()->owner, $id)->assertNotFound();
        $this->propose(User::factory()->create(), $id)->assertNotFound();
        $this->assertSame(0, JobPriceAgreement::count());

        $this->propose($manager, $id)->assertCreated();
        $this->assertSame($manager->id, JobPriceAgreement::first()->proposed_by_user_id);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json'])->postJson("/api/v1/me/received-requests/{$id}/agreements", ['amount' => 150000, 'scope' => 'Replace the pipes.'])->assertStatus(401);
    }

    public function test_a_price_follows_the_rules_for_providers_and_for_the_state_of_the_request()
    {
        [$provider, $customer, $id] = $this->fixture();

        // Requests from before bookings existed keep their original steps
        ServiceRequest::whereKey($id)->update(['booking_version' => ServiceRequest::BOOKING_LEGACY]);
        $this->propose($provider->owner, $id)->assertStatus(409)->assertJson(['code' => 'legacy_request']);
        ServiceRequest::whereKey($id)->update(['booking_version' => ServiceRequest::BOOKING_V2]);

        // A finished or cancelled job is no longer priced
        foreach (['completed', 'cancelled'] as $status) {
            ServiceRequest::whereKey($id)->update(['status' => $status]);
            $this->propose($provider->owner, $id)->assertStatus(409)->assertJson(['code' => 'request_status_conflict']);
        }
        ServiceRequest::whereKey($id)->update(['status' => 'scheduled', 'scheduled_start_at' => now()->addDay(), 'scheduled_end_at' => now()->addDay()->addHours(2)]);
        $this->propose($provider->owner, $id)->assertCreated();

        // A suspended provider can't
        $other = $this->request($provider, User::factory()->create(), ['description' => 'The bathroom tap is dripping all night.']);
        $provider->forceFill(['status' => ProviderProfile::STATUS_SUSPENDED])->save();
        $this->propose($provider->owner, $other)->assertStatus(403)->assertJson(['code' => 'provider_not_active']);
    }

    public function test_the_same_try_again_returns_the_first_proposal_with_nothing_new_recorded_or_sent()
    {
        [$provider, $customer, $id] = $this->fixture();
        $token = '8a9f3a52-1b8e-4a3e-9d0b-3a2f4f6c9e10';

        $first = $this->propose($provider->owner, $id, ['client_token' => $token])->assertCreated()->json('data.pricing.open.id');
        $second = $this->propose($provider->owner, $id, ['client_token' => $token])->assertOk()->json('data.pricing.open.id');
        $this->propose($provider->owner, $id, ['client_token' => $token, 'amount' => 999999])->assertOk();

        $this->assertSame($first, $second);
        $this->assertSame(1, JobPriceAgreement::count());
        $this->assertSame(1, JobPricingEvent::count());
        $this->assertSame(1, $this->notifications($customer, 'New price proposed')->count());
        $this->assertSame(150000, JobPriceAgreement::first()->amount_ugx);
    }

    public function test_an_identical_proposal_without_a_token_is_refused_as_a_duplicate()
    {
        [$provider, $customer, $id] = $this->fixture();
        $this->propose($provider->owner, $id)->assertCreated();
        $this->propose($provider->owner, $id)->assertStatus(409)->assertJson(['code' => 'duplicate_proposal']);
        $this->assertSame(1, JobPriceAgreement::count());
    }

    /* ---------------- The customer's decision ---------------- */

    public function test_the_customer_accepts_the_price_and_it_becomes_the_agreed_price()
    {
        [$provider, $customer, $id] = $this->fixture();
        $manager = User::factory()->create();
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        $version = $this->propose($provider->owner, $id)->assertCreated()->json('data.pricing.open.id');

        $this->accept($customer, $id, $version, 150000)->assertOk()
            ->assertJsonPath('data.pricing.agreed.amount', 150000)
            ->assertJsonPath('data.pricing.agreed.status', 'agreed')
            ->assertJsonPath('data.pricing.agreed.scope', 'Replace the kitchen sink pipes and fit a new tap.')
            ->assertJsonPath('data.pricing.open', null);

        $a = JobPriceAgreement::find($version);
        $this->assertSame(['agreed', null, 1, $customer->id], [$a->status, $a->open_slot, $a->agreed_slot, $a->responded_by_user_id]);
        $this->assertNotNull($a->responded_at);
        $this->assertSame(['proposed', 'accepted'], JobPricingEvent::orderBy('id')->pluck('event')->all());
        $this->assertTrue(AuditLog::where('action', 'marketplace.price_accepted')->where('actor_id', $customer->id)->exists());

        // The job itself is untouched by a price
        $this->assertSame('open', ServiceRequest::find($id)->status);

        // The provider business hears about it (the owner and the manager, not the customer)
        $this->assertSame(1, $this->notifications($provider->owner, 'Price agreed')->count());
        $this->assertSame(1, $this->notifications($manager, 'Price agreed')->count());
        $this->assertSame(0, $this->notifications($customer, 'Price agreed')->count());
        $this->assertSame($customer->id, (int) $this->notifications($provider->owner, 'Price agreed')->first()->actor_id);
    }

    public function test_accepting_needs_the_amount_being_looked_at_and_it_must_match()
    {
        [$provider, $customer, $id] = $this->fixture();
        $version = $this->propose($provider->owner, $id)->json('data.pricing.open.id');

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$version}/accept", [])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->accept($customer, $id, $version, 140000)->assertStatus(409)->assertJson(['code' => 'stale_proposal']);
        $this->accept($customer, $id, $version, '150000.5')->assertStatus(422);
        $this->assertSame('pending_customer', JobPriceAgreement::find($version)->status);
        $this->assertSame(0, JobPriceAgreement::whereNotNull('agreed_slot')->count());
    }

    public function test_accepting_twice_changes_and_notifies_nothing_more()
    {
        [$provider, $customer, $id] = $this->fixture();
        $version = $this->propose($provider->owner, $id)->json('data.pricing.open.id');

        $this->accept($customer, $id, $version, 150000)->assertOk();
        $this->accept($customer, $id, $version, 150000)->assertOk()->assertJsonPath('data.pricing.agreed.amount', 150000);
        $this->accept($customer, $id, $version, 150000)->assertOk();

        $this->assertSame(['proposed', 'accepted'], JobPricingEvent::orderBy('id')->pluck('event')->all());
        $this->assertSame(1, $this->notifications($provider->owner, 'Price agreed')->count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.price_accepted')->count());
    }

    public function test_the_customer_can_turn_a_price_down_and_the_provider_can_propose_again()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/reject", ['note' => 'Too expensive for me.'])->assertOk()
            ->assertJsonPath('data.pricing.agreed', null)->assertJsonPath('data.pricing.open', null);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/reject")->assertOk();
        $this->assertSame('rejected', JobPriceAgreement::find($v1)->status);
        $this->assertSame(1, $this->notifications($provider->owner, 'Price declined')->count());
        $this->assertSame(1, JobPricingEvent::where('event', 'rejected')->count());

        // Rejected is final for that version: it can't be accepted afterwards
        $this->accept($customer, $id, $v1, 150000)->assertStatus(409)->assertJson(['code' => 'already_decided']);

        // The provider proposes a new price
        $v2 = $this->propose($provider->owner, $id, ['amount' => 120000])->assertCreated()->json('data.pricing.open.id');
        $this->assertSame(2, JobPriceAgreement::find($v2)->revision_no);
        $this->accept($customer, $id, $v2, 120000)->assertOk()->assertJsonPath('data.pricing.agreed.amount', 120000);
    }

    public function test_asking_for_a_change_agrees_nothing_and_the_provider_sends_a_new_version()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/changes", ['note' => 'Can you do it for less?', 'suggested_amount' => 120000])->assertOk()
            ->assertJsonPath('data.pricing.open.status', 'revision_requested')
            ->assertJsonPath('data.pricing.open.suggested_amount', 120000)
            ->assertJsonPath('data.pricing.open.response_note', 'Can you do it for less?')
            ->assertJsonPath('data.pricing.agreed', null);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/changes", ['note' => 'Changed my mind'])->assertOk();
        $this->assertSame('Can you do it for less?', JobPriceAgreement::find($v1)->response_note);
        $this->assertSame(1, $this->notifications($provider->owner, 'Change requested')->count());

        // A suggestion is not an offer: the old version can't be accepted, and nothing is agreed
        $this->accept($customer, $id, $v1, 150000)->assertStatus(409);
        $this->accept($customer, $id, $v1, 120000)->assertStatus(409);
        $this->assertSame(0, JobPriceAgreement::whereNotNull('agreed_slot')->count());

        // The provider sends a new version: the old one is superseded, the customer is told
        $v2 = $this->propose($provider->owner, $id, ['amount' => 125000])->assertCreated()->json('data.pricing.open.id');
        $this->assertSame('superseded', JobPriceAgreement::find($v1)->status);
        $this->assertSame($v1, JobPriceAgreement::find($v2)->supersedes_id);
        $this->assertSame(1, $this->notifications($customer, 'Price changed')->count());
        $this->accept($customer, $id, $v2, 125000)->assertOk()->assertJsonPath('data.pricing.agreed.revision_no', 2);

        // The change request's suggestion is only a suggestion
        $this->assertSame(120000, JobPriceAgreement::find($v1)->suggested_amount_ugx);
        $this->assertSame(125000, JobPriceAgreement::find($v2)->amount_ugx);
    }

    public function test_a_suggested_amount_is_checked()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/changes", ['suggested_amount' => 500])->assertStatus(422)->assertJsonValidationErrors('suggested_amount');
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/changes", ['suggested_amount' => 12.5])->assertStatus(422);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/changes", ['note' => str_repeat('a', 301)])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertSame('pending_customer', JobPriceAgreement::find($v1)->status);
    }

    public function test_a_customer_looking_at_an_old_version_cannot_accept_it_after_the_provider_changed_the_price()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        // The customer is reviewing version 1 when the provider sends version 2
        $v2 = $this->propose($provider->owner, $id, ['amount' => 180000])->json('data.pricing.open.id');

        $this->accept($customer, $id, $v1, 150000)->assertStatus(409)->assertJson(['code' => 'stale_proposal']);
        $this->assertSame(0, JobPriceAgreement::whereNotNull('agreed_slot')->count());
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/reject")->assertStatus(409)->assertJson(['code' => 'stale_proposal']);

        // Looking at the latest, they can accept it
        $this->accept($customer, $id, $v2, 180000)->assertOk()->assertJsonPath('data.pricing.agreed.amount', 180000);
    }

    public function test_a_proposal_cannot_be_accepted_and_rejected_both_whoever_acts_first_wins()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');

        $this->accept($customer, $id, $v1, 150000)->assertOk();
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/reject")->assertStatus(409)->assertJson(['code' => 'already_decided']);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/changes")->assertStatus(409);
        $this->assertSame('agreed', JobPriceAgreement::find($v1)->status);

        // The provider can't take back an agreed price either
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/agreements/{$v1}/withdraw")->assertStatus(409)->assertJson(['code' => 'already_decided']);
        $this->assertSame('agreed', JobPriceAgreement::find($v1)->status);
    }

    public function test_the_database_itself_refuses_two_open_or_two_agreed_versions()
    {
        [$provider, $customer, $id] = $this->fixture();
        $this->propose($provider->owner, $id)->assertCreated();
        $first = JobPriceAgreement::first();

        $row = function (int $revision, array $extra) use ($first) {
            return $extra + [
                'service_request_id' => $first->service_request_id, 'provider_profile_id' => $first->provider_profile_id, 'revision_no' => $revision,
                'amount_ugx' => 1000 * $revision + 1000, 'scope_text' => 'Another version of the work.', 'status' => 'pending_customer', 'proposed_by_user_id' => $first->proposed_by_user_id,
                'proposed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
        };
        $refused = function (callable $insert) {
            try {
                DB::beginTransaction();
                $insert();
                DB::rollBack();

                return false;
            } catch (QueryException $e) {
                DB::rollBack();

                return true;
            }
        };

        // A second open version for the same request
        $this->assertTrue($refused(function () use ($row) {
            DB::table('job_price_agreements')->insert($row(2, ['open_slot' => 1]));
        }));
        // Two agreed versions in force
        DB::table('job_price_agreements')->where('id', $first->id)->update(['status' => 'agreed', 'open_slot' => null, 'agreed_slot' => 1]);
        $this->assertTrue($refused(function () use ($row) {
            DB::table('job_price_agreements')->insert($row(2, ['status' => 'agreed', 'agreed_slot' => 1]));
        }));
        // The same revision number twice
        $this->assertTrue($refused(function () use ($row) {
            DB::table('job_price_agreements')->insert($row(1, []));
        }));
        // Closed versions pile up freely
        DB::table('job_price_agreements')->insert($row(2, ['status' => 'rejected']));
        DB::table('job_price_agreements')->insert($row(3, ['status' => 'superseded']));
        $this->assertSame(3, JobPriceAgreement::count());
    }

    /* ---------------- Amendments ---------------- */

    public function test_a_price_can_only_change_after_agreement_through_an_amendment_the_customer_accepts()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        $this->accept($customer, $id, $v1, 150000)->assertOk();

        // The same price again is nothing to change
        $this->propose($provider->owner, $id)->assertStatus(422)->assertJson(['code' => 'nothing_to_change']);

        // An amendment is a new version; the agreed price stays in force until it is accepted
        $res = $this->propose($provider->owner, $id, ['amount' => 175000, 'scope' => 'Replace the pipes, fit a new tap and a new drain.'])->assertCreated();
        $v2 = $res->json('data.pricing.open.id');
        $res->assertJsonPath('data.pricing.agreed.amount', 150000)->assertJsonPath('data.pricing.open.amount', 175000)->assertJsonPath('data.pricing.open.is_amendment', true);
        $this->assertTrue(JobPriceAgreement::find($v2)->is_amendment);
        $this->assertFalse(JobPriceAgreement::find($v1)->is_amendment);

        // The provider alone cannot change an agreed price, whatever they send
        $this->assertSame(150000, JobPriceAgreement::where('agreed_slot', 1)->value('amount_ugx'));

        // Turned down: the agreed price is unchanged
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v2}/reject")->assertOk()->assertJsonPath('data.pricing.agreed.amount', 150000);

        // Another amendment, this time accepted: the earlier agreement is superseded, never overwritten
        $v3 = $this->propose($provider->owner, $id, ['amount' => 165000])->json('data.pricing.open.id');
        $this->accept($customer, $id, $v3, 165000)->assertOk()->assertJsonPath('data.pricing.agreed.amount', 165000);
        $this->assertSame(['superseded', 'rejected', 'agreed'], [JobPriceAgreement::find($v1)->status, JobPriceAgreement::find($v2)->status, JobPriceAgreement::find($v3)->status]);
        $this->assertSame(150000, JobPriceAgreement::find($v1)->amount_ugx);
        $this->assertSame(1, JobPriceAgreement::whereNotNull('agreed_slot')->count());
        $this->assertSame('amended', JobPriceAgreement::find($v1)->closed_reason);
    }

    /* ---------------- Withdrawing, expiry and the job's own lifecycle ---------------- */

    public function test_the_provider_can_take_back_a_proposal_the_customer_has_not_accepted()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/agreements/{$v1}/withdraw")->assertOk()->assertJsonPath('data.pricing.open', null);
        $this->postJson("/api/v1/me/received-requests/{$id}/agreements/{$v1}/withdraw")->assertOk();
        $this->assertSame(['cancelled', 'provider_withdrew'], [JobPriceAgreement::find($v1)->status, JobPriceAgreement::find($v1)->closed_reason]);
        $this->assertSame(1, $this->notifications($customer, 'Price offer withdrawn')->count());
        $this->accept($customer, $id, $v1, 150000)->assertStatus(409);
        $this->assertSame(0, JobPriceAgreement::whereNotNull('agreed_slot')->count());
    }

    public function test_silence_never_agrees_a_price_a_late_answer_is_refused_and_it_expires_once()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        JobPriceAgreement::whereKey($v1)->update(['expires_at' => now()->subMinute()]);

        // Overdue reads as expired straight away, and can't be accepted
        $this->signIn($customer);
        $this->assertSame('expired', $this->getJson('/api/v1/me/requests')->json('data.0.pricing.open.status'));
        $this->accept($customer, $id, $v1, 150000)->assertStatus(409)->assertJson(['code' => 'proposal_expired']);
        $this->assertSame(0, JobPriceAgreement::whereNotNull('agreed_slot')->count());

        // The scheduled check records it once and tells the provider once, however often it runs
        $this->artisan('workmate:expire-price-proposals')->expectsOutput('Expired: 1')->assertExitCode(0);
        $this->artisan('workmate:expire-price-proposals')->expectsOutput('Expired: 0')->assertExitCode(0);
        $this->assertSame(['expired', 'timed_out', null], [JobPriceAgreement::find($v1)->status, JobPriceAgreement::find($v1)->closed_reason, JobPriceAgreement::find($v1)->open_slot]);
        $this->assertSame(1, $this->notifications($provider->owner, 'Price offer expired')->count());
        $this->assertSame(1, JobPricingEvent::where('event', 'expired')->count());
        $this->assertNull(JobPricingEvent::where('event', 'expired')->first()->actor_id);

        // An expired offer is never an agreement; the provider proposes it again
        $this->assertSame(0, JobPriceAgreement::where('status', 'agreed')->count());
        $this->propose($provider->owner, $id)->assertCreated();
    }

    public function test_an_overdue_proposal_is_expired_when_the_provider_proposes_again_and_the_history_is_true()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        JobPriceAgreement::whereKey($v1)->update(['expires_at' => now()->subHour()]);

        $v2 = $this->propose($provider->owner, $id, ['amount' => 140000])->assertCreated()->json('data.pricing.open.id');
        $this->assertSame('expired', JobPriceAgreement::find($v1)->status);
        $this->assertSame('pending_customer', JobPriceAgreement::find($v2)->status);

        // A proposal that waited exactly as long as allowed is still open; the setting is respected
        app(PlatformSettings::class)->set('finance.agreement_expiry_days', 3, $this->userWithRoles(['super_admin']));
        $this->propose($provider->owner, $id, ['amount' => 130000])->assertCreated();
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, JobPriceAgreement::orderByDesc('id')->first()->expires_at->timestamp, 5);
    }

    public function test_a_price_still_being_talked_about_is_closed_when_the_job_is_cancelled_or_finished_but_an_agreed_price_stays()
    {
        [$provider, $customer, $id] = $this->fixture();
        $this->propose($provider->owner, $id)->assertCreated();

        // The provider declines the request: the open price can't be accepted any more
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/decline", ['reason' => 'I am fully booked this week.'])->assertOk();
        $v = JobPriceAgreement::first();
        $this->assertSame(['cancelled', 'request_cancelled'], [$v->status, $v->closed_reason]);
        $this->assertNull($v->open_slot);
        $this->accept($customer, $id, $v->id, 150000)->assertStatus(409);

        // A job that is agreed and then finished keeps its agreed price; a proposal still open is closed
        $second = $this->request($provider, $customer, ['description' => 'A second job: the bath tap is broken.']);
        $a = $this->propose($provider->owner, $second)->json('data.pricing.open.id');
        $this->accept($customer, $second, $a, 150000)->assertOk();
        $this->propose($provider->owner, $second, ['amount' => 160000])->assertCreated();
        ServiceRequest::whereKey($second)->update(['status' => 'in_progress']);
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$second}/complete")->assertOk();
        $this->assertSame('agreed', JobPriceAgreement::find($a)->status);
        $this->assertSame('job_completed', JobPriceAgreement::where('service_request_id', $second)->where('status', 'cancelled')->value('closed_reason'));

        // The customer cancelling a scheduled booking closes the open price too
        $third = $this->request($provider, $customer, ['description' => 'A third job: the toilet will not flush.']);
        $this->propose($provider->owner, $third)->assertCreated();
        ServiceRequest::whereKey($third)->update(['status' => 'scheduled', 'scheduled_start_at' => now()->addDay(), 'scheduled_end_at' => now()->addDay()->addHours(2)]);
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$third}/cancel", ['reason' => 'No longer needed'])->assertOk();
        $this->assertSame('request_cancelled', JobPriceAgreement::where('service_request_id', $third)->value('closed_reason'));
    }

    /* ---------------- Inspection ---------------- */

    public function test_a_concrete_price_after_an_inspection_clears_inspection_needed()
    {
        [$provider, $customer, $id] = $this->fixture();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true, 'note' => 'I need to see the pipe first.'])->assertOk();

        $this->signIn($customer);
        $mine = $this->getJson('/api/v1/me/requests')->json('data.0');
        $this->assertTrue($mine['inspection_required']);
        $this->assertNull($mine['pricing']['open']);

        $this->propose($provider->owner, $id)->assertCreated()->assertJsonPath('data.inspection_required', false);
        $this->assertTrue(JobPriceAgreement::first()->after_inspection);
        $this->assertNull(ServiceRequest::find($id)->inspection_required_at);
        $this->assertTrue(AuditLog::where('action', 'marketplace.inspection_cleared')->where('subject_id', $id)->exists());

        // Only the customer's explicit acceptance makes it the price
        $this->signIn($customer);
        $this->assertSame('pending_customer', $this->getJson('/api/v1/me/requests')->json('data.0.pricing.open.status'));
        $this->assertNull($this->getJson('/api/v1/me/requests')->json('data.0.pricing.agreed'));
    }

    /* ---------------- Isolation ---------------- */

    public function test_nobody_else_can_decide_or_read_a_requests_price()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        $stranger = User::factory()->create();
        $otherProvider = $this->provider();

        // Another customer, another provider and the provider itself can't answer as the customer
        foreach ([$stranger, $otherProvider->owner, $provider->owner] as $outsider) {
            $this->signIn($outsider);
            $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/accept", ['amount' => 150000])->assertNotFound();
            $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/reject")->assertNotFound();
            $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/changes")->assertNotFound();
            $this->getJson("/api/v1/me/requests/{$id}/pricing")->assertNotFound();
        }
        // ...and the customer, another provider and a stranger can't act or read as the provider
        foreach ([$customer, $stranger, $otherProvider->owner] as $outsider) {
            $this->signIn($outsider);
            $this->postJson("/api/v1/me/received-requests/{$id}/agreements/{$v1}/withdraw")->assertNotFound();
            $this->getJson("/api/v1/me/received-requests/{$id}/pricing")->assertNotFound();
        }
        $this->assertSame('pending_customer', JobPriceAgreement::find($v1)->status);

        // A version of one request can't be used through another request of the same customer
        $other = $this->request($provider, $customer, ['description' => 'A second job: the bath tap is broken.']);
        $this->accept($customer, $other, $v1, 150000)->assertNotFound();
        $this->assertSame('pending_customer', JobPriceAgreement::find($v1)->status);

        // Another customer's lists never hold it
        $this->signIn($stranger);
        $this->assertSame([], $this->getJson('/api/v1/me/requests')->json('data'));
        $this->signIn($otherProvider->owner);
        $this->assertSame([], $this->getJson('/api/v1/me/received-requests')->json('data'));
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json'])->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/accept", ['amount' => 150000])->assertStatus(401);
    }

    public function test_the_public_job_list_never_carries_prices()
    {
        $this->switchOn();
        $customer = User::factory()->create();
        $this->signIn($customer);
        $job = $this->postJson('/api/v1/jobs', ['category' => 'plumbing', 'title' => 'Fix a pipe', 'description' => 'Under the sink.', 'area' => 'ntinda', 'workers_needed' => 1])->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $listed = $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/v1/jobs')->assertOk()->json('data');
        $this->assertArrayNotHasKey('pricing', $listed[0]);
        $this->assertNotNull($job);
    }

    /* ---------------- The history ---------------- */

    public function test_each_side_reads_the_whole_history_and_administrators_read_it_without_changing_it()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/changes", ['note' => 'A bit less?', 'suggested_amount' => 120000])->assertOk();
        $v2 = $this->propose($provider->owner, $id, ['amount' => 130000])->json('data.pricing.open.id');
        $this->accept($customer, $id, $v2, 130000)->assertOk();

        $history = $this->getJson("/api/v1/me/requests/{$id}/pricing")->assertOk()->json('data');
        $this->assertSame([150000, 130000], array_column($history['versions'], 'amount'));
        $this->assertSame(['superseded', 'agreed'], array_column($history['versions'], 'status'));
        // The older version is closed first, then the new one is proposed
        $this->assertSame(['proposed', 'change_requested', 'superseded', 'revised', 'accepted'], array_column($history['events'], 'event'));
        $this->assertSame(['provider', 'customer', 'provider', 'provider', 'customer'], array_column($history['events'], 'by'));
        $this->assertSame([1, 1, 1, 2, 2], array_column($history['events'], 'revision_no'));

        $this->signIn($provider->owner);
        $this->assertSame($history['events'], $this->getJson("/api/v1/me/received-requests/{$id}/pricing")->assertOk()->json('data.events'));

        // An administrator who can see the marketplace reads it; one who can't is refused; nobody can change it
        $this->signIn($this->userWithRoles(['support']));
        $this->getJson("/api/v1/admin/requests/{$id}/pricing")->assertOk()->assertJsonPath('data.versions.1.amount', 130000);
        $this->signIn($this->userWithRoles(['content']));
        $this->getJson("/api/v1/admin/requests/{$id}/pricing")->assertStatus(403);
        $this->signIn($customer);
        $this->getJson("/api/v1/admin/requests/{$id}/pricing")->assertStatus(403);
        $this->signIn($this->userWithRoles(['support']));
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v2}/reject")->assertNotFound();
    }

    public function test_price_versions_and_their_history_can_never_be_changed_or_removed()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        $this->accept($customer, $id, $v1, 150000)->assertOk();

        $a = JobPriceAgreement::find($v1);
        foreach (['amount_ugx' => 1000, 'scope_text' => 'A different scope of work.', 'exclusions_text' => 'x', 'revision_no' => 9, 'service_request_id' => 999, 'proposed_by_user_id' => 1, 'is_amendment' => true] as $field => $value) {
            try {
                $a->forceFill([$field => $value])->save();
                $this->fail("{$field} must not be changeable");
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be changed', $e->getMessage());
            }
            $a = JobPriceAgreement::find($v1);
        }
        try {
            $a->delete();
            $this->fail('A version must not be deletable');
        } catch (LogicException $e) {
            $this->assertTrue(true);
        }
        $event = JobPricingEvent::first();
        try {
            $event->forceFill(['amount_ugx' => 1])->save();
            $this->fail('History must not be changeable');
        } catch (LogicException $e) {
            $this->assertTrue(true);
        }
        try {
            $event->delete();
            $this->fail('History must not be deletable');
        } catch (LogicException $e) {
            $this->assertTrue(true);
        }
        $this->assertSame(150000, JobPriceAgreement::find($v1)->amount_ugx);
        $this->assertSame(2, JobPricingEvent::count());
    }

    /* ---------------- Where the job and the profession come from ---------------- */

    public function test_a_job_the_provider_was_hired_for_records_the_profession_they_applied_as()
    {
        $this->switchOn();
        $plumbing = Category::where('slug', 'plumbing')->first();
        $pipeFitter = Profession::create(['category_id' => $plumbing->id, 'slug' => 'pipe-fitter', 'name' => 'Pipe fitter', 'plural_name' => 'Pipe fitters']);
        $provider = $this->provider(['plumber']);
        $provider->professions()->attach($pipeFitter->id, ['is_primary' => false]);
        $customer = User::factory()->create();

        $this->signIn($customer);
        $job = $this->postJson('/api/v1/jobs', ['category' => 'plumbing', 'title' => 'Fix a pipe', 'description' => 'Under the sink.', 'area' => 'ntinda', 'workers_needed' => 1])->assertCreated()->json('data.id');
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/jobs/{$job}/applications", ['message' => 'I can fix this tomorrow morning.', 'start_time' => 'Tomorrow', 'profession' => 'pipe-fitter'])->assertCreated();
        $this->signIn($customer);
        $application = Quotation::first()->id;
        $this->postJson("/api/v1/me/jobs/{$job}/applications/{$application}/hire")->assertOk();

        $this->propose($provider->owner, $job)->assertCreated();
        $version = JobPriceAgreement::first();
        $this->assertSame([$pipeFitter->id, 'quotation', false], [$version->profession_id, $version->profession_source, $version->profession_review_needed]);

        // The customer of that posted job accepts it and sees it
        $this->accept($customer, $job, $version->id, 150000)->assertOk()->assertJsonPath('data.pricing.agreed.amount', 150000);
    }

    public function test_the_profession_of_a_direct_request_is_derived_never_chosen()
    {
        $this->switchOn();
        $customer = User::factory()->create();

        // One fitting profession: that one
        $sole = $this->provider(['plumber', 'electrician']);
        $a = $this->request($sole, $customer);
        $this->propose($sole->owner, $a)->assertCreated();
        $v = JobPriceAgreement::where('service_request_id', $a)->first();
        $this->assertSame([Profession::where('slug', 'plumber')->value('id'), 'sole_match', false], [$v->profession_id, $v->profession_source, $v->profession_review_needed]);

        // Several fit, and the main one is among them: that one, flagged for review
        $plumbing = Category::where('slug', 'plumbing')->first();
        $pipeFitter = Profession::create(['category_id' => $plumbing->id, 'slug' => 'pipe-fitter', 'name' => 'Pipe fitter', 'plural_name' => 'Pipe fitters']);
        $several = $this->provider(['plumber']);
        $several->professions()->attach($pipeFitter->id, ['is_primary' => false]);
        $b = $this->request($several, $customer, ['description' => 'The kitchen sink is leaking badly today.']);
        $this->propose($several->owner, $b)->assertCreated();
        $v = JobPriceAgreement::where('service_request_id', $b)->first();
        $this->assertSame([Profession::where('slug', 'plumber')->value('id'), 'primary_in_category', true], [$v->profession_id, $v->profession_source, $v->profession_review_needed]);

        // Several fit and none is the main one: not guessed
        $unclear = $this->provider(['electrician']);
        $unclear->professions()->attach([Profession::where('slug', 'plumber')->value('id') => ['is_primary' => false], $pipeFitter->id => ['is_primary' => false]]);
        $c = $this->request($unclear, $customer, ['description' => 'The kitchen sink is leaking, again.']);
        $this->propose($unclear->owner, $c)->assertCreated();
        $v = JobPriceAgreement::where('service_request_id', $c)->first();
        $this->assertSame([null, 'unresolved', true], [$v->profession_id, $v->profession_source, $v->profession_review_needed]);
    }

    /* ---------------- What each screen is shown ---------------- */

    public function test_a_declined_withdrawn_or_expired_price_is_still_shown_as_ended()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');

        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$v1}/reject")->assertOk()
            ->assertJsonPath('data.pricing.open', null)->assertJsonPath('data.pricing.agreed', null)
            ->assertJsonPath('data.pricing.latest.status', 'rejected')->assertJsonPath('data.pricing.latest.amount', 150000);
        $this->signIn($provider->owner);
        $this->assertSame('rejected', $this->getJson('/api/v1/me/received-requests')->json('data.0.pricing.latest.status'));

        // Once something is open or agreed, "latest" is not repeated
        $v2 = $this->propose($provider->owner, $id, ['amount' => 120000])->assertCreated()->assertJsonPath('data.pricing.latest', null)->json('data.pricing.open.id');
        $this->accept($customer, $id, $v2, 120000)->assertOk()->assertJsonPath('data.pricing.latest', null);

        // Nothing ever proposed: nothing at all
        $second = $this->request($provider, $customer, ['description' => 'A second job: the bath tap is broken.']);
        $this->signIn($customer);
        $mine = collect($this->getJson('/api/v1/me/requests')->json('data'))->firstWhere('id', $second);
        $this->assertSame(['agreed' => null, 'open' => null, 'latest' => null], $mine['pricing']);
    }

    public function test_the_chat_card_carries_the_price_for_both_sides_only_while_pricing_is_on()
    {
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        $this->signIn($provider->owner);
        $conversation = $this->postJson("/api/v1/me/received-requests/{$id}/message")->json('data.conversation_id');

        $card = function () use ($conversation) {
            return collect($this->getJson("/api/v1/me/conversations/{$conversation}")->assertOk()->json('data.messages'))->firstWhere('kind', 'request')['request'];
        };
        $this->assertSame(150000, $card()['pricing']['open']['amount']);
        $this->accept($customer, $id, $v1, 150000)->assertOk();
        $this->signIn($customer);
        $this->assertSame(150000, $card()['pricing']['agreed']['amount']);
        $this->assertNull($card()['pricing']['open']);

        $this->switchOn(false);
        $this->assertNull($card()['pricing']);
    }

    public function test_the_customer_of_a_posted_job_sees_and_answers_the_price_of_the_provider_they_hired()
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

        $version = $this->propose($provider->owner, $job)->assertCreated()->json('data.pricing.open.id');
        $this->signIn($customer);
        $listed = collect($this->getJson('/api/v1/me/requests')->json('data'))->firstWhere('id', $job);
        $this->assertSame(['posted', 150000], [$listed['kind'], $listed['pricing']['open']['amount']]);
        $this->assertStringContainsString('tab=posted', $this->notifications($customer, 'New price proposed')->first()->url);
        $this->accept($customer, $job, $version, 150000)->assertOk()->assertJsonPath('data.kind', 'posted')->assertJsonPath('data.pricing.agreed.amount', 150000);
    }

    /* ---------------- Scope ---------------- */

    public function test_this_phase_adds_no_payment_commission_ledger_or_debt()
    {
        foreach (['job_payments', 'commission_policies', 'commission_assessments', 'ledger_entries', 'settlement_cycles', 'provider_subscriptions'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "$table must not exist yet");
        }
        [$provider, $customer, $id] = $this->fixture();
        $v1 = $this->propose($provider->owner, $id)->json('data.pricing.open.id');
        $this->accept($customer, $id, $v1, 150000)->assertOk();
        // Agreeing a price changes no amount owed anywhere: the request keeps the budget the customer gave and nothing else
        $this->assertNull(ServiceRequest::find($id)->budget);
        $this->assertSame('open', ServiceRequest::find($id)->status);
    }
}
