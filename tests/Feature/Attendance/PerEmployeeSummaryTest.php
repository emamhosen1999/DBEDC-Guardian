<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Department;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PerEmployeeSummaryTest extends TestCase
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

    /**
     * Seed a realistic June 2026 fixture with:
     *  - emp1: fully present (Mon-Fri, no weekends; punches every working day)
     *  - emp2: one absent day (present on 2026-06-02 only; 2026-06-03 absent)
     *  - emp3: one approved leave day on 2026-06-02; no punches
     *
     * Freeze "today" to 2026-06-30 end-of-day so the whole month is analysed.
     * Weekend = Saturday+Sunday. Window analysed: 2026-06-01..2026-06-30
     */
    private function seedFixtureMonth(): array
    {
        // Freeze to after the full month so every day is included in analysis.
        Carbon::setTestNow(Carbon::parse('2026-06-30 23:59:59'));

        AttendanceSetting::create([
            'office_start_time'  => '09:00',
            'office_end_time'    => '17:00',
            'break_time_duration' => 0,
            'late_mark_after'    => 15,
            'early_leave_before' => 0,
            'overtime_after'     => 0,
            'weekend_days'       => ['saturday', 'sunday'],
            'auto_punch_out'     => false,
        ]);

        $deptA = Department::factory()->create(['name' => 'Engineering']);
        $deptB = Department::factory()->create(['name' => 'Finance']);

        // emp1: fully present — punches on every Mon-Fri in June 2026
        $emp1 = User::factory()->create(['department_id' => $deptA->id]);
        $emp1->assignRole('Employee');
        // June 2026 weekdays: 1,2,3,4,5, 8,9,10,11,12, 15,16,17,18,19, 22,23,24,25,26, 29,30
        $workdays = [
            '2026-06-01','2026-06-02','2026-06-03','2026-06-04','2026-06-05',
            '2026-06-08','2026-06-09','2026-06-10','2026-06-11','2026-06-12',
            '2026-06-15','2026-06-16','2026-06-17','2026-06-18','2026-06-19',
            '2026-06-22','2026-06-23','2026-06-24','2026-06-25','2026-06-26',
            '2026-06-29','2026-06-30',
        ];
        foreach ($workdays as $d) {
            Attendance::factory()->for($emp1)->create([
                'date'     => $d,
                'punchin'  => $d.' 09:00:00',
                'punchout' => $d.' 17:00:00',
            ]);
        }

        // emp2: present on 2026-06-02 only; all other working days absent
        $emp2 = User::factory()->create(['department_id' => $deptA->id]);
        $emp2->assignRole('Employee');
        Attendance::factory()->for($emp2)->create([
            'date'     => '2026-06-02',
            'punchin'  => '2026-06-02 09:00:00',
            'punchout' => '2026-06-02 17:00:00',
        ]);

        // emp3: approved leave on 2026-06-02 (a Monday — working day); no punches
        $emp3 = User::factory()->create(['department_id' => $deptB->id]);
        $emp3->assignRole('Employee');
        $leaveType = LeaveSetting::create([
            'type' => 'Casual', 'days' => 10,
            'carry_forward' => false, 'earned_leave' => false,
        ]);
        Leave::create([
            'user_id'    => $emp3->id,
            'leave_type' => $leaveType->id,
            'from_date'  => '2026-06-02',
            'to_date'    => '2026-06-02',
            'no_of_days' => 1,
            'status'     => 'approved',
            'reason'     => 'test',
        ]);

        return [$emp1, $emp2, $emp3, $deptA, $deptB];
    }

    public function test_summary_reconciles_with_dashboard_and_identity(): void
    {
        // Arrange a fixture month with a mix of present/late/absent/leave.
        [$emp1, $emp2, $emp3, $deptA, $deptB] = $this->seedFixtureMonth();

        $service = app(AttendanceReportService::class);

        $summary = $service->getPerEmployeeMonthlySummary(2026, 6);
        $stats   = $service->calculateMonthlyStats(6, 2026, true, null);

        $this->assertArrayHasKey('rows', $summary);
        $this->assertArrayHasKey('meta', $summary);
        $this->assertNotEmpty($summary['rows'], 'Rows must not be empty');

        // Per-row identity: Present + Absent + Leave = Working Days.
        foreach ($summary['rows'] as $row) {
            $this->assertSame(
                $row['present'] + $row['absent'] + $row['leave'],
                $row['working_days'],
                "Row identity failed for {$row['employee_name']}"
            );
            $denom = $row['present'] + $row['absent'];
            $expectedPct = $denom > 0 ? round($row['present'] / $denom * 100, 1) : 0.0;
            $this->assertSame($expectedPct, $row['attendance_percentage']);
        }

        // Aggregates reconcile with the dashboard's single-engine counts.
        $this->assertSame($stats['attendance']['present'],      array_sum(array_column($summary['rows'], 'present')));
        $this->assertSame($stats['attendance']['absent'],       array_sum(array_column($summary['rows'], 'absent')));
        $this->assertSame($stats['attendance']['leaves'],       array_sum(array_column($summary['rows'], 'leave')));
        $this->assertSame($stats['attendance']['lateArrivals'], array_sum(array_column($summary['rows'], 'late')));
    }

    public function test_row_keys_are_complete(): void
    {
        $this->seedFixtureMonth();

        $summary = app(AttendanceReportService::class)->getPerEmployeeMonthlySummary(2026, 6);
        $requiredKeys = [
            'employee_name', 'employee_id', 'department',
            'present', 'absent', 'leave',
            'ot_hours', 'late', 'holidays_worked', 'weekly_off_worked',
            'working_days', 'attendance_percentage',
        ];

        foreach ($summary['rows'] as $row) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $row, "Missing key '{$key}' in row for {$row['employee_name']}");
            }
        }
    }

    public function test_department_filter_narrows_rows(): void
    {
        [, , , $deptA, $deptB] = $this->seedFixtureMonth();

        $service = app(AttendanceReportService::class);

        $all = $service->getPerEmployeeMonthlySummary(2026, 6);
        $this->assertNotEmpty($all['rows']);

        // Filter to deptB (Finance) — only emp3 is there.
        $deptBRows = $service->getPerEmployeeMonthlySummary(2026, 6, $deptB->id);
        $this->assertCount(1, $deptBRows['rows'], 'Department filter must narrow rows to deptB employees only');

        // A non-matching department id yields zero rows.
        $none = $service->getPerEmployeeMonthlySummary(2026, 6, 999999);
        $this->assertCount(0, $none['rows']);
    }
}
