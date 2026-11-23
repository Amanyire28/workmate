<?php

namespace Tests\Feature\Api;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CommissionShadowPolicy;
use App\Models\CommissionShadowSnapshot;
use App\Models\JobPaymentReport;
use App\Models\Profession;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\CommissionShadow;
use App\Services\PlatformSettings;
use App\Services\ProviderApplications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Phase B4: SHADOW-mode commission estimates. WorkMate works out what it COULD earn from payments the customer confirmed,
 * with rate rules the administrators control. Every figure is an estimate ("Estimated — Not Collected"): nothing is
 * charged, nobody owes anything, and nothing is shown to providers or customers.
 */
class CommissionShadowTest extends ApiTestCase
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

    /** Payment reporting and the shadow estimates both on */
    private function enable(bool $shadow = true): void
    {
        $this->setting('finance.pricing_enabled', true);
        $this->setting('finance.commission_shadow_enabled', $shadow);
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

    private function request(ProviderProfile $provider, User $customer, array $extra = []): int
    {
        $this->signIn($customer);

        return $this->postJson("/api/v1/providers/{$provider->id}/requests", $extra + [
            'category' => 'plumbing', 'description' => 'The kitchen sink is leaking badly.', 'area' => 'kisaasi', 'preferred_time' => 'Tomorrow morning',
        ])->assertCreated()->json('data.id');
    }

    /** A job under way with a price agreed: [provider, customer, request id] */
    private function priced(?ProviderProfile $provider = null, int $price = 1000000): array
    {
        $provider = $provider ?: $this->provider();
        $customer = User::factory()->create();
        $id = $this->request($provider, $customer);
        ServiceRequest::whereKey($id)->update(['status' => 'in_progress']);
        $this->signIn($provider->owner);
        $version = $this->postJson("/api/v1/me/received-requests/{$id}/agreements", ['amount' => $price, 'scope' => 'Replace the kitchen sink pipes and fit a new tap.'])->assertCreated()->json('data.pricing.open.id');
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/agreements/{$version}/accept", ['amount' => $price])->assertOk();

        return [$provider, $customer, $id];
    }

    /** The provider reports a payment (not yet confirmed); returns its id */
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

    /** A payment reported and confirmed by the customer; returns the report id */
    private function confirmed(ProviderProfile $provider, User $customer, int $id, int $amount): int
    {
        $payment = $this->reported($provider, $id, $amount);
        $this->confirmPayment($customer, $id, $payment, $amount);

        return $payment;
    }

    /** Preview and save a rule as an administrator; returns its id */
    private function rule(array $scope, int $bp, array $extra = [], ?User $admin = null): int
    {
        // Starts "now" unless told otherwise (a few seconds back, within the one-minute tolerance for a clock a little behind)
        $body = array_merge($scope, ['rate_bp' => $bp, 'effective_from' => now()->subSeconds(30)->toIso8601String()], $extra);
        $this->signIn($admin ?: $this->root);
        $preview = $this->postJson('/api/v1/admin/commission/policies/simulate', $body)->assertOk()->json('data');
        $this->assertTrue($preview['valid'], json_encode($preview['conflicts']));

        return $this->postJson('/api/v1/admin/commission/policies', $body + ['token' => $preview['token'], 'confirm_rate_bp' => $bp, 'reason' => 'Testing the rate'])->assertCreated()->json('data.id');
    }

    private function snapshot(int $payment): ?CommissionShadowSnapshot
    {
        return CommissionShadowSnapshot::where('payment_report_id', $payment)->where('current_slot', 1)->first();
    }

    private function global(int $bp = 500): int
    {
        return $this->rule(['scope_type' => 'global'], $bp);
    }

    /* ---------------- The calculation ---------------- */

    public function test_the_commission_is_whole_number_arithmetic_rounded_half_up()
    {
        // The worked example: 5% of UGX 100,000 is UGX 5,000
        $this->assertSame(5000, CommissionShadow::commission(100000, 500));
        $this->assertSame(95000, 100000 - CommissionShadow::commission(100000, 500));

        // Rounding: half up, on each payment
        $cases = [
            [33333, 500, 1667],     // 1,666.65 rounds up
            [33330, 500, 1667],     // 1,666.5 rounds up (the half)
            [33329, 500, 1666],     // 1,666.45 rounds down
            [110, 500, 6],          // 5.5 rounds up
            [101, 500, 5],          // 5.05 rounds down
            [99999, 500, 5000],     // 4,999.95 rounds up
            [100, 500, 5],
            [100, 1, 0],            // 0.01 rounds down to nothing
            [100, 50, 1],           // 0.5 rounds up
            [1000000, 250, 25000],  // 2.5%
            [100000000, 2000, 20000000],   // the largest payment at the 20% ceiling
            [100000, 0, 0],         // a 0% rate
            [100000, 10000, 100000],
        ];
        foreach ($cases as [$amount, $bp, $expected]) {
            $got = CommissionShadow::commission($amount, $bp);
            $this->assertSame($expected, $got, "{$amount} at {$bp} bp");
            $this->assertTrue(is_int($got));
        }
    }

    /* ---------------- The switches ---------------- */

    public function test_shadow_mode_and_pricing_are_off_by_default_and_the_ceiling_is_a_limit_not_a_rate()
    {
        $settings = app(PlatformSettings::class);
        $this->assertFalse($settings->get('finance.commission_shadow_enabled'));
        $this->assertFalse($settings->get('finance.pricing_enabled'));
        $this->assertSame(2000, $settings->get('finance.commission_max_bp'));
        // Nothing is seeded: no rate exists until an administrator creates one
        $this->assertSame(0, CommissionShadowPolicy::count());
        // The shadow switch is not offered to the apps
        $this->assertArrayNotHasKey('finance.commission_shadow_enabled', $this->getJson('/api/v1/settings')->json('data'));
    }

    public function test_with_the_shadow_switch_off_no_estimate_is_made_even_with_a_rule_and_confirmed_payments()
    {
        $this->enable(false);
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);

        $this->assertSame(0, CommissionShadowSnapshot::count());
        $this->artisan('workmate:commission-shadow')->expectsOutput('Shadow commission calculations are off (finance.commission_shadow_enabled). Nothing done.')->assertExitCode(0);
        $this->assertSame(0, CommissionShadowSnapshot::count());
        $this->assertSame(JobPaymentReport::CONFIRMED, JobPaymentReport::find($payment)->status);
    }

    public function test_shadow_mode_is_independent_of_pricing_and_turns_nothing_else_on()
    {
        // Shadow on with pricing off: payments can't be reported, so nothing is estimated, and nothing breaks
        $this->setting('finance.commission_shadow_enabled', true);
        $this->assertFalse(app(PlatformSettings::class)->get('finance.pricing_enabled'));
        $provider = $this->provider();
        $customer = User::factory()->create();
        $id = $this->request($provider, $customer);
        ServiceRequest::whereKey($id)->update(['status' => 'in_progress']);
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/payments", ['amount' => 5000, 'method' => 'cash'])->assertStatus(409)->assertJson(['code' => 'pricing_unavailable']);
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);
        $this->assertSame([0, 0], [JobPaymentReport::count(), CommissionShadowSnapshot::count()]);
        // ...and the reverse: pricing on, shadow off
        $this->setting('finance.commission_shadow_enabled', false);
        $this->setting('finance.pricing_enabled', true);
        $this->assertFalse(app(CommissionShadow::class)->enabled());
    }

    /* ---------------- Rules: preview, saving and safeguards ---------------- */

    public function test_a_rule_needs_a_preview_the_rate_typed_again_and_a_reason()
    {
        $this->enable();
        $body = ['scope_type' => 'global', 'rate_bp' => 500, 'effective_from' => now()->toIso8601String()];
        $this->signIn($this->root);

        // The preview shows what the rate means, labelled as an estimate, and writes nothing
        $preview = $this->postJson('/api/v1/admin/commission/policies/simulate', $body)->assertOk()->json('data');
        $this->assertSame([true, '5%', 'Estimated — Not Collected'], [$preview['valid'], $preview['percent'], $preview['label']]);
        $this->assertSame([[50000, 2500, 47500], [200000, 10000, 190000], [1000000, 50000, 950000]], array_map(function ($e) {
            return [$e['amount_ugx'], $e['commission_ugx'], $e['provider_remainder_ugx']];
        }, $preview['examples']));
        $this->assertStringContainsString('Nothing is charged', $preview['note']);
        $this->assertSame(0, CommissionShadowPolicy::count());

        $save = function (array $extra) use ($body, $preview) {
            return $this->postJson('/api/v1/admin/commission/policies', array_merge($body, ['token' => $preview['token'], 'confirm_rate_bp' => 500, 'reason' => 'Starting estimate'], $extra));
        };
        $save(['token' => null])->assertStatus(422);
        $save(['token' => 'tampered'])->assertStatus(422);
        $save(['token' => str_repeat('1', 10).'.'.str_repeat('a', 64)])->assertStatus(422)->assertJson(['code' => 'preview_required']);
        $save(['confirm_rate_bp' => 600])->assertStatus(422)->assertJson(['code' => 'confirmation_mismatch']);
        $save(['confirm_rate_bp' => null])->assertStatus(422);
        $save(['reason' => ''])->assertStatus(422);
        $save(['reason' => 'abc'])->assertStatus(422)->assertJson(['code' => 'reason_required']);
        // A preview is for exactly that rule: another rate, scope or date is refused with the same token
        $save(['rate_bp' => 450, 'confirm_rate_bp' => 450])->assertStatus(422)->assertJson(['code' => 'preview_required']);
        $save(['scope_type' => 'category', 'scope_ref' => Category::value('id')])->assertStatus(422)->assertJson(['code' => 'preview_required']);
        $this->assertSame(0, CommissionShadowPolicy::count());

        // And it is for the administrator who previewed it
        $other = $this->userWithRoles(['super_admin']);
        $this->signIn($other);
        $this->postJson('/api/v1/admin/commission/policies', $body + ['token' => $preview['token'], 'confirm_rate_bp' => 500, 'reason' => 'Starting estimate'])->assertStatus(422)->assertJson(['code' => 'preview_required']);

        $this->signIn($this->root);
        $save([])->assertCreated()->assertJsonPath('data.percent', '5%')->assertJsonPath('data.state', 'active')->assertJsonPath('data.version', 1);
        $this->assertSame(1, CommissionShadowPolicy::count());
    }

    public function test_a_stale_preview_is_refused()
    {
        $this->enable();
        $body = ['scope_type' => 'global', 'rate_bp' => 500, 'effective_from' => now()->addHours(2)->toIso8601String()];
        $this->signIn($this->root);
        $preview = $this->postJson('/api/v1/admin/commission/policies/simulate', $body)->json('data');
        $this->travel(31)->minutes();
        $this->postJson('/api/v1/admin/commission/policies', $body + ['token' => $preview['token'], 'confirm_rate_bp' => 500, 'reason' => 'Starting estimate'])->assertStatus(422)->assertJson(['code' => 'preview_required']);
        $this->assertSame(0, CommissionShadowPolicy::count());
    }

    public function test_rates_are_whole_basis_points_within_the_ceiling_and_dates_cannot_be_in_the_past()
    {
        $this->enable();
        $this->signIn($this->root);
        $future = now()->addDay()->toIso8601String();
        $sim = function (array $extra) use ($future) {
            return $this->postJson('/api/v1/admin/commission/policies/simulate', $extra + ['scope_type' => 'global', 'rate_bp' => 500, 'effective_from' => $future]);
        };

        $sim(['rate_bp' => 2001])->assertStatus(422)->assertJson(['code' => 'rate_above_ceiling']);
        $sim(['rate_bp' => 2000])->assertOk();
        $sim(['rate_bp' => 0])->assertOk()->assertJsonPath('data.percent', '0%');
        $sim(['rate_bp' => -1])->assertStatus(422);
        $sim(['rate_bp' => 12.5])->assertStatus(422);
        $sim(['rate_bp' => '5%'])->assertStatus(422);
        $sim(['rate_bp' => null])->assertStatus(422);
        $sim(['rate_bp' => 10001])->assertStatus(422);
        $sim(['scope_type' => 'planet'])->assertStatus(422)->assertJson(['code' => 'scope_invalid']);
        $sim(['scope_type' => 'category', 'scope_ref' => 99999])->assertStatus(422)->assertJson(['code' => 'scope_invalid']);
        $sim(['scope_type' => 'category'])->assertStatus(422)->assertJson(['code' => 'scope_invalid']);
        $sim(['scope_type' => 'district', 'scope_key' => 'Atlantis'])->assertStatus(422)->assertJson(['code' => 'scope_invalid']);
        $sim(['effective_from' => now()->subDay()->toIso8601String()])->assertStatus(422)->assertJson(['code' => 'effective_in_past']);
        $sim(['effective_from' => 'whenever'])->assertStatus(422);
        $sim(['effective_to' => now()->subHour()->toIso8601String()])->assertStatus(422)->assertJson(['code' => 'dates_invalid']);
        $sim(['effective_to' => $future])->assertStatus(422)->assertJson(['code' => 'dates_invalid']);

        // The ceiling is a setting: lowering it applies to new rules
        $this->setting('finance.commission_max_bp', 1000);
        $sim(['rate_bp' => 1001])->assertStatus(422)->assertJson(['code' => 'rate_above_ceiling']);
        $sim(['rate_bp' => 1000])->assertOk();
    }

    public function test_two_rules_for_the_same_scope_cannot_overlap_but_can_follow_each_other()
    {
        $this->enable();
        $category = Category::where('slug', 'plumbing')->value('id');
        $first = $this->rule(['scope_type' => 'category', 'scope_ref' => $category], 800, ['effective_from' => now()->addDay()->toIso8601String(), 'effective_to' => now()->addDays(10)->toIso8601String()]);

        $this->signIn($this->root);
        $overlap = $this->postJson('/api/v1/admin/commission/policies/simulate', ['scope_type' => 'category', 'scope_ref' => $category, 'rate_bp' => 900, 'effective_from' => now()->addDays(5)->toIso8601String()])->assertOk()->json('data');
        $this->assertFalse($overlap['valid']);
        $this->assertSame($first, $overlap['conflicts'][0]['id']);
        $this->assertNull($overlap['token']);
        $this->postJson('/api/v1/admin/commission/policies', ['scope_type' => 'category', 'scope_ref' => $category, 'rate_bp' => 900, 'effective_from' => now()->addDays(5)->toIso8601String(), 'token' => 'x', 'confirm_rate_bp' => 900, 'reason' => 'Overlapping'])
            ->assertStatus(409)->assertJson(['code' => 'policy_overlap']);

        // Ending exactly when the next starts is fine; so is another scope at the same time
        $this->rule(['scope_type' => 'category', 'scope_ref' => $category], 900, ['effective_from' => now()->addDays(10)->toIso8601String()]);
        $this->rule(['scope_type' => 'category', 'scope_ref' => Category::where('slug', 'cleaning')->value('id')], 900, ['effective_from' => now()->addDays(5)->toIso8601String()]);
        $this->assertSame(3, CommissionShadowPolicy::count());
        // An open-ended rule blocks any later one in its scope
        $this->postJson('/api/v1/admin/commission/policies/simulate', ['scope_type' => 'category', 'scope_ref' => $category, 'rate_bp' => 700, 'effective_from' => now()->addDays(30)->toIso8601String()])->assertOk()->assertJsonPath('data.valid', false);
    }

    public function test_replacing_and_retiring_keep_every_version_and_end_the_old_one_when_the_new_starts()
    {
        $this->enable();
        $first = $this->global(500);
        $this->signIn($this->root);

        $start = now()->addDay();
        $body = ['rate_bp' => 700, 'effective_from' => $start->toIso8601String()];
        $preview = $this->postJson('/api/v1/admin/commission/policies/simulate', $body + ['replaces' => $first])->assertOk()->json('data');
        $this->assertSame([true, 'Everything (default)'], [$preview['valid'], $preview['replaces']['scope']]);
        $second = $this->postJson("/api/v1/admin/commission/policies/{$first}/replace", $body + ['token' => $preview['token'], 'confirm_rate_bp' => 700, 'reason' => 'A better estimate'])
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.replaces_id', $first)->json('data.id');

        $old = CommissionShadowPolicy::find($first);
        $this->assertSame([CommissionShadowPolicy::RETIRED, $second, 500], [$old->status, $old->replaced_by_id, $old->rate_bp]);
        $this->assertEquals($start->timestamp, $old->retired_at->timestamp);
        // Until the new one starts the old one still applies; from then on only the new one does
        $this->assertTrue($old->inForceAt(now()));
        $this->assertFalse($old->inForceAt($start->copy()->addSecond()));
        $this->assertTrue(CommissionShadowPolicy::find($second)->inForceAt($start->copy()->addSecond()));
        $this->assertFalse(CommissionShadowPolicy::find($second)->inForceAt(now()));

        // A replaced rule can't be replaced or retired again; a scheduled one can be retired before it starts
        $this->postJson("/api/v1/admin/commission/policies/{$first}/retire", ['reason' => 'Too late'])->assertStatus(409)->assertJson(['code' => 'already_retired']);
        $this->postJson("/api/v1/admin/commission/policies/{$second}/retire", ['reason' => 'Changed our mind'])->assertOk()->assertJsonPath('data.state', 'ended');
        $this->postJson("/api/v1/admin/commission/policies/{$second}/retire", ['reason' => 'Again please'])->assertStatus(409);
        $this->postJson("/api/v1/admin/commission/policies/{$second}/retire", ['reason' => ''])->assertStatus(422);
        $this->assertSame([1, 2], CommissionShadowPolicy::orderBy('version')->pluck('version')->all());

        // Everything was audited with who, why and before/after
        foreach (['commission.shadow_policy_created', 'commission.shadow_policy_replaced', 'commission.shadow_policy_retired'] as $action) {
            $log = AuditLog::where('action', $action)->first();
            $this->assertNotNull($log, $action);
            $this->assertSame($this->root->id, (int) $log->actor_id);
            $this->assertNotEmpty($log->reason);
        }
        $replaced = AuditLog::where('action', 'commission.shadow_policy_replaced')->first();
        $this->assertSame([500, 700], [$replaced->before['rate_bp'], $replaced->after['rate_bp']]);
    }

    public function test_saved_rules_and_estimates_can_never_be_edited_or_deleted()
    {
        $this->enable();
        $rule = $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);

        $policy = CommissionShadowPolicy::find($rule);
        foreach (['rate_bp' => 900, 'effective_from' => now()->addYear(), 'scope_hash' => 'x', 'reason' => 'edited', 'version' => 7] as $field => $value) {
            try {
                $policy->forceFill([$field => $value])->save();
                $this->fail("{$field} must not be changeable");
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be changed', $e->getMessage());
            }
            $policy = CommissionShadowPolicy::find($rule);
        }
        $row = $this->snapshot($payment);
        foreach (['amount_ugx' => 1, 'rate_bp' => 900, 'commission_ugx' => 1, 'provider_net_ugx' => 1, 'policy_id' => 99, 'status' => 'excluded', 'basis_at' => now()->subYear(), 'mode' => 'live'] as $field => $value) {
            try {
                $row->forceFill([$field => $value])->save();
                $this->fail("{$field} must not be changeable");
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be changed', $e->getMessage());
            }
            $row = $this->snapshot($payment);
        }
        foreach ([$policy, $row] as $model) {
            try {
                $model->delete();
                $this->fail('Must not be deletable');
            } catch (LogicException $e) {
                $this->assertTrue(true);
            }
        }
    }

    /* ---------------- Authorization ---------------- */

    public function test_the_new_permissions_are_given_to_the_right_roles_only()
    {
        $rolesFor = function (string $key) {
            return DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->join('roles', 'roles.id', '=', 'permission_role.role_id')
                ->where('permissions.key', $key)->pluck('roles.key')->sort()->values()->all();
        };
        $this->assertSame(['finance', 'super_admin'], $rolesFor('commission.view'));
        $this->assertSame(['super_admin'], $rolesFor('commission.manage'));
        // Nobody else: not operations, support, trust and safety, analytics, technical
        foreach (['operations', 'support', 'provider_review', 'trust_safety', 'content', 'analytics', 'technical'] as $role) {
            $this->assertFalse($this->userWithRoles([$role])->can('commission.view'), "{$role} must not view");
            $this->assertFalse($this->userWithRoles([$role])->can('commission.manage'), "{$role} must not manage");
        }
        $finance = $this->userWithRoles(['finance']);
        $this->assertTrue($finance->can('commission.view'));
        $this->assertFalse($finance->can('commission.manage'));
    }

    public function test_only_authorised_administrators_can_read_or_change_commission_rules()
    {
        $this->enable();
        $reads = ['summary', 'snapshots', 'policies', 'options', 'resolve?category=plumbing'];
        $body = ['scope_type' => 'global', 'rate_bp' => 500, 'effective_from' => now()->addDay()->toIso8601String()];

        // Not signed in
        foreach ($reads as $path) {
            $this->withHeaders(['Accept' => 'application/json'])->getJson("/api/v1/admin/commission/{$path}")->assertStatus(401);
        }
        $this->withHeaders(['Accept' => 'application/json'])->postJson('/api/v1/admin/commission/policies/simulate', $body)->assertStatus(401);

        // A customer, a provider, other administrators, and a finance administrator (who may look but not change)
        $provider = $this->provider();
        foreach ([User::factory()->create(), $provider->owner, $this->userWithRoles(['support']), $this->userWithRoles(['operations']), $this->userWithRoles(['trust_safety'])] as $nobody) {
            $this->signIn($nobody);
            foreach ($reads as $path) {
                $this->getJson("/api/v1/admin/commission/{$path}")->assertStatus(403);
            }
            $this->postJson('/api/v1/admin/commission/policies/simulate', $body)->assertStatus(403);
            $this->postJson('/api/v1/admin/commission/policies', $body + ['token' => 'x', 'confirm_rate_bp' => 500, 'reason' => 'Trying'])->assertStatus(403);
        }
        $finance = $this->userWithRoles(['finance']);
        $this->signIn($finance);
        foreach ($reads as $path) {
            $this->getJson("/api/v1/admin/commission/{$path}")->assertOk();
        }
        $this->postJson('/api/v1/admin/commission/policies/simulate', $body)->assertStatus(403);
        $this->postJson('/api/v1/admin/commission/policies', $body + ['token' => 'x', 'confirm_rate_bp' => 500, 'reason' => 'Trying'])->assertStatus(403);
        $rule = $this->global(500);
        $this->signIn($finance);
        $this->postJson("/api/v1/admin/commission/policies/{$rule}/retire", ['reason' => 'Trying to retire'])->assertStatus(403);
        $this->postJson("/api/v1/admin/commission/policies/{$rule}/replace", $body + ['token' => 'x', 'confirm_rate_bp' => 500, 'reason' => 'Trying'])->assertStatus(403);
        $this->assertSame('active', CommissionShadowPolicy::find($rule)->state());
        $this->assertSame(1, CommissionShadowPolicy::count());
    }

    /* ---------------- Which rule applies ---------------- */

    public function test_the_most_specific_rule_wins_and_a_tier_is_never_guessed()
    {
        $this->enable();
        [$provider, $customer, $id] = $this->priced();
        $request = ServiceRequest::find($id);
        $area = Area::find($request->area_id);
        $plumber = Profession::where('slug', 'plumber')->value('id');
        $category = Category::where('slug', 'plumbing')->value('id');
        $district = mb_strtolower(trim($area->district));

        $global = $this->rule(['scope_type' => 'global'], 1000);
        $districtRule = $this->rule(['scope_type' => 'district', 'scope_key' => $district], 900);
        $areaRule = $this->rule(['scope_type' => 'area', 'scope_ref' => $area->id], 800);
        $categoryRule = $this->rule(['scope_type' => 'category', 'scope_ref' => $category], 700);
        $professionRule = $this->rule(['scope_type' => 'profession', 'scope_ref' => $plumber], 600);
        $providerRule = $this->rule(['scope_type' => 'provider', 'scope_ref' => $provider->id], 400);

        $payment = fn (int $amount) => $this->confirmed($provider, $customer, $id, $amount);
        $expect = function (int $payment, string $tier, int $rule, int $bp) {
            $s = $this->snapshot($payment);
            $this->assertSame([CommissionShadowSnapshot::CALCULATED, $tier, $rule, $bp], [$s->status, $s->tier, $s->policy_id, $s->rate_bp], 'expected '.$tier);
        };

        // Provider → Profession → Category → Area → District → Global: retire the winner and the next one takes over
        $expect($payment(1000), 'provider', $providerRule, 400);
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/commission/policies/{$providerRule}/retire", ['reason' => 'Testing precedence'])->assertOk();
        $this->travel(2)->seconds();
        $expect($payment(1000), 'profession', $professionRule, 600);
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/commission/policies/{$professionRule}/retire", ['reason' => 'Testing precedence'])->assertOk();
        $this->travel(2)->seconds();
        $expect($payment(1000), 'category', $categoryRule, 700);
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/commission/policies/{$categoryRule}/retire", ['reason' => 'Testing precedence'])->assertOk();
        $this->travel(2)->seconds();
        $expect($payment(1000), 'area', $areaRule, 800);
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/commission/policies/{$areaRule}/retire", ['reason' => 'Testing precedence'])->assertOk();
        $this->travel(2)->seconds();
        $expect($payment(1000), 'district', $districtRule, 900);
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/commission/policies/{$districtRule}/retire", ['reason' => 'Testing precedence'])->assertOk();
        $this->travel(2)->seconds();
        $expect($payment(1000), 'global', $global, 1000);
        // The way the rule was chosen is kept: every tier looked at and why
        $trace = collect(CommissionShadowSnapshot::orderByDesc('id')->first()->trace)->pluck('result', 'tier')->all();
        $this->assertSame(['provider' => 'none', 'profession' => 'none', 'category' => 'none', 'area' => 'none', 'district' => 'none', 'global' => 'matched'], $trace);
    }

    public function test_the_profession_tier_is_skipped_when_the_profession_was_not_safely_derived()
    {
        $this->enable();
        $plumbing = Category::where('slug', 'plumbing')->first();
        $pipeFitter = Profession::create(['category_id' => $plumbing->id, 'slug' => 'pipe-fitter', 'name' => 'Pipe fitter', 'plural_name' => 'Pipe fitters']);
        $plumber = Profession::where('slug', 'plumber')->value('id');
        $this->rule(['scope_type' => 'profession', 'scope_ref' => $plumber], 300);
        $this->rule(['scope_type' => 'category', 'scope_ref' => $plumbing->id], 800);

        // Safe: the provider's only fitting profession, so the profession rate applies
        [$p1, $c1, $id1] = $this->priced();
        $this->assertSame([300, 'profession'], [$this->snapshot($this->confirmed($p1, $c1, $id1, 100000))->rate_bp, $this->snapshot(JobPaymentReport::orderByDesc('id')->value('id'))->tier]);

        // Several fit and the main one was assumed (flagged for review): not guessed, so the category rate applies
        $many = $this->provider(['plumber']);
        $many->professions()->attach($pipeFitter->id, ['is_primary' => false]);
        [$p2, $c2, $id2] = $this->priced($many);
        $s = $this->snapshot($this->confirmed($p2, $c2, $id2, 100000));
        $this->assertSame([800, 'category'], [$s->rate_bp, $s->tier]);
        $profession = collect($s->trace)->firstWhere('tier', 'profession');
        $this->assertSame(['skipped', 'profession_needs_review'], [$profession['result'], $profession['why']]);

        // None fits: the profession was left unresolved, so it is skipped too
        $none = $this->provider(['electrician']);
        $none->professions()->attach([$plumber => ['is_primary' => false], $pipeFitter->id => ['is_primary' => false]]);
        [$p3, $c3, $id3] = $this->priced($none);
        $s = $this->snapshot($this->confirmed($p3, $c3, $id3, 100000));
        $this->assertSame([800, 'category', 'profession_unresolved'], [$s->rate_bp, $s->tier, collect($s->trace)->firstWhere('tier', 'profession')['why']]);

        // A job with no agreement at all has no profession to judge by: the category rate applies
        [$p4, $c4] = [$this->provider(), User::factory()->create()];
        $id4 = $this->request($p4, $c4);
        ServiceRequest::whereKey($id4)->update(['status' => 'in_progress']);
        $s = $this->snapshot($this->confirmed($p4, $c4, $id4, 100000));
        $this->assertSame([800, 'category', 'no_agreement'], [$s->rate_bp, $s->tier, collect($s->trace)->firstWhere('tier', 'profession')['why']]);
    }

    public function test_two_rules_in_one_tier_are_ambiguous_so_no_rate_is_invented()
    {
        $this->enable();
        $category = Category::where('slug', 'plumbing')->value('id');
        $this->rule(['scope_type' => 'category', 'scope_ref' => $category], 800);
        $this->global(500);
        // Bypass the safeguards, as a bad restore or a manual edit could: a second rule for the same scope at the same time
        $first = CommissionShadowPolicy::where('scope_type', 'category')->first();
        DB::table('commission_shadow_policies')->insert([
            'scope_type' => 'category', 'scope_ref' => $category, 'scope_key' => null, 'scope_hash' => $first->scope_hash, 'version' => 2, 'rate_bp' => 900,
            'effective_from' => $first->effective_from, 'effective_to' => null, 'status' => 'active', 'reason' => 'Forced duplicate', 'created_by_user_id' => $this->root->id,
            'content_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$provider, $customer, $id] = $this->priced();
        $s = $this->snapshot($this->confirmed($provider, $customer, $id, 100000));
        // It does NOT fall through to the global rate: ambiguity is reported, not guessed around
        $this->assertSame([CommissionShadowSnapshot::EXCLUDED, 'ambiguous_policy', null, null], [$s->status, $s->exclusion_reason, $s->rate_bp, $s->commission_ugx]);
        $this->assertSame('ambiguous', collect($s->trace)->firstWhere('tier', 'category')['result']);
    }

    /* ---------------- Effective dates ---------------- */

    public function test_a_rule_is_judged_at_the_moment_the_customer_confirmed_the_payment()
    {
        $this->enable();
        [$provider, $customer, $id] = $this->priced();
        // The rule starts in two hours and runs for three
        $this->rule(['scope_type' => 'global'], 500, ['effective_from' => now()->addHours(2)->toIso8601String(), 'effective_to' => now()->addHours(5)->toIso8601String()]);

        $before = $this->confirmed($provider, $customer, $id, 100000);
        $this->assertSame(['excluded', 'no_policy'], [$this->snapshot($before)->status, $this->snapshot($before)->exclusion_reason]);

        $this->travel(3)->hours();
        $during = $this->confirmed($provider, $customer, $id, 100000);
        $this->assertSame([CommissionShadowSnapshot::CALCULATED, 5000, 95000], [$this->snapshot($during)->status, $this->snapshot($during)->commission_ugx, $this->snapshot($during)->provider_net_ugx]);

        $this->travel(3)->hours();
        $after = $this->confirmed($provider, $customer, $id, 100000);
        $this->assertSame(['excluded', 'no_policy'], [$this->snapshot($after)->status, $this->snapshot($after)->exclusion_reason]);
    }

    /* ---------------- Processing and eligibility ---------------- */

    public function test_a_confirmed_payment_gets_a_traceable_estimate_with_everything_recorded()
    {
        $this->enable();
        $rule = $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);
        $report = JobPaymentReport::find($payment);

        $s = $this->snapshot($payment);
        $this->assertSame(
            [$payment, $id, $provider->id, 1, CommissionShadowSnapshot::CALCULATED, 'shadow', 100000, $rule, 1, 'global', 'global', 500, 5000, 95000, 'half_up_per_payment', 1],
            [$s->payment_report_id, $s->service_request_id, $s->provider_profile_id, $s->revision_no, $s->status, $s->mode, $s->amount_ugx, $s->policy_id, $s->policy_version, $s->tier, $s->policy_scope, $s->rate_bp, $s->commission_ugx, $s->provider_net_ugx, $s->rounding, $s->engine_version]
        );
        $this->assertEquals($report->responded_at->timestamp, $s->basis_at->timestamp);
        $this->assertSame(Category::where('slug', 'plumbing')->value('id'), $s->category_id);
        $this->assertSame(Profession::where('slug', 'plumber')->value('id'), $s->profession_id);
        $this->assertNotNull(ServiceRequest::find($id)->area_id);
        $this->assertSame($s->area_id, ServiceRequest::find($id)->area_id);
        $this->assertSame('matched', collect($s->trace)->firstWhere('tier', 'global')['result']);
        // The payment report itself is untouched by the estimate
        $this->assertSame($report->getAttributes(), JobPaymentReport::find($payment)->getAttributes());
    }

    public function test_a_waiting_disputed_or_withdrawn_payment_earns_nothing()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $waiting = $this->reported($provider, $id, 10000);
        $disputed = $this->reported($provider, $id, 20000);
        $withdrawn = $this->reported($provider, $id, 30000);
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$disputed}/dispute", ['note' => 'Not right'])->assertOk();
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/payments/{$withdrawn}/cancel", ['reason' => 'Entered by mistake'])->assertOk();

        // Nothing is estimated for any of them, however often the catch-up runs
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);
        $this->assertSame(0, CommissionShadowSnapshot::count());
        // A finished job and an agreed price don't imply a payment either
        $this->signIn($provider->owner);
        $this->postJson("/api/v1/me/received-requests/{$id}/complete")->assertOk();
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);
        $this->assertSame(0, CommissionShadowSnapshot::count());

        // They show up as exclusions, with their reasons, and never as volume
        $this->signIn($this->root);
        $summary = $this->getJson('/api/v1/admin/commission/summary')->assertOk()->json('data');
        $this->assertSame(0, $summary['totals']['eligible_volume_ugx']);
        $this->assertSame(['awaiting_confirmation' => 10000, 'disputed' => 20000, 'withdrawn' => 30000], collect($summary['excluded']['by_reason'])->map(function ($r) {
            return $r['amount_ugx'];
        })->all());
        $this->assertSame(3, $summary['excluded']['payments']);
    }

    public function test_several_part_payments_are_each_estimated_once_and_never_double_counted()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced(null, 100000);
        $a = $this->confirmed($provider, $customer, $id, 33333);
        $b = $this->confirmed($provider, $customer, $id, 33333);
        $c = $this->confirmed($provider, $customer, $id, 33334);

        $this->assertSame([1667, 1667, 1667], [$this->snapshot($a)->commission_ugx, $this->snapshot($b)->commission_ugx, $this->snapshot($c)->commission_ugx]);
        $this->assertSame(3, CommissionShadowSnapshot::count());
        // Each payment is rounded on its own (documented): 5,001 here, against 5,000 on the whole UGX 100,000
        $this->assertSame(5001, (int) CommissionShadowSnapshot::sum('commission_ugx'));

        // Running it again, in any way, creates and changes nothing
        $this->artisan('workmate:commission-shadow')->expectsOutput('Created: 0  Superseded: 0  Unchanged: 0  (estimates only, not collected)')->assertExitCode(0);
        app(CommissionShadow::class)->process(JobPaymentReport::find($a));
        app(CommissionShadow::class)->process(JobPaymentReport::find($a));
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$a}/confirm", ['amount' => 33333])->assertOk();   // a double tap
        $this->assertSame([3, 1], [CommissionShadowSnapshot::count(), CommissionShadowSnapshot::where('payment_report_id', $a)->count()]);
        $this->assertSame(3, CommissionShadowSnapshot::whereNotNull('current_slot')->count());
    }

    public function test_the_catch_up_estimates_payments_confirmed_before_shadow_mode_was_switched_on()
    {
        $this->enable(false);
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $one = $this->confirmed($provider, $customer, $id, 100000);
        $two = $this->confirmed($provider, $customer, $id, 200000);
        $this->assertSame(0, CommissionShadowSnapshot::count());

        $this->setting('finance.commission_shadow_enabled', true);
        $this->artisan('workmate:commission-shadow')->expectsOutput('Created: 2  Superseded: 0  Unchanged: 0  (estimates only, not collected)')->assertExitCode(0);
        $this->assertSame([5000, 10000], [$this->snapshot($one)->commission_ugx, $this->snapshot($two)->commission_ugx]);
        $this->artisan('workmate:commission-shadow')->expectsOutput('Created: 0  Superseded: 0  Unchanged: 0  (estimates only, not collected)')->assertExitCode(0);
        $this->assertSame(2, CommissionShadowSnapshot::count());
    }

    public function test_a_rule_that_starts_later_never_reaches_back_to_an_earlier_payment()
    {
        $this->enable();
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);
        $first = $this->snapshot($payment);
        $this->assertSame(['excluded', 'no_policy'], [$first->status, $first->exclusion_reason]);

        // Nothing changes while there is still no rule
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);
        $this->assertSame(1, CommissionShadowSnapshot::where('payment_report_id', $payment)->count());

        // A rule can't start in the past, so it can't reach a payment confirmed before it: that one stays excluded
        $this->rule(['scope_type' => 'global'], 500, ['effective_from' => now()->addMinute()->toIso8601String()]);
        $this->travel(2)->minutes();
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);
        $this->assertSame([$first->id, 'excluded', 1], [$this->snapshot($payment)->id, $this->snapshot($payment)->status, CommissionShadowSnapshot::where('payment_report_id', $payment)->count()]);

        // A payment confirmed after the rule started is calculated
        $later = $this->confirmed($provider, $customer, $id, 100000);
        $this->assertSame([CommissionShadowSnapshot::CALCULATED, 5000], [$this->snapshot($later)->status, $this->snapshot($later)->commission_ugx]);
    }

    public function test_an_earlier_exclusion_is_superseded_when_a_rule_in_force_at_confirmation_exists_later()
    {
        $this->enable();
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);
        $first = $this->snapshot($payment);
        $this->assertSame('no_policy', $first->exclusion_reason);

        // A rule that WAS in force at confirmation time (put in place by a restore or a manual fix): the payment is re-judged
        DB::table('commission_shadow_policies')->insert([
            'scope_type' => 'global', 'scope_ref' => null, 'scope_key' => null, 'scope_hash' => 'global', 'version' => 1, 'rate_bp' => 500,
            'effective_from' => now()->subDay(), 'effective_to' => null, 'status' => 'active', 'reason' => 'Restored rule', 'created_by_user_id' => $this->root->id,
            'content_hash' => str_repeat('0', 64), 'created_at' => now()->addSecond(), 'updated_at' => now()->addSecond(),
        ]);
        $this->travel(2)->seconds();
        $this->artisan('workmate:commission-shadow')->expectsOutput('Created: 0  Superseded: 1  Unchanged: 0  (estimates only, not collected)')->assertExitCode(0);

        $now = $this->snapshot($payment);
        $this->assertSame([2, 'calculated', 5000, $first->id], [$now->revision_no, $now->status, $now->commission_ugx, $now->supersedes_id]);
        // The earlier row is exactly as it was, only no longer current
        $old = CommissionShadowSnapshot::find($first->id);
        $this->assertSame(['excluded', 'no_policy', null], [$old->status, $old->exclusion_reason, $old->commission_ugx]);
        $this->assertNull($old->current_slot);
        $this->assertNotNull($old->superseded_at);
        $this->assertSame(2, CommissionShadowSnapshot::where('payment_report_id', $payment)->count());
        // And once calculated it is never touched again
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);
        $this->assertSame(2, CommissionShadowSnapshot::where('payment_report_id', $payment)->count());
    }

    public function test_a_later_change_of_rule_never_rewrites_an_earlier_calculation()
    {
        $this->enable();
        $rule = $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $first = $this->confirmed($provider, $customer, $id, 100000);
        $this->assertSame([500, 5000, 1], [$this->snapshot($first)->rate_bp, $this->snapshot($first)->commission_ugx, $this->snapshot($first)->policy_version]);
        $before = $this->snapshot($first)->getAttributes();

        // The rule is replaced by a dearer one from now on, then retired altogether
        $this->signIn($this->root);
        $body = ['rate_bp' => 1000, 'effective_from' => now()->addMinute()->toIso8601String()];
        $preview = $this->postJson('/api/v1/admin/commission/policies/simulate', $body + ['replaces' => $rule])->json('data');
        $new = $this->postJson("/api/v1/admin/commission/policies/{$rule}/replace", $body + ['token' => $preview['token'], 'confirm_rate_bp' => 1000, 'reason' => 'A higher estimate'])->assertCreated()->json('data.id');
        $this->travel(2)->minutes();
        $second = $this->confirmed($provider, $customer, $id, 100000);
        $this->signIn($this->root);
        $this->postJson("/api/v1/admin/commission/policies/{$new}/retire", ['reason' => 'No longer needed'])->assertOk();
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);

        // The first payment keeps its rate, its rule and its version; the second used the new version
        $this->assertSame($before, $this->snapshot($first)->getAttributes());
        $this->assertSame([500, $rule, 1], [$this->snapshot($first)->rate_bp, $this->snapshot($first)->policy_id, $this->snapshot($first)->policy_version]);
        $this->assertSame([1000, $new, 2, 10000], [$this->snapshot($second)->rate_bp, $this->snapshot($second)->policy_id, $this->snapshot($second)->policy_version, $this->snapshot($second)->commission_ugx]);
        $this->assertSame(1, CommissionShadowSnapshot::where('payment_report_id', $first)->count());
    }

    public function test_records_that_cannot_be_judged_safely_are_excluded_with_the_reason()
    {
        $this->enable();
        $this->global(500);

        // A sample record never counts as real
        [$p1, $c1, $id1] = $this->priced();
        $p1->forceFill(['is_sample' => true])->save();
        $this->assertSame('sample_record', $this->snapshot($this->confirmed($p1, $c1, $id1, 100000))->exclusion_reason);

        [$p2, $c2, $id2] = $this->priced();
        ServiceRequest::whereKey($id2)->update(['is_sample' => true]);
        $this->assertSame('sample_record', $this->snapshot($this->confirmed($p2, $c2, $id2, 100000))->exclusion_reason);

        // A job cancelled before the customer answered: refunds aren't tracked, so it isn't treated as earned
        [$p3, $c3, $id3] = $this->priced();
        $late = $this->reported($p3, $id3, 100000);
        ServiceRequest::whereKey($id3)->update(['status' => 'cancelled']);
        $this->confirmPayment($c3, $id3, $late, 100000);
        $this->assertSame('job_cancelled', $this->snapshot($late)->exclusion_reason);

        // Nothing is invented for these: no rate, no commission
        $this->assertSame(0, CommissionShadowSnapshot::where('status', 'calculated')->count());
        $this->assertSame(0, (int) CommissionShadowSnapshot::sum('commission_ugx'));
    }

    /* ---------------- The administrators' screens ---------------- */

    public function test_the_summary_labels_everything_as_an_estimate_and_breaks_it_down()
    {
        $this->enable();
        $this->global(500);
        $category = Category::where('slug', 'plumbing')->value('id');
        $this->rule(['scope_type' => 'category', 'scope_ref' => $category], 1000);
        [$provider, $customer, $id] = $this->priced();
        $this->confirmed($provider, $customer, $id, 100000);
        $this->confirmed($provider, $customer, $id, 200000);

        // A sample record, excluded; and a waiting payment
        [$sp, $sc, $sid] = $this->priced();
        $sp->forceFill(['is_sample' => true])->save();
        $this->confirmed($sp, $sc, $sid, 999999);
        $this->reported($provider, $id, 50000);

        $this->signIn($this->userWithRoles(['finance']));
        $s = $this->getJson('/api/v1/admin/commission/summary')->assertOk()->json('data');
        $this->assertSame(['shadow', 'Estimated — Not Collected', 'UGX', true, true], [$s['mode'], $s['label'], $s['currency'], $s['shadow_enabled'], $s['pricing_enabled']]);
        // 10% category rule: UGX 10,000 + 20,000 on UGX 300,000; the sample is not in the figures
        $this->assertSame([2, 300000, 30000, 270000], array_values($s['totals']));
        $this->assertSame(['awaiting_confirmation' => 1, 'sample_record' => 1], collect($s['excluded']['by_reason'])->map(function ($r) {
            return $r['payments'];
        })->all());
        $this->assertSame(2, $s['excluded']['payments']);
        $this->assertSame([[now()->format('Y-m'), 2, 300000, 30000]], collect($s['by_period'])->map(function ($r) {
            return [$r['period'], $r['payments'], $r['amount_ugx'], $r['commission_ugx']];
        })->all());
        $this->assertSame(['Plumbing', 2, 30000], [$s['by_category'][0]['name'], $s['by_category'][0]['payments'], $s['by_category'][0]['commission_ugx']]);
        $this->assertSame(['Plumber', 30000], [$s['by_profession'][0]['name'], $s['by_profession'][0]['commission_ugx']]);
        $this->assertSame(['category', 1000, 2, 30000], [$s['by_policy'][0]['tier'], $s['by_policy'][0]['rate_bp'], $s['by_policy'][0]['payments'], $s['by_policy'][0]['commission_ugx']]);

        // A period with nothing in it is zero, not an error
        $empty = $this->getJson('/api/v1/admin/commission/summary?from='.now()->addDays(5)->toDateString().'&to='.now()->addDays(9)->toDateString())->assertOk()->json('data');
        $this->assertSame([0, 0, 0], [$empty['totals']['eligible_payments'], $empty['totals']['eligible_volume_ugx'], $empty['totals']['estimated_commission_ugx']]);
        $this->getJson('/api/v1/admin/commission/summary?from=yesterday')->assertStatus(422);
        $this->getJson('/api/v1/admin/commission/summary?granularity=century')->assertStatus(422);
    }

    public function test_the_estimates_list_pages_and_filters_and_shows_no_personal_details()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        foreach ([10000, 20000, 30000] as $amount) {
            $this->confirmed($provider, $customer, $id, $amount);
        }
        [$sp, $sc, $sid] = $this->priced();
        $sp->forceFill(['is_sample' => true])->save();
        $this->confirmed($sp, $sc, $sid, 5000);

        $this->signIn($this->userWithRoles(['finance']));
        $all = $this->getJson('/api/v1/admin/commission/snapshots')->assertOk()->json('data');
        $this->assertSame(['Estimated — Not Collected', 4, 25], [$all['label'], $all['total'], $all['per_page']]);
        $this->assertSame(['shadow'], array_values(array_unique(array_column($all['items'], 'mode'))));
        $this->assertSame([30000, 1500, '5%', 28500], [$all['items'][1]['amount_ugx'], $all['items'][1]['commission_ugx'], $all['items'][1]['percent'], $all['items'][1]['provider_remainder_ugx']]);
        $this->assertSame(['Plumbing', $provider->display_name], [$all['items'][1]['category'], $all['items'][1]['provider']]);
        $this->assertSame(1, $this->getJson('/api/v1/admin/commission/snapshots?status=excluded&reason=sample_record')->json('data.total'));
        $this->assertSame(3, $this->getJson('/api/v1/admin/commission/snapshots?status=calculated')->json('data.total'));
        $this->assertSame([], $this->getJson('/api/v1/admin/commission/snapshots?page=9')->json('data.items'));
        $this->getJson('/api/v1/admin/commission/snapshots?status=nonsense')->assertStatus(422);

        // No customer or provider contact details, and no customer at all
        $json = json_encode($all);
        $this->assertStringNotContainsString($customer->email, $json);
        $this->assertStringNotContainsString((string) $customer->name, $json);
        $this->assertStringNotContainsString($provider->owner->email, $json);
    }

    public function test_the_what_if_check_and_the_rule_list_show_which_rate_applies_and_why()
    {
        $this->enable();
        $global = $this->global(500);
        $plumbing = Category::where('slug', 'plumbing')->value('id');
        $category = $this->rule(['scope_type' => 'category', 'scope_ref' => $plumbing], 800);

        $this->signIn($this->userWithRoles(['finance']));
        $r = $this->getJson('/api/v1/admin/commission/resolve?category=plumbing&amount=200000')->assertOk()->json('data');
        $this->assertSame(['resolved', 'category', $category, 800, [200000, 16000, 184000]], [$r['status'], $r['tier'], $r['policy']['id'], $r['policy']['rate_bp'], array_values($r['example'])]);
        $this->assertSame('Estimated — Not Collected', $r['label']);
        $other = $this->getJson('/api/v1/admin/commission/resolve?category=cleaning')->assertOk()->json('data');
        $this->assertSame(['resolved', 'global', $global], [$other['status'], $other['tier'], $other['policy']['id']]);
        // Before any rule is in force nothing is invented
        $future = $this->getJson('/api/v1/admin/commission/resolve?category=plumbing&at='.urlencode(now()->subYear()->toIso8601String()))->assertOk()->json('data');
        $this->assertSame(['unresolved', 'no_policy'], [$future['status'], $future['reason']]);
        $this->getJson('/api/v1/admin/commission/resolve')->assertStatus(422);

        $list = $this->getJson('/api/v1/admin/commission/policies')->assertOk()->json('data');
        $this->assertSame(['category', 'global'], array_column($list, 'scope_type'));
        $this->assertSame(['Category: Plumbing', '8%', 'active'], [$list[0]['scope'], $list[0]['percent'], $list[0]['state']]);
        $options = $this->getJson('/api/v1/admin/commission/options')->assertOk()->json('data');
        $this->assertSame([2000, ['provider', 'profession', 'category', 'area', 'district', 'global']], [$options['ceiling_bp'], $options['precedence']]);
        $this->assertNotEmpty($options['districts']);
    }

    /* ---------------- Shadow mode creates no obligation and is invisible to providers and customers ---------------- */

    public function test_shadow_mode_notifies_nobody_changes_no_payment_and_creates_no_obligation()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->reported($provider, $id, 100000);
        $before = UserNotification::count();
        $this->confirmPayment($customer, $id, $payment, 100000);
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);

        // Only the one ordinary "Payment confirmed" notification for the provider business: nothing about commission or money owed
        $this->assertSame($before + 1, UserNotification::count());
        $this->assertSame(0, UserNotification::where('title', 'like', '%ommission%')->orWhere('body', 'like', '%ommission%')->orWhere('body', 'like', '%owe%')->count());
        $this->assertNotNull($this->snapshot($payment));
        $this->assertSame(JobPaymentReport::CONFIRMED, JobPaymentReport::find($payment)->status);
        $this->assertSame('in_progress', ServiceRequest::find($id)->status);

        // No place to hold a debt, an invoice, a balance or a payment request exists
        foreach (['commission_assessments', 'ledger_entries', 'settlement_cycles', 'invoices', 'provider_debts', 'provider_balances', 'payment_requests', 'wallets', 'payouts', 'provider_subscriptions', 'commission_payments'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist");
        }
        // A provider's account is never restricted by an estimate
        $this->assertSame(ProviderProfile::STATUS_APPROVED, $provider->fresh()->status);
        $this->assertSame(0, \App\Models\AccountWarning::count());
    }

    public function test_providers_and_customers_never_see_commission_figures_anywhere()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);

        $payloads = [];
        $this->signIn($provider->owner);
        $payloads[] = $this->getJson('/api/v1/me/received-requests')->getContent();
        $payloads[] = $this->getJson("/api/v1/me/received-requests/{$id}/payments")->getContent();
        $payloads[] = $this->getJson("/api/v1/me/received-requests/{$id}/pricing")->getContent();
        $payloads[] = $this->getJson('/api/v1/me/notifications')->getContent();
        $this->signIn($customer);
        $payloads[] = $this->getJson('/api/v1/me/requests')->getContent();
        $payloads[] = $this->getJson("/api/v1/me/requests/{$id}/payments")->getContent();
        $payloads[] = $this->getJson('/api/v1/me/notifications')->getContent();
        foreach ($payloads as $i => $json) {
            $this->assertDoesNotMatchRegularExpression('/commission|rate_bp|provider_net|remainder|shadow/i', $json, "payload {$i}");
        }
        $this->assertNotNull($this->snapshot($payment));
    }

    public function test_a_problem_in_the_estimate_can_never_undo_or_block_the_customers_confirmation()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->reported($provider, $id, 100000);

        // The estimate engine fails outright
        $this->app->bind(CommissionShadow::class, function () {
            return new class(app(PlatformSettings::class), app(\App\Services\AuditLogger::class)) extends CommissionShadow {
                public function process(JobPaymentReport $report): ?CommissionShadowSnapshot
                {
                    throw new \RuntimeException('The estimate engine broke.');
                }
            };
        });
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => 100000])->assertOk()->assertJsonPath('data.payments.reports.0.status', 'confirmed');
        $this->assertSame(JobPaymentReport::CONFIRMED, JobPaymentReport::find($payment)->status);
        $this->assertSame(0, CommissionShadowSnapshot::count());

        // The catch-up fixes it once the engine works again
        $this->app->forgetInstance(CommissionShadow::class);
        $this->app->bind(CommissionShadow::class, function () {
            return new CommissionShadow(app(PlatformSettings::class), app(\App\Services\AuditLogger::class));
        });
        $this->artisan('workmate:commission-shadow')->expectsOutput('Created: 1  Superseded: 0  Unchanged: 0  (estimates only, not collected)')->assertExitCode(0);
        $this->assertSame(5000, $this->snapshot($payment)->commission_ugx);
    }

    public function test_pricing_off_keeps_every_existing_estimate_but_nothing_new_can_be_confirmed()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);
        $this->setting('finance.pricing_enabled', false);

        $this->signIn($customer);
        $this->assertNull($this->getJson('/api/v1/me/requests')->json('data.0.payments'));
        $this->assertSame(5000, $this->snapshot($payment)->commission_ugx);
        $this->signIn($this->root);
        $this->assertSame(5000, $this->getJson('/api/v1/admin/commission/summary')->json('data.totals.estimated_commission_ugx'));
    }
    public function test_an_estimate_stops_counting_when_the_job_is_cancelled_afterwards_and_the_old_row_is_kept()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);
        $first = $this->snapshot($payment);
        $this->assertSame([CommissionShadowSnapshot::CALCULATED, 5000], [$first->status, $first->commission_ugx]);

        ServiceRequest::whereKey($id)->update(['status' => 'cancelled']);
        $summary = app(CommissionShadow::class)->summary(now()->subDay(), now()->addDay(), 'month');
        $this->assertSame(1, $summary['totals']['eligible_payments']);   // not noticed until it is judged again

        $this->artisan('workmate:commission-shadow')->assertExitCode(0);

        $now = $this->snapshot($payment);
        $this->assertNotSame($first->id, $now->id);
        $this->assertSame([CommissionShadowSnapshot::EXCLUDED, 'job_cancelled', null, 2, $first->id], [$now->status, $now->exclusion_reason, $now->commission_ugx, $now->revision_no, $now->supersedes_id]);
        $old = CommissionShadowSnapshot::find($first->id);
        $this->assertNull($old->current_slot);
        $this->assertSame([CommissionShadowSnapshot::CALCULATED, 5000], [$old->status, $old->commission_ugx]);   // history untouched
        $summary = app(CommissionShadow::class)->summary(now()->subDay(), now()->addDay(), 'month');
        $this->assertSame([0, 0], [$summary['totals']['eligible_payments'], $summary['totals']['estimated_commission_ugx']]);
        $this->assertSame(1, $summary['excluded']['by_reason']['job_cancelled']['payments']);

        // Running it again changes nothing
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);
        $this->assertSame(2, CommissionShadowSnapshot::where('payment_report_id', $payment)->count());
    }

    public function test_an_estimate_stops_counting_if_the_payment_is_somehow_no_longer_confirmed()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);

        JobPaymentReport::whereKey($payment)->update(['status' => 'disputed']);
        $this->artisan('workmate:commission-shadow')->assertExitCode(0);

        $now = $this->snapshot($payment);
        $this->assertSame([CommissionShadowSnapshot::EXCLUDED, 'payment_no_longer_confirmed'], [$now->status, $now->exclusion_reason]);
        $this->assertSame(1, CommissionShadowSnapshot::where('payment_report_id', $payment)->where('status', 'calculated')->count());
    }

    public function test_a_confirmed_payment_cannot_be_estimated_twice_however_often_it_is_processed()
    {
        $this->enable();
        $this->global(500);
        [$provider, $customer, $id] = $this->priced();
        $payment = $this->confirmed($provider, $customer, $id, 100000);

        $report = JobPaymentReport::find($payment);
        for ($i = 0; $i < 3; $i++) {
            app(CommissionShadow::class)->process($report);
            $this->artisan('workmate:commission-shadow')->assertExitCode(0);
        }
        // The customer answering again is refused and adds nothing
        $this->signIn($customer);
        $this->postJson("/api/v1/me/requests/{$id}/payments/{$payment}/confirm", ['amount' => 100000]);
        $this->assertSame(1, CommissionShadowSnapshot::where('payment_report_id', $payment)->count());
        $this->assertSame(5000, (int) CommissionShadowSnapshot::sum('commission_ugx'));
    }

    public function test_the_permission_migration_is_safe_to_run_again_and_gives_only_the_intended_access()
    {
        $migration = require_once base_path('database/migrations/2026_11_28_000070_add_commission_shadow_permissions.php');
        $migration = is_object($migration) ? $migration : new \AddCommissionShadowPermissions();

        $before = [DB::table('permissions')->count(), DB::table('permission_role')->count(), DB::table('roles')->count()];
        $viewId = DB::table('permissions')->where('key', 'commission.view')->value('id');
        $edited = DB::table('permissions')->where('id', $viewId)->update(['description' => 'Edited by an administrator']);
        $this->assertSame(1, $edited);

        $migration->up();
        $migration->up();

        $this->assertSame($before, [DB::table('permissions')->count(), DB::table('permission_role')->count(), DB::table('roles')->count()]);
        $this->assertSame('Edited by an administrator', DB::table('permissions')->where('id', $viewId)->value('description'));   // not overwritten
        $holders = function (string $key) {
            return DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')->join('roles as r', 'r.id', '=', 'pr.role_id')
                ->where('p.key', $key)->orderBy('r.key')->pluck('r.key')->all();
        };
        $this->assertSame(['finance', 'super_admin'], $holders('commission.view'));
        $this->assertSame(['super_admin'], $holders('commission.manage'));
        $this->assertSame(1, DB::table('permissions')->where('key', 'commission.view')->count());
    }

    public function test_the_estimate_switch_starts_off_no_rate_is_seeded_and_no_obligation_tables_exist()
    {
        $definition = config('workmate.settings')['finance.commission_shadow_enabled'];
        $this->assertFalse($definition['default']);
        $this->assertArrayNotHasKey('public', $definition);   // never sent to the public site
        $this->assertFalse(config('workmate.settings')['finance.pricing_enabled']['default']);
        $this->assertFalse(app(CommissionShadow::class)->enabled());
        $this->assertSame(0, CommissionShadowPolicy::count());
        $this->assertSame(0, CommissionShadowSnapshot::count());
        foreach (['provider_debts', 'commission_invoices', 'provider_balances', 'commission_charges', 'settlements', 'invoices', 'provider_payables'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        foreach (DB::getSchemaBuilder()->getColumnListing('commission_shadow_snapshots') as $column) {
            $this->assertDoesNotMatchRegularExpression('/(due|debt|owed|invoice|balance|deadline|penalty)/', $column);
        }
    }
}
