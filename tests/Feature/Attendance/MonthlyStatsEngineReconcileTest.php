<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MonthlyStatsEngineReconcileTest extends TestCase
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

    public function test_absent_man_days_are_exact_per_roster_not_estimated(): void
    {
        // Freeze "today" at 2026-06-03 so only 06-01..06-03 are in the analysis window.
        Carbon::setTestNow(Carbon::parse('2026-06-03 12:00:00'));

        // Monday is the weekly off. 2026-06-01 is a Monday => off; 06-02 Tue, 06-03 Wed work.
        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 15,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['monday'], 'auto_punch_out' => false,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');

        // Present on 06-02 only; 06-03 is a working day with no punch -> exactly 1 absent.
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-02', 'punchin' => '2026-06-02 09:00:00', 'punchout' => '2026-06-02 17:00:00',
        ]);

        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, false, $user->id);

        $this->assertSame(1, $stats['attendance']['present']);
        // Old daysPassed*2/7 estimate would report 2 here; the engine reports the exact 1.
        $this->assertSame(1, $stats['attendance']['absent']);
        $this->assertSame(0, $stats['attendance']['leaves']);
        $this->assertSame(50.0, $stats['attendance']['percentage']);
    }

    public function test_absent_excludes_days_before_joining(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-03 12:00:00'));

        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 15,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => [], 'auto_punch_out' => false,
        ]);

        // Joined 06-03: 06-01 and 06-02 must not accrue absents; only 06-03 (no punch) does.
        $user = User::factory()->create(['date_of_joining' => '2026-06-03']);
        $user->assignRole('Employee');

        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, false, $user->id);

        $this->assertSame(0, $stats['attendance']['present']);
        $this->assertSame(1, $stats['attendance']['absent']);
    }
}
