<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Profession;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B1: budget types, provider estimates, "inspection needed" and the skill applied with. All of it sits
 * behind finance.pricing_enabled (off by default) and none of it prices a job, takes a payment or charges
 * a commission.
 */
class PricingOptionsTest extends ApiTestCase
{
    private function switchOn(bool $on = true): void
    {
        app(PlatformSettings::class)->set('finance.pricing_enabled', $on, $this->userWithRoles(['super_admin']));
    }

    /** An approved plumber (and electrician unless told otherwise); plumbing is their main skill */
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

    private function postJob(User $customer, array $extra = [])
    {
        $this->signIn($customer);

        return $this->postJson('/api/v1/jobs', $extra + [
            'category' => 'plumbing', 'title' => 'Fix a leaking pipe', 'description' => 'Under the kitchen sink.',
            'area' => 'ntinda', 'preferred_time' => 'This week', 'workers_needed' => 1,
        ]);
    }

    private function sendRequest(ProviderProfile $provider, User $customer, array $extra = [])
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/providers/{$provider->id}/requests", $extra + [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow morning',
        ]);
    }

    private function apply(ProviderProfile $provider, int $job, array $extra = [])
    {
        $this->signIn($provider->owner);

        return $this->postJson("/api/v1/jobs/{$job}/applications", $extra + ['message' => 'I can fix this tomorrow morning.', 'start_time' => 'Tomorrow, 9am']);
    }

    /* ---------------- The switch ---------------- */

    public function test_pricing_options_are_off_by_default_and_the_app_is_told()
    {
        $this->assertFalse($this->getJson('/api/v1/settings')->assertOk()->json('data')['finance.pricing_enabled']);
        $this->switchOn();
        $this->assertTrue($this->getJson('/api/v1/settings')->json('data')['finance.pricing_enabled']);
    }

    public function test_while_off_the_new_fields_are_ignored_and_everything_works_as_before()
    {
        $customer = User::factory()->create();
        $id = $this->postJob($customer, ['budget_type' => 'range', 'budget_min' => 20000, 'budget_max' => 40000, 'budget' => 30000, 'budget_basis' => 'fixed'])
            ->assertCreated()->json('data.id');

        $request = ServiceRequest::find($id);
        $this->assertSame(30000, $request->budget);
        $this->assertSame('fixed', $request->budget_basis);
        $this->assertNull($request->budget_type);
        $this->assertNull($request->budget_min);
        $this->assertNull($request->budget_max);

        // An application keeps its plain price; the estimate fields and skill are not read
        $provider = $this->provider();
        $this->apply($provider, $id, ['amount' => 25000, 'estimate_type' => 'range', 'amount_max' => 35000, 'profession' => 'electrician'])->assertCreated();
        $quotation = Quotation::first();
        $this->assertSame(25000, $quotation->amount);
        $this->assertNull($quotation->amount_max);
        $this->assertNull($quotation->estimate_type);
        $this->assertNull($quotation->profession_id);

        // Inspection and price ranges on services are not available
        $direct = $this->sendRequest($provider, User::factory()->create())->assertCreated()->json('data.id');
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$direct}/inspection", ['required' => true])->assertStatus(409)->assertJson(['code' => 'pricing_unavailable']);
        $this->postJson('/api/v1/me/provider/services', ['title' => 'Pipe repair', 'pricing_type' => 'range', 'price' => 20000, 'price_max' => 40000, 'category' => 'plumbing'])
            ->assertStatus(422);
        $this->assertNull(ServiceRequest::find($direct)->inspection_required_at);

        // The helper endpoint still answers, saying the options are off
        $this->getJson("/api/v1/jobs/{$id}/application-options")->assertOk()->assertJsonPath('data.pricing_enabled', false);
    }

    /* ---------------- Customer budgets ---------------- */

    public function test_a_customer_can_post_a_job_with_each_kind_of_budget()
    {
        $this->switchOn();
        $customer = User::factory()->create();

        $this->postJob($customer, ['budget_type' => 'fixed', 'budget' => 50000, 'budget_basis' => 'total'])->assertCreated()
            ->assertJsonPath('data.budget_type', 'fixed')->assertJsonPath('data.budget', 50000)->assertJsonPath('data.budget_basis', 'total')
            ->assertJsonPath('data.budget_min', null);

        // A range has no single amount (older apps then show no budget) and keeps its basis
        $range = $this->postJob($customer, ['budget_type' => 'range', 'budget_min' => 30000, 'budget_max' => 60000, 'budget_basis' => 'per day'])->assertCreated()
            ->assertJsonPath('data.budget_type', 'range')->assertJsonPath('data.budget', null)
            ->assertJsonPath('data.budget_min', 30000)->assertJsonPath('data.budget_max', 60000)->assertJsonPath('data.budget_basis', 'per day')
            ->json('data.id');
        $this->assertSame('range', ServiceRequest::find($range)->budget_type);

        // Negotiable: the amount, if any, is only where the talking starts
        $this->postJob($customer, ['budget_type' => 'negotiable', 'budget' => 40000])->assertCreated()
            ->assertJsonPath('data.budget_type', 'negotiable')->assertJsonPath('data.budget', 40000)->assertJsonPath('data.budget_basis', 'negotiable');
        $this->postJob($customer, ['budget_type' => 'negotiable'])->assertCreated()
            ->assertJsonPath('data.budget_type', 'negotiable')->assertJsonPath('data.budget', null);

        // No budget: whatever amounts were sent are dropped
        $this->postJob($customer, ['budget_type' => 'none', 'budget' => 99999, 'budget_min' => 1000, 'budget_max' => 5000])->assertCreated()
            ->assertJsonPath('data.budget_type', 'none')->assertJsonPath('data.budget', null)->assertJsonPath('data.budget_min', null)->assertJsonPath('data.budget_basis', null);
    }

    public function test_budget_types_are_checked_by_the_server()
    {
        $this->switchOn();
        $customer = User::factory()->create();

        $this->postJob($customer, ['budget_type' => 'fixed'])->assertStatus(422)->assertJsonValidationErrors('budget');
        $this->postJob($customer, ['budget_type' => 'range', 'budget_min' => 30000])->assertStatus(422)->assertJsonValidationErrors('budget_max');
        $this->postJob($customer, ['budget_type' => 'range', 'budget_max' => 30000])->assertStatus(422)->assertJsonValidationErrors('budget_min');
        $this->postJob($customer, ['budget_type' => 'range', 'budget_min' => 50000, 'budget_max' => 50000])->assertStatus(422)->assertJsonValidationErrors('budget_max');
        $this->postJob($customer, ['budget_type' => 'range', 'budget_min' => 60000, 'budget_max' => 50000])->assertStatus(422)->assertJsonValidationErrors('budget_max');
        $this->postJob($customer, ['budget_type' => 'range', 'budget_min' => 500, 'budget_max' => 50000])->assertStatus(422)->assertJsonValidationErrors('budget_min');
        $this->postJob($customer, ['budget_type' => 'range', 'budget_min' => 1000, 'budget_max' => 200000000])->assertStatus(422)->assertJsonValidationErrors('budget_max');
        $this->postJob($customer, ['budget_type' => 'fixed', 'budget' => 12.5])->assertStatus(422)->assertJsonValidationErrors('budget');
        $this->postJob($customer, ['budget_type' => 'free'])->assertStatus(422)->assertJsonValidationErrors('budget_type');
        $this->assertSame(0, ServiceRequest::count());
    }

    public function test_older_apps_that_only_send_a_budget_still_work_and_old_requests_read_correctly()
    {
        $this->switchOn();
        $customer = User::factory()->create();

        // An older app: budget and basis only
        $this->postJob($customer, ['budget' => 50000, 'budget_basis' => 'fixed'])->assertCreated()->assertJsonPath('data.budget_type', 'fixed');
        $this->postJob($customer, ['budget' => 50000, 'budget_basis' => 'negotiable'])->assertCreated()->assertJsonPath('data.budget_type', 'negotiable');
        $this->postJob($customer)->assertCreated()->assertJsonPath('data.budget_type', 'none')->assertJsonPath('data.budget', null);

        // A request saved before budget types existed (no type stored) is read from the older columns
        $old = ServiceRequest::first();
        DB::table('service_requests')->where('id', $old->id)->update(['budget_type' => null, 'budget' => 70000, 'budget_basis' => 'per day']);
        $this->getJson("/api/v1/jobs/{$old->id}")->assertOk()->assertJsonPath('data.budget_type', 'fixed')->assertJsonPath('data.budget', 70000);
        DB::table('service_requests')->where('id', $old->id)->update(['budget' => null, 'budget_basis' => null]);
        $this->getJson("/api/v1/jobs/{$old->id}")->assertJsonPath('data.budget_type', 'none');
        DB::table('service_requests')->where('id', $old->id)->update(['budget' => 70000, 'budget_basis' => 'negotiable']);
        $this->getJson("/api/v1/jobs/{$old->id}")->assertJsonPath('data.budget_type', 'negotiable');
    }

    public function test_a_request_to_one_provider_takes_a_budget_type_too()
    {
        $this->switchOn();
        $provider = $this->provider();
        $customer = User::factory()->create();

        $this->sendRequest($provider, $customer, ['budget_type' => 'range', 'budget_min' => 20000, 'budget_max' => 45000])->assertCreated()
            ->assertJsonPath('data.budget_type', 'range')->assertJsonPath('data.budget_min', 20000)->assertJsonPath('data.budget_max', 45000)->assertJsonPath('data.budget', null);
        $this->sendRequest($provider, $customer, ['budget_type' => 'range', 'budget_min' => 20000])->assertStatus(422)->assertJsonValidationErrors('budget_max');

        // The provider sees it the same way, and the chat card carries it
        $this->signIn($provider->owner);
        $received = $this->getJson('/api/v1/me/received-requests')->assertOk()->json('data.0');
        $this->assertSame('range', $received['budget_type']);
        $this->assertSame(45000, $received['budget_max']);
        $conversation = $this->postJson("/api/v1/me/received-requests/{$received['id']}/message")->json('data.conversation_id');
        $card = collect($this->getJson("/api/v1/me/conversations/{$conversation}")->assertOk()->json('data.messages'))->firstWhere('kind', 'request');
        $this->assertSame('range', $card['request']['budget_type']);
        $this->assertSame(20000, $card['request']['budget_min']);
        $this->assertFalse($card['request']['inspection_required']);
    }

    /* ---------------- Provider estimates ---------------- */

    public function test_a_provider_applies_with_a_fixed_range_or_inspection_first_estimate()
    {
        $this->switchOn();
        $customer = User::factory()->create();
        $job = $this->postJob($customer, ['budget_type' => 'negotiable'])->json('data.id');
        $a = $this->provider();
        $b = $this->provider();
        $c = $this->provider();
        $d = $this->provider();

        $this->apply($a, $job, ['estimate_type' => 'fixed', 'amount' => 45000])->assertCreated();
        $this->apply($b, $job, ['estimate_type' => 'range', 'amount' => 30000, 'amount_max' => 60000])->assertCreated();
        // Inspection first: no figure at all, whatever was sent
        $this->apply($c, $job, ['estimate_type' => 'inspection_required', 'amount' => 99000, 'amount_max' => 120000])->assertCreated();
        // An older app: just an amount
        $this->apply($d, $job, ['amount' => 38000])->assertCreated();

        $by = function (ProviderProfile $p) {
            return Quotation::where('provider_profile_id', $p->id)->first();
        };
        $this->assertSame(['fixed', 45000, null], [$by($a)->estimate_type, $by($a)->amount, $by($a)->amount_max]);
        $this->assertSame(['range', 30000, 60000], [$by($b)->estimate_type, $by($b)->amount, $by($b)->amount_max]);
        $this->assertSame(['inspection_required', null, null], [$by($c)->estimate_type, $by($c)->amount, $by($c)->amount_max]);
        $this->assertSame(['fixed', 38000, null], [$by($d)->estimate_type, $by($d)->amount, $by($d)->amount_max]);

        // The customer sees them as estimates, and the provider sees their own
        $this->signIn($customer);
        $seen = collect($this->getJson("/api/v1/me/jobs/{$job}/applications")->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('range', $seen[$by($b)->id]['estimate_type']);
        $this->assertSame(60000, $seen[$by($b)->id]['amount_max']);
        $this->assertSame('inspection_required', $seen[$by($c)->id]['estimate_type']);
        $this->assertNull($seen[$by($c)->id]['amount']);

        $this->signIn($b->owner);
        $mine = $this->getJson('/api/v1/me/applications')->assertOk()->json('data.0');
        $this->assertSame('range', $mine['estimate_type']);
        $this->assertSame(60000, $mine['amount_max']);
        $this->assertSame('negotiable', $mine['job']['budget_type']);

        // The estimate is audited
        $this->assertTrue(AuditLog::where('action', 'marketplace.applied')->where('actor_id', $b->owner_id)->get()
            ->contains(function ($log) {
                return ($log->after['estimate_type'] ?? null) === 'range' && ($log->after['amount_max'] ?? null) === 60000;
            }));
    }

    public function test_estimates_are_checked_by_the_server()
    {
        $this->switchOn();
        $job = $this->postJob(User::factory()->create())->json('data.id');
        $provider = $this->provider();

        $this->apply($provider, $job, ['estimate_type' => 'fixed'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->apply($provider, $job, ['estimate_type' => 'range', 'amount' => 30000])->assertStatus(422)->assertJsonValidationErrors('amount_max');
        $this->apply($provider, $job, ['estimate_type' => 'range', 'amount' => 30000, 'amount_max' => 30000])->assertStatus(422)->assertJsonValidationErrors('amount_max');
        $this->apply($provider, $job, ['estimate_type' => 'range', 'amount' => 30000, 'amount_max' => 20000])->assertStatus(422)->assertJsonValidationErrors('amount_max');
        $this->apply($provider, $job, ['estimate_type' => 'guess', 'amount' => 30000])->assertStatus(422)->assertJsonValidationErrors('estimate_type');
        $this->apply($provider, $job, ['estimate_type' => 'fixed', 'amount' => 500])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame(0, Quotation::count());
    }

    public function test_inspection_and_listed_prices_never_change_a_requests_budget()
    {
        $this->switchOn();
        $customer = User::factory()->create();
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $this->postJson('/api/v1/me/provider/services', ['title' => 'Pipe repair', 'pricing_type' => 'from', 'price' => 30000, 'category' => 'plumbing'])->assertCreated();

        $id = $this->sendRequest($provider, $customer, ['budget_type' => 'fixed', 'budget' => 20000])->assertCreated()->json('data.id');
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertOk();

        // The customer's budget is untouched by the provider's listed "from" price and by marking inspection
        $this->assertSame(20000, ServiceRequest::find($id)->budget);
        $this->assertSame('fixed', ServiceRequest::find($id)->budget_type);
    }

    /* ---------------- The skill applied with ---------------- */

    public function test_an_application_records_a_validated_profession()
    {
        $this->switchOn();
        $plumbing = Category::where('slug', 'plumbing')->first();
        $pipeFitter = Profession::create(['category_id' => $plumbing->id, 'slug' => 'pipe-fitter', 'name' => 'Pipe fitter', 'plural_name' => 'Pipe fitters']);
        $job = $this->postJob(User::factory()->create())->json('data.id');

        // One fitting skill, no choice sent (an older app): that one
        $single = $this->provider(['plumber', 'electrician']);
        $this->apply($single, $job)->assertCreated();
        $this->assertSame('plumber', Quotation::where('provider_profile_id', $single->id)->first()->profession->slug);

        // Two fitting skills, the main one is plumbing: it is the default; a valid choice wins
        $two = $this->provider(['plumber']);
        $two->professions()->attach($pipeFitter->id, ['is_primary' => false]);
        $this->apply($two, $job, ['profession' => 'pipe-fitter'])->assertCreated();
        $this->assertSame('pipe-fitter', Quotation::where('provider_profile_id', $two->id)->first()->profession->slug);

        // Several fit and none is their main skill: an older app's application is saved without a guess
        $job2 = $this->postJob(User::factory()->create())->json('data.id');
        $unclear = $this->provider(['electrician']);
        $unclear->professions()->attach([Profession::where('slug', 'plumber')->value('id') => ['is_primary' => false], $pipeFitter->id => ['is_primary' => false]]);
        $this->apply($unclear, $job2)->assertCreated();
        $this->assertNull(Quotation::where('provider_profile_id', $unclear->id)->first()->profession_id);
    }

    public function test_a_provider_cannot_apply_as_a_skill_they_do_not_hold_or_that_does_not_fit_the_job()
    {
        $this->switchOn();
        $job = $this->postJob(User::factory()->create())->json('data.id');
        $provider = $this->provider(['plumber', 'electrician']);

        // Not theirs at all
        $this->apply($provider, $job, ['profession' => 'carpenter'])->assertStatus(422)->assertJson(['code' => 'profession_invalid']);
        // Theirs, but not in this job's category
        $this->apply($provider, $job, ['profession' => 'electrician'])->assertStatus(422)->assertJson(['code' => 'profession_invalid']);
        $this->assertSame(0, Quotation::count());
        $this->apply($provider, $job, ['profession' => 'plumber'])->assertCreated();
    }

    public function test_the_application_options_say_which_skills_fit_and_whether_to_ask()
    {
        $this->switchOn();
        $plumbing = Category::where('slug', 'plumbing')->first();
        $pipeFitter = Profession::create(['category_id' => $plumbing->id, 'slug' => 'pipe-fitter', 'name' => 'Pipe fitter', 'plural_name' => 'Pipe fitters']);
        $customer = User::factory()->create();
        $job = $this->postJob($customer)->json('data.id');

        $main = $this->provider(['plumber', 'electrician']);
        $this->signIn($main->owner);
        $this->getJson("/api/v1/jobs/{$job}/application-options")->assertOk()
            ->assertJsonPath('data.pricing_enabled', true)->assertJsonPath('data.default', 'plumber')->assertJsonPath('data.must_choose', false)
            ->assertJsonCount(1, 'data.professions');

        $unclear = $this->provider(['electrician']);
        $unclear->professions()->attach([Profession::where('slug', 'plumber')->value('id') => ['is_primary' => false], $pipeFitter->id => ['is_primary' => false]]);
        $this->signIn($unclear->owner);
        $this->getJson("/api/v1/jobs/{$job}/application-options")->assertOk()
            ->assertJsonPath('data.must_choose', true)->assertJsonPath('data.default', null)->assertJsonCount(2, 'data.professions');

        // Customers, other accounts, own jobs and jobs that are no longer open
        $this->signIn(User::factory()->create());
        $this->getJson("/api/v1/jobs/{$job}/application-options")->assertNotFound();
        $this->signIn($customer);
        $this->getJson("/api/v1/jobs/{$job}/application-options")->assertNotFound();
        ServiceRequest::whereKey($job)->update(['status' => 'cancelled']);
        $this->signIn($main->owner);
        $this->getJson("/api/v1/jobs/{$job}/application-options")->assertNotFound();
    }

    public function test_the_application_options_need_a_signed_in_account()
    {
        $job = $this->postJob(User::factory()->create())->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json'])->getJson("/api/v1/jobs/{$job}/application-options")->assertStatus(401);
    }

    /* ---------------- Inspection ---------------- */

    private function inspectionFixture(): array
    {
        $this->switchOn();
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->sendRequest($provider, $customer)->assertCreated()->json('data.id');

        return [$provider, $customer, $id];
    }

    public function test_the_owner_marks_a_job_as_needing_inspection_and_the_customer_is_told()
    {
        [$provider, $customer, $id] = $this->inspectionFixture();

        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true, 'note' => '  I need to see   the pipe first. '])->assertOk()
            ->assertJsonPath('data.inspection_required', true)->assertJsonPath('data.inspection_note', 'I need to see the pipe first.');

        $request = ServiceRequest::find($id);
        $this->assertNotNull($request->inspection_required_at);
        $this->assertTrue(AuditLog::where('action', 'marketplace.inspection_required')->where('actor_id', $provider->owner_id)->where('subject_id', $id)->exists());
        $this->assertSame(1, UserNotification::where('user_id', $customer->id)->where('title', 'Inspection needed')->count());

        // The customer sees it; nothing is priced
        $this->signIn($customer);
        $mine = collect($this->getJson('/api/v1/me/requests')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertTrue($mine['inspection_required']);
        $this->assertSame('I need to see the pipe first.', $mine['inspection_note']);

        // Taking it back
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => false])->assertOk()->assertJsonPath('data.inspection_required', false)->assertJsonPath('data.inspection_note', null);
        $this->assertNull(ServiceRequest::find($id)->inspection_required_at);
        $this->assertTrue(AuditLog::where('action', 'marketplace.inspection_cleared')->where('subject_id', $id)->exists());
    }

    public function test_doing_the_same_thing_twice_changes_and_records_nothing_more()
    {
        [$provider, $customer, $id] = $this->inspectionFixture();
        $this->signIn($provider->owner);

        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertOk();
        $first = ServiceRequest::find($id)->inspection_required_at;
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertOk();
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertOk();

        $this->assertEquals($first, ServiceRequest::find($id)->inspection_required_at);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.inspection_required')->where('subject_id', $id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $customer->id)->where('title', 'Inspection needed')->count());

        // Taking back what was never set does nothing either
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => false])->assertOk();
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => false])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'marketplace.inspection_cleared')->where('subject_id', $id)->count());
    }

    public function test_only_the_owner_or_a_manager_can_set_inspection_and_nobody_else_can_touch_it()
    {
        [$provider, $customer, $id] = $this->inspectionFixture();
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $provider->members()->create(['user_id' => $manager->id, 'role' => ProviderMember::ROLE_MANAGER]);
        $provider->members()->create(['user_id' => $member->id, 'role' => ProviderMember::ROLE_MEMBER]);

        // An ordinary member can see the request but doesn't make money statements
        $this->signIn($member);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertStatus(403)->assertJson(['code' => 'not_authorised']);
        $this->assertNull(ServiceRequest::find($id)->inspection_required_at);

        // A manager can
        $this->signIn($manager);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertOk();
        $this->assertTrue(AuditLog::where('action', 'marketplace.inspection_required')->where('actor_id', $manager->id)->exists());

        // Another provider business, the customer and a stranger find nothing
        $other = $this->provider();
        foreach ([$other->owner, $customer, User::factory()->create()] as $outsider) {
            $this->signIn($outsider);
            $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => false])->assertNotFound();
        }
        $this->assertNotNull(ServiceRequest::find($id)->inspection_required_at);

        // Not signed in
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json'])->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => false])->assertStatus(401);
    }

    public function test_inspection_follows_the_existing_rules_for_providers_and_requests()
    {
        [$provider, $customer, $id] = $this->inspectionFixture();
        $this->signIn($provider->owner);

        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", [])->assertStatus(422)->assertJsonValidationErrors('required');
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true, 'note' => str_repeat('a', 301)])->assertStatus(422)->assertJsonValidationErrors('note');

        // A finished or cancelled job is no longer priced
        ServiceRequest::whereKey($id)->update(['status' => 'completed']);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertStatus(409)->assertJson(['code' => 'request_status_conflict']);
        ServiceRequest::whereKey($id)->update(['status' => 'cancelled']);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertStatus(409);

        // A request from before bookings existed keeps its original steps
        ServiceRequest::whereKey($id)->update(['status' => 'open', 'booking_version' => ServiceRequest::BOOKING_LEGACY]);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertStatus(409)->assertJson(['code' => 'legacy_request']);

        // A suspended provider can't
        ServiceRequest::whereKey($id)->update(['booking_version' => ServiceRequest::BOOKING_V2]);
        $provider->forceFill(['status' => ProviderProfile::STATUS_SUSPENDED])->save();
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertStatus(403)->assertJson(['code' => 'provider_not_active']);
        $this->assertNull(ServiceRequest::find($id)->inspection_required_at);
    }

    public function test_inspection_works_on_an_accepted_job_and_does_not_change_its_status()
    {
        [$provider, $customer, $id] = $this->inspectionFixture();
        ServiceRequest::whereKey($id)->update(['status' => 'scheduled', 'scheduled_start_at' => now()->addDay(), 'scheduled_end_at' => now()->addDay()->addHours(2)]);
        $this->signIn($provider->owner);

        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true])->assertOk()
            ->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.inspection_required', true);
        ServiceRequest::whereKey($id)->update(['status' => 'in_progress']);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => false])->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    /* ---------------- Listed price ranges ---------------- */

    public function test_a_provider_can_list_a_price_range()
    {
        $this->switchOn();
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $add = function (array $data) {
            return $this->postJson('/api/v1/me/provider/services', $data + ['title' => 'Pipe repair', 'category' => 'plumbing', 'price_unit' => 'visit']);
        };

        $id = $add(['pricing_type' => 'range', 'price' => 20000, 'price_max' => 45000])->assertCreated()
            ->assertJsonPath('data.pricing_type', 'range')->assertJsonPath('data.price', 20000)->assertJsonPath('data.price_max', 45000)->json('data.id');

        $add(['pricing_type' => 'range', 'price' => 20000])->assertStatus(422);
        $add(['pricing_type' => 'range', 'price' => 20000, 'price_max' => 20000])->assertStatus(422);
        $add(['pricing_type' => 'range', 'price' => 30000, 'price_max' => 20000])->assertStatus(422);
        $add(['pricing_type' => 'range', 'price_max' => 20000])->assertStatus(422);

        // A top price on any other kind is dropped
        $add(['pricing_type' => 'from', 'price' => 20000, 'price_max' => 90000])->assertCreated()->assertJsonPath('data.price_max', null);
        $add(['pricing_type' => 'quote', 'price_max' => 90000])->assertCreated()->assertJsonPath('data.price_max', null);

        // Customers see the range; changing the kind clears it
        $this->signIn(User::factory()->create());
        $listed = collect($this->getJson("/api/v1/providers/{$provider->id}/services")->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame([20000, 45000], [$listed['price'], $listed['price_max']]);
        $this->signIn($provider->owner);
        $this->patchJson("/api/v1/me/provider/services/{$id}", ['pricing_type' => 'fixed', 'price' => 30000])->assertOk()->assertJsonPath('data.price_max', null);
    }

    public function test_a_range_already_listed_stays_editable_when_pricing_options_are_switched_off()
    {
        $this->switchOn();
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $id = $this->postJson('/api/v1/me/provider/services', ['title' => 'Pipe repair', 'category' => 'plumbing', 'pricing_type' => 'range', 'price' => 20000, 'price_max' => 45000])->json('data.id');

        $this->switchOn(false);
        $this->patchJson("/api/v1/me/provider/services/{$id}", ['title' => 'Pipe repairs'])->assertOk()->assertJsonPath('data.price_max', 45000);
        $this->patchJson("/api/v1/me/provider/services/{$id}", ['price_max' => 50000])->assertOk()->assertJsonPath('data.price_max', 50000);
        // But no new one
        $this->postJson('/api/v1/me/provider/services', ['title' => 'Another', 'category' => 'plumbing', 'pricing_type' => 'range', 'price' => 20000, 'price_max' => 45000])->assertStatus(422);
    }

    public function test_another_provider_cannot_change_a_providers_price_range()
    {
        $this->switchOn();
        $provider = $this->provider();
        $this->signIn($provider->owner);
        $id = $this->postJson('/api/v1/me/provider/services', ['title' => 'Pipe repair', 'category' => 'plumbing', 'pricing_type' => 'range', 'price' => 20000, 'price_max' => 45000])->json('data.id');

        $other = $this->provider();
        $this->signIn($other->owner);
        $this->patchJson("/api/v1/me/provider/services/{$id}", ['price_max' => 1000000])->assertNotFound();
        $this->assertSame(45000, DB::table('provider_services')->where('id', $id)->value('price_max'));
    }

    /* ---------------- Data isolation and scope ---------------- */

    public function test_nobody_else_sees_a_requests_inspection_note_or_its_budget_details()
    {
        [$provider, $customer, $id] = $this->inspectionFixture();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/inspection", ['required' => true, 'note' => 'Private note for the customer.'])->assertOk();

        // Another customer's own list never holds it, and the request itself is not theirs to open
        $stranger = User::factory()->create();
        $this->signIn($stranger);
        $this->assertSame([], $this->getJson('/api/v1/me/requests')->json('data'));
        $this->getJson("/api/v1/jobs/{$id}")->assertNotFound();

        // A direct request is never in the public job list, so its note can't be read there
        $this->app['auth']->forgetGuards();
        $this->assertStringNotContainsString('Private note', $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/v1/jobs')->getContent());

        // Another provider doesn't have it among their requests
        $other = $this->provider();
        $this->signIn($other->owner);
        $this->assertSame([], $this->getJson('/api/v1/me/received-requests')->json('data'));
    }

    public function test_this_phase_adds_no_commission_ledger_payment_or_agreement_tables()
    {
        // (price agreements arrived with Phase B2; payments, commissions and the rest are still to come)
        foreach (['commission_policies', 'ledger_entries', 'settlement_cycles', 'job_payments', 'provider_subscriptions'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "$table must not exist yet");
        }
    }
}
