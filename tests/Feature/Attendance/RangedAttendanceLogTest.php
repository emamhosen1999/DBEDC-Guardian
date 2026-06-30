<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Attendance\DTO\DayAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RangedAttendanceLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        AttendanceSetting::create([
            'office_start_time' => '09:00:00',
            'late_mark_after' => 15,
            'weekend_days' => ['friday'],
        ]);
    }

    private function employee(string $name = 'Worker'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('Employee');

        return $user;
    }

    public function test_user_attendance_data_exposes_status_code_and_is_complete(): void
    {
        $user = $this->employee();
        Attendance::create([
            'user_id' => $user->id,
            'date' => '2026-06-02',
            'punchin' => '2026-06-02 09:00:00',
            'punchout' => '2026-06-02 18:00:00',
        ]);

        $service = app(AttendanceReportService::class);
        $holidays = $service->getHolidaysForMonth(2026, 6);
        $data = $service->getUserAttendanceData($user, 2026, 6, $holidays, LeaveSetting::all());

        $this->assertArrayHasKey('status_code', $data['2026-06-02']);
        $this->assertArrayHasKey('is_complete', $data['2026-06-02']);
        $this->assertSame(DayAttendance::PRESENT, $data['2026-06-02']['status_code']);
        $this->assertTrue($data['2026-06-02']['is_complete']);
    }

    public function test_employee_selection_filters_by_employee_keyword(): void
    {
        $alice = $this->employee('Alice Example');
        $bob = $this->employee('Bob Sample');

        $service = app(AttendanceReportService::class);
        $users = $service->getEmployeeUsersWithAttendanceAndLeaves(2026, 6, null, null, null, 'Alice');

        $this->assertCount(1, $users);
        $this->assertSame($alice->id, $users->first()->id);
        $this->assertTrue($users->first()->relationLoaded('designation'));
    }

    public function test_ranged_log_spans_month_boundary_and_trims_to_range(): void
    {
        $user = $this->employee('Range Worker');
        Attendance::create([
            'user_id' => $user->id, 'date' => '2026-06-29',
            'punchin' => '2026-06-29 09:00:00', 'punchout' => '2026-06-29 18:00:00',
        ]);
        Attendance::create([
            'user_id' => $user->id, 'date' => '2026-07-01',
            'punchin' => '2026-07-01 09:00:00', 'punchout' => '2026-07-01 18:00:00',
        ]);

        $service = app(AttendanceReportService::class);
        $rows = $service->getRangedAttendanceLog(
            \Carbon\Carbon::parse('2026-06-29'),
            \Carbon\Carbon::parse('2026-07-01')
        );

        $dates = collect($rows)->where('user_id', $user->id)->pluck('date')->all();
        $this->assertSame(['2026-06-29', '2026-06-30', '2026-07-01'], $dates);
        $this->assertSame('Range Worker', $rows[0]['employee_name']);
    }

    public function test_ranged_log_status_filter_returns_only_absent(): void
    {
        $present = $this->employee('Present Person');
        $absent = $this->employee('Absent Person');
        Attendance::create([
            'user_id' => $present->id, 'date' => '2026-06-02',
            'punchin' => '2026-06-02 09:00:00', 'punchout' => '2026-06-02 18:00:00',
        ]);

        $service = app(AttendanceReportService::class);
        $rows = $service->getRangedAttendanceLog(
            \Carbon\Carbon::parse('2026-06-02'),
            \Carbon\Carbon::parse('2026-06-02'),
            ['status' => 'absent']
        );

        $names = collect($rows)->pluck('employee_name')->unique()->values()->all();
        $this->assertContains('Absent Person', $names);
        $this->assertNotContains('Present Person', $names);
    }
}
