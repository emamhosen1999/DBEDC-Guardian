<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileLeaveApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_mobile_leave_endpoints(): void
    {
        $this->getJson('/api/v1/leave-types')->assertUnauthorized();
        $this->getJson('/api/v1/leaves')->assertUnauthorized();
        $this->getJson('/api/v1/leaves/summary')->assertUnauthorized();
        $this->getJson('/api/v1/leaves/analytics')->assertUnauthorized();
        $this->getJson('/api/v1/leaves/calendar')->assertUnauthorized();
        $this->getJson('/api/v1/leaves/1')->assertUnauthorized();
        $this->postJson('/api/v1/leaves', [])->assertUnauthorized();
        $this->putJson('/api/v1/leaves/1', [])->assertUnauthorized();
        $this->deleteJson('/api/v1/leaves/1')->assertUnauthorized();
    }

    public function test_authenticated_user_can_fetch_leave_types_and_own_leaves(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $ownLeaveId = $this->insertLeaveForUser($user->id, $leaveTypeId);
        $this->insertLeaveForUser($otherUser->id, $leaveTypeId);

        Sanctum::actingAs($user);

        $typesResponse = $this->getJson('/api/v1/leave-types');
        $typesResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $leaveTypeId)
            ->assertJsonPath('data.0.type', 'Casual');

        $leavesResponse = $this->getJson('/api/v1/leaves');
        $leavesResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.leaves')
            ->assertJsonPath('data.leaves.0.id', $ownLeaveId);
    }

    public function test_authenticated_user_can_fetch_leave_analytics(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $year = now()->year;

        $casualLeaveTypeId = $this->createLeaveType([
            'type' => 'Casual',
            'days' => 12,
        ]);

        $sickLeaveTypeId = $this->createLeaveType([
            'type' => 'Sick',
            'symbol' => 'S',
            'days' => 8,
        ]);

        $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, 1, 10)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, 1, 12)->toDateString(),
            'no_of_days' => 3,
            'status' => 'Approved',
        ]);

        $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, 1, 20)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, 1, 21)->toDateString(),
            'no_of_days' => 2,
            'status' => 'New',
        ]);

        $this->insertLeaveForUser($user->id, $sickLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, 2, 5)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, 2, 5)->toDateString(),
            'no_of_days' => 1,
            'status' => 'Declined',
        ]);

        $this->insertLeaveForUser($user->id, $sickLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, 3, 3)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, 3, 6)->toDateString(),
            'no_of_days' => 4,
            'status' => 'Approved',
        ]);

        $this->insertLeaveForUser($otherUser->id, $casualLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, 1, 24)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, 1, 29)->toDateString(),
            'no_of_days' => 6,
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/analytics?year='.$year);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.year', $year)
            ->assertJsonPath('data.totals.requests', 4)
            ->assertJsonPath('data.totals.approved_days', 7)
            ->assertJsonPath('data.totals.pending_days', 2)
            ->assertJsonPath('data.totals.rejected_days', 1)
            ->assertJsonPath('data.totals.total_days', 10)
            ->assertJsonPath('data.status_breakdown.approved.requests', 2)
            ->assertJsonPath('data.status_breakdown.pending.requests', 1)
            ->assertJsonPath('data.status_breakdown.rejected.requests', 1)
            ->assertJsonCount(12, 'data.monthly')
            ->assertJsonCount(2, 'data.leave_type_breakdown');

        $monthly = collect($response->json('data.monthly'))->keyBy('month');

        $january = $monthly->get(1);
        $this->assertSame(2, $january['requests']);
        $this->assertSame(3, $january['approved_days']);
        $this->assertSame(2, $january['pending_days']);
        $this->assertSame(5, $january['total_days']);

        $february = $monthly->get(2);
        $this->assertSame(1, $february['requests']);
        $this->assertSame(1, $february['rejected_days']);

        $march = $monthly->get(3);
        $this->assertSame(1, $march['requests']);
        $this->assertSame(4, $march['approved_days']);
    }

    public function test_leave_analytics_can_be_filtered_by_leave_type(): void
    {
        $user = User::factory()->create(['active' => true]);
        $year = now()->year;

        $casualLeaveTypeId = $this->createLeaveType([
            'type' => 'Casual',
            'days' => 12,
        ]);

        $sickLeaveTypeId = $this->createLeaveType([
            'type' => 'Sick',
            'symbol' => 'S',
            'days' => 8,
        ]);

        $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, 1, 10)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, 1, 12)->toDateString(),
            'no_of_days' => 3,
            'status' => 'Approved',
        ]);

        $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, 1, 20)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, 1, 21)->toDateString(),
            'no_of_days' => 2,
            'status' => 'New',
        ]);

        $this->insertLeaveForUser($user->id, $sickLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, 3, 3)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, 3, 6)->toDateString(),
            'no_of_days' => 4,
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/analytics?year='.$year.'&leave_type_id='.$casualLeaveTypeId);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.totals.requests', 2)
            ->assertJsonPath('data.totals.approved_days', 3)
            ->assertJsonPath('data.totals.pending_days', 2)
            ->assertJsonPath('data.totals.rejected_days', 0)
            ->assertJsonPath('data.totals.total_days', 5)
            ->assertJsonCount(1, 'data.leave_type_breakdown')
            ->assertJsonPath('data.leave_type_breakdown.0.leave_type_id', $casualLeaveTypeId)
            ->assertJsonPath('data.leave_type_breakdown.0.requests', 2)
            ->assertJsonPath('data.leave_type_breakdown.0.total_days', 5);
    }

    public function test_authenticated_user_can_fetch_leave_calendar_with_holidays(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $year = now()->year;
        $month = now()->month;

        $casualLeaveTypeId = $this->createLeaveType([
            'type' => 'Casual',
            'days' => 12,
        ]);

        $sickLeaveTypeId = $this->createLeaveType([
            'type' => 'Sick',
            'symbol' => 'S',
            'days' => 8,
        ]);

        $approvedLeaveId = $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, $month, 5)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, $month, 7)->toDateString(),
            'no_of_days' => 3,
            'status' => 'Approved',
        ]);

        $pendingLeaveId = $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, $month, 10)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, $month, 11)->toDateString(),
            'no_of_days' => 2,
            'status' => 'New',
        ]);

        $rejectedLeaveId = $this->insertLeaveForUser($user->id, $sickLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, $month, 15)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, $month, 15)->toDateString(),
            'no_of_days' => 1,
            'status' => 'Declined',
        ]);

        $this->insertLeaveForUser($otherUser->id, $casualLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, $month, 20)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, $month, 24)->toDateString(),
            'no_of_days' => 5,
            'status' => 'Approved',
        ]);

        if (Schema::hasTable('holidays')) {
            $this->insertHoliday([
                'title' => 'Holiday One',
                'from_date' => \Carbon\Carbon::create($year, $month, 8)->toDateString(),
                'to_date' => \Carbon\Carbon::create($year, $month, 9)->toDateString(),
            ]);

            $this->insertHoliday([
                'title' => 'Holiday Two',
                'from_date' => \Carbon\Carbon::create($year, $month, 25)->toDateString(),
                'to_date' => \Carbon\Carbon::create($year, $month, 25)->toDateString(),
            ]);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/calendar?year='.$year.'&month='.$month);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.year', $year)
            ->assertJsonPath('data.month', $month)
            ->assertJsonPath('data.summary.total_leave_requests', 3)
            ->assertJsonPath('data.summary.total_leave_days', 6)
            ->assertJsonPath('data.summary.approved_leave_days', 3)
            ->assertJsonPath('data.summary.pending_leave_days', 2)
            ->assertJsonPath('data.summary.rejected_leave_days', 1)
            ->assertJsonCount(3, 'data.leaves');

        if (Schema::hasTable('holidays')) {
            $response->assertJsonPath('data.summary.holiday_days', 3)
                ->assertJsonCount(2, 'data.holidays');
        } else {
            $response->assertJsonPath('data.summary.holiday_days', 0)
                ->assertJsonCount(0, 'data.holidays');
        }

        $leaveIds = collect($response->json('data.leaves'))->pluck('id');

        $this->assertTrue($leaveIds->contains($approvedLeaveId));
        $this->assertTrue($leaveIds->contains($pendingLeaveId));
        $this->assertTrue($leaveIds->contains($rejectedLeaveId));
    }

    public function test_leave_calendar_can_be_filtered_by_leave_type(): void
    {
        $user = User::factory()->create(['active' => true]);
        $year = now()->year;
        $month = now()->month;

        $casualLeaveTypeId = $this->createLeaveType([
            'type' => 'Casual',
            'days' => 12,
        ]);

        $sickLeaveTypeId = $this->createLeaveType([
            'type' => 'Sick',
            'symbol' => 'S',
            'days' => 8,
        ]);

        $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, $month, 5)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, $month, 6)->toDateString(),
            'no_of_days' => 2,
            'status' => 'Approved',
        ]);

        $this->insertLeaveForUser($user->id, $sickLeaveTypeId, [
            'from_date' => \Carbon\Carbon::create($year, $month, 8)->toDateString(),
            'to_date' => \Carbon\Carbon::create($year, $month, 9)->toDateString(),
            'no_of_days' => 2,
            'status' => 'New',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/calendar?year='.$year.'&month='.$month.'&leave_type_id='.$casualLeaveTypeId);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total_leave_requests', 1)
            ->assertJsonPath('data.summary.total_leave_days', 2)
            ->assertJsonPath('data.summary.approved_leave_days', 2)
            ->assertJsonPath('data.summary.pending_leave_days', 0)
            ->assertJsonPath('data.summary.rejected_leave_days', 0)
            ->assertJsonCount(1, 'data.leaves')
            ->assertJsonPath('data.leaves.0.leave_type', $casualLeaveTypeId);
    }

    public function test_leave_calendar_validates_month_range(): void
    {
        $user = User::factory()->create(['active' => true]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/calendar?month=13');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['month']);
    }

    public function test_authenticated_user_can_fetch_own_leave_details(): void
    {
        $user = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType([
            'type' => 'Annual',
            'symbol' => 'AL',
        ]);

        $leaveId = $this->insertLeaveForUser($user->id, $leaveTypeId, [
            'reason' => 'Detailed leave reason.',
            'status' => 'New',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/'.$leaveId);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $leaveId)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.leave_type', $leaveTypeId)
            ->assertJsonPath('data.leave_type_name', 'Annual')
            ->assertJsonPath('data.reason', 'Detailed leave reason.');
    }

    public function test_user_cannot_fetch_another_users_leave_details(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertLeaveForUser($otherUser->id, $leaveTypeId);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/'.$leaveId);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access this leave request.');
    }

    public function test_fetching_non_existent_leave_details_returns_not_found(): void
    {
        $user = User::factory()->create(['active' => true]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/999999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Leave request not found.');
    }

    public function test_authenticated_user_can_fetch_leave_summary_with_totals_and_balances(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $casualLeaveTypeId = $this->createLeaveType([
            'type' => 'Casual',
            'days' => 12,
        ]);

        $sickLeaveTypeId = $this->createLeaveType([
            'type' => 'Sick',
            'symbol' => 'S',
            'days' => 8,
        ]);

        $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'no_of_days' => 3,
            'status' => 'Approved',
            'from_date' => now()->startOfYear()->addDays(10)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(12)->toDateString(),
        ]);

        $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'no_of_days' => 2,
            'status' => 'New',
            'from_date' => now()->startOfYear()->addDays(20)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(21)->toDateString(),
        ]);

        $this->insertLeaveForUser($user->id, $sickLeaveTypeId, [
            'no_of_days' => 4,
            'status' => 'Approved',
            'from_date' => now()->startOfYear()->addDays(30)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(33)->toDateString(),
        ]);

        $this->insertLeaveForUser($user->id, $sickLeaveTypeId, [
            'no_of_days' => 1,
            'status' => 'Declined',
            'from_date' => now()->startOfYear()->addDays(40)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(40)->toDateString(),
        ]);

        $this->insertLeaveForUser($otherUser->id, $casualLeaveTypeId, [
            'no_of_days' => 9,
            'status' => 'Approved',
            'from_date' => now()->startOfYear()->addDays(50)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(58)->toDateString(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/summary?year='.now()->year);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.year', now()->year)
            ->assertJsonPath('data.totals.allocated', 20)
            ->assertJsonPath('data.totals.used', 7)
            ->assertJsonPath('data.totals.pending', 2)
            ->assertJsonPath('data.totals.rejected', 1)
            ->assertJsonPath('data.totals.balance', 13)
            ->assertJsonPath('data.totals.usage_percentage', 35)
            ->assertJsonCount(2, 'data.by_type');

        $summaryByType = collect($response->json('data.by_type'))->keyBy('id');

        $casualSummary = $summaryByType->get($casualLeaveTypeId);
        $this->assertNotNull($casualSummary);
        $this->assertSame(12, $casualSummary['allocated']);
        $this->assertSame(3, $casualSummary['used']);
        $this->assertSame(2, $casualSummary['pending']);
        $this->assertSame(0, $casualSummary['rejected']);
        $this->assertSame(9, $casualSummary['balance']);

        $sickSummary = $summaryByType->get($sickLeaveTypeId);
        $this->assertNotNull($sickSummary);
        $this->assertSame(8, $sickSummary['allocated']);
        $this->assertSame(4, $sickSummary['used']);
        $this->assertSame(0, $sickSummary['pending']);
        $this->assertSame(1, $sickSummary['rejected']);
        $this->assertSame(4, $sickSummary['balance']);
    }

    public function test_leave_summary_can_be_filtered_by_leave_type(): void
    {
        $user = User::factory()->create(['active' => true]);

        $casualLeaveTypeId = $this->createLeaveType([
            'type' => 'Casual',
            'days' => 12,
        ]);

        $sickLeaveTypeId = $this->createLeaveType([
            'type' => 'Sick',
            'symbol' => 'S',
            'days' => 8,
        ]);

        $this->insertLeaveForUser($user->id, $casualLeaveTypeId, [
            'no_of_days' => 5,
            'status' => 'Approved',
            'from_date' => now()->startOfYear()->addDays(5)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(9)->toDateString(),
        ]);

        $this->insertLeaveForUser($user->id, $sickLeaveTypeId, [
            'no_of_days' => 2,
            'status' => 'Approved',
            'from_date' => now()->startOfYear()->addDays(15)->toDateString(),
            'to_date' => now()->startOfYear()->addDays(16)->toDateString(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/leaves/summary?year='.now()->year.'&leave_type_id='.$casualLeaveTypeId);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.totals.allocated', 12)
            ->assertJsonPath('data.totals.used', 5)
            ->assertJsonPath('data.totals.pending', 0)
            ->assertJsonPath('data.totals.rejected', 0)
            ->assertJsonPath('data.totals.balance', 7)
            ->assertJsonCount(1, 'data.by_type')
            ->assertJsonPath('data.by_type.0.id', $casualLeaveTypeId);
    }

    public function test_authenticated_user_can_apply_leave_via_mobile_api(): void
    {
        $user = User::factory()->create(['active' => true]);
        $leaveTypeId = $this->createLeaveType([
            'requires_approval' => true,
            'auto_approve' => false,
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'leave_type_id' => $leaveTypeId,
            'from_date' => now()->addDays(3)->toDateString(),
            'to_date' => now()->addDays(4)->toDateString(),
            'reason' => 'Need leave for personal work.',
        ];

        $response = $this->postJson('/api/v1/leaves', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Leave request submitted successfully.')
            ->assertJsonPath('data.leave_type', $leaveTypeId)
            ->assertJsonPath('data.status', 'New');

        $leaveId = $response->json('data.id');
        $this->assertNotNull($leaveId);

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveId,
            'leave_type' => $leaveTypeId,
            'status' => 'New',
        ]);

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveId,
            $this->resolveLeavesUserColumn() => $user->id,
        ]);
    }

    public function test_user_can_update_own_non_final_leave_request(): void
    {
        $user = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType([
            'type' => 'Annual',
            'symbol' => 'AL',
            'requires_approval' => true,
            'auto_approve' => false,
        ]);

        $fromDate = now()->addDays(5)->toDateString();
        $toDate = now()->addDays(6)->toDateString();

        $leaveId = $this->insertLeaveForUser($user->id, $leaveTypeId, [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'no_of_days' => 2,
            'reason' => 'Original leave reason.',
            'status' => 'New',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/leaves/'.$leaveId, [
            'leave_type_id' => $leaveTypeId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'reason' => 'Updated leave reason from mobile app.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Leave request updated successfully.')
            ->assertJsonPath('data.id', $leaveId)
            ->assertJsonPath('data.reason', 'Updated leave reason from mobile app.');

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveId,
            'reason' => 'Updated leave reason from mobile app.',
            'status' => 'New',
        ]);
    }

    public function test_user_cannot_update_another_users_leave_request(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertLeaveForUser($otherUser->id, $leaveTypeId, [
            'status' => 'New',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/leaves/'.$leaveId, [
            'leave_type_id' => $leaveTypeId,
            'from_date' => now()->addDays(6)->toDateString(),
            'to_date' => now()->addDays(6)->toDateString(),
            'reason' => 'Trying to update another user leave.',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to update this leave request.');
    }

    public function test_user_cannot_update_finalized_leave_request(): void
    {
        $user = User::factory()->create(['active' => true]);
        $leaveTypeId = $this->createLeaveType();

        $leaveId = $this->insertLeaveForUser($user->id, $leaveTypeId, [
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/leaves/'.$leaveId, [
            'leave_type_id' => $leaveTypeId,
            'from_date' => now()->addDays(7)->toDateString(),
            'to_date' => now()->addDays(8)->toDateString(),
            'reason' => 'Trying to edit approved leave.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This leave request can no longer be updated.');
    }

    public function test_user_cannot_update_leave_with_overlapping_dates(): void
    {
        $user = User::factory()->create(['active' => true]);
        $leaveTypeId = $this->createLeaveType();

        $leaveToUpdateId = $this->insertLeaveForUser($user->id, $leaveTypeId, [
            'from_date' => now()->addDays(4)->toDateString(),
            'to_date' => now()->addDays(4)->toDateString(),
            'no_of_days' => 1,
            'status' => 'New',
        ]);

        $this->insertLeaveForUser($user->id, $leaveTypeId, [
            'from_date' => now()->addDays(10)->toDateString(),
            'to_date' => now()->addDays(11)->toDateString(),
            'no_of_days' => 2,
            'status' => 'New',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/leaves/'.$leaveToUpdateId, [
            'leave_type_id' => $leaveTypeId,
            'from_date' => now()->addDays(10)->toDateString(),
            'to_date' => now()->addDays(11)->toDateString(),
            'reason' => 'Attempting to move leave into overlapping dates.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Leave dates overlap with an existing leave request.');
    }

    public function test_mobile_leave_application_blocks_overlapping_dates(): void
    {
        $user = User::factory()->create(['active' => true]);
        $leaveTypeId = $this->createLeaveType();

        $this->insertLeaveForUser($user->id, $leaveTypeId, [
            'from_date' => now()->addDays(6)->toDateString(),
            'to_date' => now()->addDays(7)->toDateString(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/leaves', [
            'leave_type_id' => $leaveTypeId,
            'from_date' => now()->addDays(7)->toDateString(),
            'to_date' => now()->addDays(8)->toDateString(),
            'reason' => 'Overlapping leave request.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Leave dates overlap with an existing leave request.');
    }

    public function test_user_can_cancel_own_non_final_leave(): void
    {
        $user = User::factory()->create(['active' => true]);
        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertLeaveForUser($user->id, $leaveTypeId, [
            'status' => 'New',
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/leaves/'.$leaveId);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Leave request cancelled successfully.');

        $this->assertDatabaseMissing('leaves', [
            'id' => $leaveId,
        ]);
    }

    public function test_user_cannot_cancel_another_users_leave(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertLeaveForUser($otherUser->id, $leaveTypeId);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/leaves/'.$leaveId);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to cancel this leave request.');
    }

    public function test_user_cannot_cancel_finalized_leave_status(): void
    {
        $user = User::factory()->create(['active' => true]);
        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertLeaveForUser($user->id, $leaveTypeId, [
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/leaves/'.$leaveId);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This leave request can no longer be cancelled.');
    }

    private function createLeaveType(array $overrides = []): int
    {
        $payload = array_merge([
            'type' => 'Casual',
            'symbol' => 'C',
            'days' => 12,
            'eligibility' => null,
            'carry_forward' => false,
            'earned_leave' => false,
            'is_earned' => false,
            'requires_approval' => true,
            'auto_approve' => false,
            'special_conditions' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        return (int) DB::table('leave_settings')->insertGetId($payload);
    }

    private function insertLeaveForUser(int $userId, int $leaveTypeId, array $overrides = []): int
    {
        $payload = array_merge([
            'leave_type' => $leaveTypeId,
            'from_date' => now()->addDays(2)->toDateString(),
            'to_date' => now()->addDays(2)->toDateString(),
            'no_of_days' => 1,
            'reason' => 'Test leave reason.',
            'status' => 'New',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        if (Schema::hasColumn('leaves', 'user')) {
            $payload['user'] = $userId;
        }

        if (Schema::hasColumn('leaves', 'user_id')) {
            $payload['user_id'] = $userId;
        }

        if (Schema::hasColumn('leaves', 'submitted_at') && ! array_key_exists('submitted_at', $payload)) {
            $payload['submitted_at'] = now();
        }

        if (Schema::hasColumn('leaves', 'approved_at') && ($payload['status'] ?? '') === 'Approved' && ! array_key_exists('approved_at', $payload)) {
            $payload['approved_at'] = now();
        }

        return (int) DB::table('leaves')->insertGetId($payload);
    }

    private function insertHoliday(array $overrides = []): int
    {
        $payload = array_merge([
            'title' => 'Holiday',
            'from_date' => now()->toDateString(),
            'to_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        if (Schema::hasColumn('holidays', 'description') && ! array_key_exists('description', $payload)) {
            $payload['description'] = null;
        }

        if (Schema::hasColumn('holidays', 'type') && ! array_key_exists('type', $payload)) {
            $payload['type'] = 'company';
        }

        if (Schema::hasColumn('holidays', 'is_recurring') && ! array_key_exists('is_recurring', $payload)) {
            $payload['is_recurring'] = false;
        }

        if (Schema::hasColumn('holidays', 'recurrence_pattern') && ! array_key_exists('recurrence_pattern', $payload)) {
            $payload['recurrence_pattern'] = null;
        }

        if (Schema::hasColumn('holidays', 'is_active') && ! array_key_exists('is_active', $payload)) {
            $payload['is_active'] = true;
        }

        if (Schema::hasColumn('holidays', 'created_by') && ! array_key_exists('created_by', $payload)) {
            $payload['created_by'] = null;
        }

        if (Schema::hasColumn('holidays', 'updated_by') && ! array_key_exists('updated_by', $payload)) {
            $payload['updated_by'] = null;
        }

        return (int) DB::table('holidays')->insertGetId($payload);
    }

    private function resolveLeavesUserColumn(): string
    {
        return Schema::hasColumn('leaves', 'user_id') ? 'user_id' : 'user';
    }
}
