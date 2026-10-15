<?php

namespace Tests\Feature;

use App\Exports\AttendanceAdminExport;
use App\Jobs\ExportAttendanceReport;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\AttendanceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceExportAndStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);

        AttendanceSetting::create([
            'office_start_time' => '09:00:00',
            'late_mark_after' => 15,
            'weekend_days' => ['saturday', 'sunday'],
        ]);
    }

    public function test_export_monthly_calendar_dispatches_queue_job(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $response = $this->actingAs($admin)
            ->getJson(route('attendance.monthlyCalendar.export', [
                'month' => '2026-06',
                'type' => 'excel',
            ]));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'queued' => true,
        ]);
        $response->assertJsonStructure(['download_url', 'filename']);

        Queue::assertPushed(ExportAttendanceReport::class, function ($job) {
            return $job->getType() === 'monthly_excel';
        });
    }

    public function test_export_register_takes_any_period_and_defaults_to_the_payroll_cycle(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $this->actingAs($admin)->getJson(route('attendance.monthlyCalendar.export', ['from' => '2026-08-26', 'to' => '2026-09-25', 'type' => 'pdf']))
            ->assertOk()->assertJsonPath('success', true);
        Queue::assertPushed(ExportAttendanceReport::class, fn ($job) => $job->getType() === 'register_pdf');

        // No period at all: the payroll cycle (26th to 25th) containing today.
        $this->actingAs($admin)->getJson(route('attendance.monthlyCalendar.export', ['type' => 'excel']))->assertOk();
        Queue::assertPushed(ExportAttendanceReport::class, fn ($job) => $job->getType() === 'register_excel');

        // Longer than a spreadsheet / PDF accepts.
        $this->actingAs($admin)->getJson(route('attendance.monthlyCalendar.export', ['from' => '2025-01-01', 'to' => '2026-12-31', 'type' => 'excel']))->assertStatus(422);
        $this->actingAs($admin)->getJson(route('attendance.monthlyCalendar.export', ['from' => '2026-01-01', 'to' => '2026-12-31', 'type' => 'pdf']))->assertStatus(422);
    }

    public function test_attendance_admin_export_save_to_disk(): void
    {
        Storage::fake('public');

        $export = new AttendanceAdminExport;
        $filename = 'test_export.xlsx';
        $filePath = 'exports/'.$filename;

        $export->saveToDisk('2026-06', $filePath, 'public');

        Storage::disk('public')->assertExists($filePath);

        // The monthly export is now the register: one sheet per department, then the Summary sheet.
        $savedPath = Storage::disk('public')->path($filePath);
        $loaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($savedPath);
        $sheetNames = $loaded->getSheetNames();

        $this->assertSame('Summary', end($sheetNames));
        $this->assertContains('Summary', $sheetNames);
    }

    public function test_get_monthly_attendance_stats_enforces_auth_id_for_regular_employees(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');
        $employee->givePermissionTo('attendance.own.view');

        $otherUser = User::factory()->create();

        // Acting as employee, requesting stats for another user
        $response = $this->actingAs($employee)
            ->getJson(route('attendance.myMonthlyStats', [
                'currentMonth' => 6,
                'currentYear' => 2026,
                'userId' => $otherUser->id,
            ]));

        $response->assertStatus(200);
        // Assert scope is Single (not Global)
        $response->assertJsonPath('stats.meta.scope', 'Single');
        $response->assertJsonPath('stats.meta.totalEmployees', 1);
    }

    public function test_get_user_locations_for_date_returns_expected_structure(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        // Create AttendanceType config
        AttendanceType::create([
            'name' => 'Geofence Test',
            'slug' => 'geo_polygon_1',
            'config' => ['polygon' => []],
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('getUserLocationsForDate', [
                'date' => '2026-06-03',
            ]));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
        $response->assertJsonStructure([
            'success',
            'locations',
            'attendance_type_configs',
        ]);
    }
}
