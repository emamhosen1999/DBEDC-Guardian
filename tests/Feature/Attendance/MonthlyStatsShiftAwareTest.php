<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MonthlyStatsShiftAwareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_monthly_late_uses_resolved_shift_not_global_nine(): void
    {
        // Global office start 09:00, but the user's shift starts 11:00.
        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 0,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['friday'], 'auto_punch_out' => false,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');
        $shift = Shift::factory()->create(['start_time' => '11:00', 'end_time' => '19:00', 'grace_in_minutes' => 0, 'full_day_minutes' => 0]);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        // Thursday 2026-06-18, punch 10:00 — LATE vs global 09:00, but ON-TIME vs the 11:00 shift.
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-18',
            'punchin' => Carbon::parse('2026-06-18 10:00'),
            'punchout' => Carbon::parse('2026-06-18 18:00'),
        ]);

        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, false, $user->id);

        // Engine-aware: 10:00 is before the 11:00 shift start -> NOT late.
        $this->assertSame(0, $stats['attendance']['lateArrivals']);
    }
}
