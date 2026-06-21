<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Only APPROVED leaves may mark a day "On Leave" in the monthly grid. A pending (or
 * rejected) leave must NOT mask an absence — otherwise a not-yet-approved leave hides a
 * real absence and disagrees with the approved-only stats.
 */
class LeaveStatusGridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_pending_leave_does_not_mask_absence_only_approved_shows_on_leave(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole('Employee');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $type = LeaveSetting::create(['type' => 'Casual', 'days' => 10, 'carry_forward' => false, 'earned_leave' => false]);

        // Pending leave on Wed Jun 17 — must NOT show as On Leave.
        Leave::create([
            'user_id' => $emp->id, 'leave_type' => $type->id,
            'from_date' => '2026-06-17', 'to_date' => '2026-06-17', 'no_of_days' => 1, 'status' => 'pending', 'reason' => 'x',
        ]);
        // Approved leave on Thu Jun 18 — SHOULD show as On Leave.
        Leave::create([
            'user_id' => $emp->id, 'leave_type' => $type->id,
            'from_date' => '2026-06-18', 'to_date' => '2026-06-18', 'no_of_days' => 1, 'status' => 'approved', 'reason' => 'y',
        ]);

        $payload = $this->actingAs($admin)
            ->getJson(route('attendancesAdmin.paginate').'?'.http_build_query(['currentMonth' => 6, 'currentYear' => 2026]))
            ->assertOk()
            ->json();

        $row = collect($payload['data'])->firstWhere('user_id', $emp->id);
        $this->assertNotNull($row);

        // Pending leave day → NOT on leave (it's an absence).
        $this->assertNotSame('On Leave', $row['2026-06-17']['remarks'] ?? null);
        // Approved leave day → On Leave.
        $this->assertSame('On Leave', $row['2026-06-18']['remarks'] ?? null);
    }
}
