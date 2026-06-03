<?php

namespace Tests\Feature;

use App\Exports\AttendanceAdminExport;
use App\Jobs\ExportAttendanceReport;
use App\Models\User;
use App\Models\HRM\AttendanceSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceExportAndStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'attendance.view']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'attendance.own.view']);

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

    public function test_attendance_admin_export_save_to_disk(): void
    {
        Storage::fake('public');

        $export = new AttendanceAdminExport();
        $filename = 'test_export.xlsx';
        $filePath = 'exports/' . $filename;

        $export->saveToDisk('2026-06', $filePath, 'public');

        Storage::disk('public')->assertExists($filePath);
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
        \App\Models\HRM\AttendanceType::create([
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
