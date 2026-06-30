<?php

namespace Tests\Feature\Attendance;

use App\Jobs\ExportAttendanceReport;
use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Attendance\DTO\DayAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
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

    public function test_attendance_log_endpoint_returns_paginated_rows(): void
    {
        Permission::firstOrCreate(['name' => 'attendance.view']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');
        $user = $this->employee('Log Worker');
        Attendance::create([
            'user_id' => $user->id, 'date' => '2026-06-02',
            'punchin' => '2026-06-02 09:00:00', 'punchout' => '2026-06-02 18:00:00',
        ]);

        $response = $this->actingAs($admin)->getJson(route('attendance.log', [
            'from' => '2026-06-02', 'to' => '2026-06-02', 'perPage' => 25,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'rows' => [['date', 'employee_name', 'clock_in', 'clock_out', 'work_hours', 'status']],
            'total', 'page', 'per_page', 'last_page', 'applied_filters',
        ]);
        $response->assertJsonPath('applied_filters.from', '2026-06-02');
        $response->assertJsonPath('total', 1);
        $response->assertJsonCount(1, 'rows');
    }

    public function test_range_export_saves_xlsx_with_rows(): void
    {
        Storage::fake('public');
        $user = $this->employee('Export Worker');
        Attendance::create([
            'user_id' => $user->id, 'date' => '2026-06-02',
            'punchin' => '2026-06-02 09:00:00', 'punchout' => '2026-06-02 18:00:00',
        ]);

        $path = 'exports/test_range.xlsx';
        (new \App\Exports\AttendanceRangeExport)->saveToDisk('2026-06-01', '2026-06-03', [], $path, 'public');

        Storage::disk('public')->assertExists($path);
        $loaded = \PhpOffice\PhpSpreadsheet\IOFactory::load(Storage::disk('public')->path($path));
        $cells = $loaded->getActiveSheet()->toArray();
        $flat = json_encode($cells);
        $this->assertStringContainsString('Export Worker', $flat);
        $this->assertStringContainsString('Clock In', $flat);
        $this->assertStringContainsString('Present', $flat);
    }

    public function test_log_export_dispatches_range_excel_job(): void
    {
        Queue::fake();
        Permission::firstOrCreate(['name' => 'attendance.view']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $response = $this->actingAs($admin)->getJson(route('attendance.log.export', [
            'from' => '2026-06-01', 'to' => '2026-06-10', 'type' => 'excel',
        ]));

        $response->assertStatus(200)->assertJson(['success' => true, 'queued' => true]);
        $response->assertJsonStructure(['download_url', 'filename']);
        Queue::assertPushed(ExportAttendanceReport::class, function ($job) {
            // filters are private; covered structurally — only getType() is publicly observable
            return $job->getType() === 'range_excel';
        });
    }

    public function test_log_export_pdf_rejects_range_over_cap(): void
    {
        Permission::firstOrCreate(['name' => 'attendance.view']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $response = $this->actingAs($admin)->getJson(route('attendance.log.export', [
            'from' => '2026-01-01', 'to' => '2026-06-30', 'type' => 'pdf',
        ]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    public function test_log_export_pdf_cap_boundary(): void
    {
        Permission::firstOrCreate(['name' => 'attendance.view']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $from = '2026-01-01';
        $allowedTo = \Carbon\Carbon::parse($from)->addDays(62)->toDateString(); // diff 62 → allowed
        $rejectedTo = \Carbon\Carbon::parse($from)->addDays(63)->toDateString(); // diff 63 → rejected

        $this->actingAs($admin)
            ->getJson(route('attendance.log.export', ['from' => $from, 'to' => $allowedTo, 'type' => 'pdf']))
            ->assertStatus(200);

        $this->actingAs($admin)
            ->getJson(route('attendance.log.export', ['from' => $from, 'to' => $rejectedTo, 'type' => 'pdf']))
            ->assertStatus(422);
    }
}
