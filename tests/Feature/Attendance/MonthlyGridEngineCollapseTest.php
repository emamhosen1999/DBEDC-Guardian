<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendancePolicy;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MonthlyGridEngineCollapseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);

        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 15,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['friday'], 'auto_punch_out' => false,
        ]);
    }

    private function loadUser(int $id): User
    {
        return app(AttendanceReportService::class)
            ->getEmployeeUsersWithAttendanceAndLeaves(2026, 6)
            ->firstWhere('id', $id);
    }

    public function test_grid_total_work_hours_reflect_break_policy_and_match_buckets(): void
    {
        // Active org-wide breaks policy: 30-min unpaid meal once worked >= 360 min.
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 7001,
            'effective_from' => '2026-01-01',
            'rule_overrides' => ['breaks' => ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]],
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');

        // 2026-06-17 Wed, continuous 7h punch -> 420 raw, 390 after the 30-min auto-deduction.
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-17',
            'punchin' => '2026-06-17 09:00:00',
            'punchout' => '2026-06-17 16:00:00',
        ]);

        $data = app(AttendanceReportService::class)
            ->getUserAttendanceData($this->loadUser($user->id), 2026, 6, collect(), collect());

        $cell = $data['2026-06-17'];
        $this->assertSame('√', $cell['status']);
        $this->assertSame(390, $cell['worked_minutes']);
        $this->assertSame(30, $cell['break_deducted_minutes']);
        // The headline hours now come from the engine, not the raw span: 390 min = 06:30.
        $this->assertSame('06:30', $cell['total_work_hours']);
    }

    public function test_grid_holiday_beats_leave_on_no_punch_day(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        Holiday::create(['title' => 'Test Holiday', 'from_date' => '2026-06-15', 'to_date' => '2026-06-15']);
        $setting = LeaveSetting::factory()->create(['type' => 'Casual', 'symbol' => 'C']);
        Leave::factory()->for($user)->create([
            'leave_type' => $setting->id, 'status' => 'approved',
            'from_date' => '2026-06-15', 'to_date' => '2026-06-15',
        ]);

        $svc = app(AttendanceReportService::class);
        $data = $svc->getUserAttendanceData(
            $this->loadUser($user->id), 2026, 6, $svc->getHolidaysForMonth(2026, 6), LeaveSetting::all()
        );

        // No punch on a day that is both holiday and approved-leave -> Holiday wins.
        $this->assertSame('#', $data['2026-06-15']['status']);
        $this->assertSame('Holiday', $data['2026-06-15']['remarks']);
    }

    public function test_grid_leave_day_with_real_punch_stays_on_leave(): void
    {
        // A punch on an approved-leave day is a conflict for the (Phase B) exceptions
        // workflow to reconcile — NOT silently relabeled "Present" here. Capture is never
        // blocked; the day keeps the approved intent ("On Leave") and hides hours.
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $setting = LeaveSetting::factory()->create(['type' => 'Casual', 'symbol' => 'C']);
        Leave::factory()->for($user)->create([
            'leave_type' => $setting->id, 'status' => 'approved',
            'from_date' => '2026-06-16', 'to_date' => '2026-06-16',
        ]);
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-16',
            'punchin' => '2026-06-16 09:00:00',
            'punchout' => '2026-06-16 17:00:00',
        ]);

        $svc = app(AttendanceReportService::class);
        $data = $svc->getUserAttendanceData(
            $this->loadUser($user->id), 2026, 6, $svc->getHolidaysForMonth(2026, 6), LeaveSetting::all()
        );

        // The loader aliases leaves.leave_type to the leave-type NAME, so firstWhere('id', ...)
        // misses and the symbol falls back to '/'. Remarks must be "On Leave"; hours hidden.
        $this->assertSame('/', $data['2026-06-16']['status']);
        $this->assertSame('On Leave', $data['2026-06-16']['remarks']);
        $this->assertSame('00:00', $data['2026-06-16']['total_work_hours']);
    }
}
