<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ProviderReport;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\SampleMarketplaceSeeder;

class AdminDataTest extends ApiTestCase
{
    public function test_only_administrators_can_load_admin_data()
    {
        $this->getJson('/api/v1/admin/data')->assertUnauthorized();

        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/admin/data')->assertForbidden();
    }

    public function test_sections_follow_the_administrators_permissions()
    {
        $this->seed(SampleMarketplaceSeeder::class);

        $this->signIn($this->userWithRoles(['finance']));
        $data = $this->getJson('/api/v1/admin/data')->assertOk()->json('data');
        $this->assertSame([], $data['core']['customers'], 'finance has no marketplace.view');
        $this->assertSame([], $data['ops']['audit']);
        $this->assertCount(180, $data['daily']);

        $this->signIn($this->userWithRoles(['super_admin']));
        $data = $this->getJson('/api/v1/admin/data')->assertOk()->json('data');
        $this->assertCount(14, $data['core']['providers']);
        $this->assertCount(7, $data['core']['jobs']);
        $this->assertSame([], $data['core']['transactions'], 'payments are not built yet');
        $this->assertSame(['customersNew', 'providersNew', 'jobsCreated', 'enquiries'], $data['trackedSeries']);
    }

    public function test_samples_are_labelled_and_left_out_of_figures()
    {
        $this->seed(SampleMarketplaceSeeder::class);
        $this->signIn($this->userWithRoles(['super_admin']));

        $data = $this->getJson('/api/v1/admin/data')->json('data');

        $this->assertStringEndsWith('(sample)', $data['core']['providers'][0]['name']);
        $this->assertSame(0, array_sum(array_column($data['daily'], 'jobsCreated')), 'sample jobs are not counted');
        // The signed-in administrator registered today and is counted
        $this->assertSame(1, end($data['daily'])['customersNew']);
    }

    public function test_contact_details_are_masked()
    {
        $this->signIn($this->userWithRoles(['super_admin']));
        User::factory()->create(['email' => 'okello.james@example.com', 'phone' => '+256772123456']);

        $json = json_encode($this->getJson('/api/v1/admin/data')->json(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('okello.james@example.com', $json);
        $this->assertStringNotContainsString('+256772123456', $json);
        $this->assertStringContainsString('o•••@example.com', $json);
    }

    public function test_suspending_and_reactivating_a_customer()
    {
        $customer = User::factory()->create();
        $admin = $this->signIn($this->userWithRoles(['trust_safety']));

        $this->postJson("/api/v1/admin/users/{$customer->id}/suspend", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/admin/users/{$customer->id}/suspend", ['reason' => 'Confirmed fraud report'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertFalse($customer->fresh()->isActive());

        $this->postJson("/api/v1/admin/users/{$customer->id}/reactivate", ['reason' => 'Appeal accepted'])->assertOk();
        $this->assertTrue($customer->fresh()->isActive());

        $this->postJson("/api/v1/admin/users/{$admin->id}/suspend", ['reason' => 'Testing myself'])
            ->assertForbidden()->assertJson(['code' => 'cannot_change_own_account']);
        $other = $this->userWithRoles(['support']);
        $this->postJson("/api/v1/admin/users/{$other->id}/suspend", ['reason' => 'Admin dispute here'])
            ->assertForbidden()->assertJson(['code' => 'admin_account']);

        $this->assertSame(2, AuditLog::where('action', 'like', 'admin.account_%')->count());
    }

    public function test_hiding_a_review_and_handling_a_report()
    {
        $this->seed(SampleMarketplaceSeeder::class);
        $review = Review::first();

        $this->signIn($this->userWithRoles(['support']));
        $this->patchJson("/api/v1/admin/reviews/{$review->id}", ['status' => 'hidden', 'reason' => 'Abusive language'])->assertForbidden();

        $this->signIn($this->userWithRoles(['content']));
        $this->patchJson("/api/v1/admin/reviews/{$review->id}", ['status' => 'hidden', 'reason' => 'Abusive language'])->assertOk();
        $this->assertSame('hidden', $review->fresh()->status);

        $report = new ProviderReport(['reason' => 'scam']);
        $report->forceFill(['provider_profile_id' => $review->provider_profile_id, 'status' => 'open'])->save();
        $this->signIn($this->userWithRoles(['trust_safety']));
        $this->patchJson("/api/v1/admin/reports/{$report->id}", ['status' => 'dismissed', 'reason' => 'No evidence found'])->assertOk();
        $this->assertSame('dismissed', $report->fresh()->status);
    }
}
