<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Offboarding;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TerminationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_user_offboarding_relation_returns_latest_non_cancelled_record(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();

        Offboarding::forceCreate([
            'employee_id' => $user->id,
            'initiation_date' => '2026-05-01',
            'last_working_date' => '2026-05-31',
            'reason' => Offboarding::REASON_RESIGNATION,
            'status' => Offboarding::STATUS_CANCELLED,
            'created_by' => $admin->id,
        ]);
        $active = Offboarding::forceCreate([
            'employee_id' => $user->id,
            'initiation_date' => '2026-06-01',
            'last_working_date' => '2026-06-15',
            'reason' => Offboarding::REASON_TERMINATION,
            'status' => Offboarding::STATUS_IN_PROGRESS,
            'created_by' => $admin->id,
        ]);

        $resolved = $user->fresh()->offboarding;

        $this->assertNotNull($resolved);
        $this->assertSame($active->id, $resolved->id);
        $this->assertSame('2026-06-15', $resolved->last_working_date->toDateString());
    }

    public function test_dashboard_does_not_count_absent_after_last_working_date(): void
    {
        // Freeze "today" to after June 2026 so the whole month is in the past and every
        // scheduled working day would otherwise be Absent (no punches at all).
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

        // Saturday + Sunday are weekends; all other days are working days.
        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 15,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['saturday', 'sunday'], 'auto_punch_out' => false,
        ]);

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        // Employee terminated; last_working_date = 2026-06-15 (a Monday).
        Offboarding::forceCreate([
            'employee_id' => $employee->id,
            'initiation_date' => '2026-06-01',
            'last_working_date' => '2026-06-15',
            'reason' => Offboarding::REASON_TERMINATION,
            'status' => Offboarding::STATUS_COMPLETED,
            'created_by' => $employee->id,
        ]);

        $stats = app(AttendanceReportService::class)
            ->calculateMonthlyStats(6, 2026, true, null);

        // Working days Mon-Fri in June 1–15:
        // Week 1: Mon 1, Tue 2, Wed 3, Thu 4, Fri 5      → 5
        // Week 2: Mon 8, Tue 9, Wed 10, Thu 11, Fri 12   → 5
        // Week 3: Mon 15                                  → 1
        // Total: 11 working days on/before the 15th.
        $expectedAbsent = 11;

        // No absents after the 15th may appear in the count.
        $this->assertSame($expectedAbsent, $stats['attendance']['absent'],
            'Absent count must not include days after last_working_date.'
        );
    }
}
