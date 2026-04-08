<?php

namespace Tests\Feature\Api;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileAttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_mobile_attendance_endpoints(): void
    {
        $this->getJson('/api/v1/attendance/today')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/present-users?date=2026-04-07')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/absent-users?date=2026-04-07')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/locations-today?date=2026-04-07')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/check-user-locations-updates/2026-04-07')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/check-timesheet-updates/2026-04-07/2026-04')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/daily-timesheet')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/team-locations')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/monthly-summary')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/history')->assertUnauthorized();
        $this->postJson('/api/v1/attendance/punch', [])->assertUnauthorized();
    }

    public function test_non_manager_cannot_access_mobile_daily_timesheet_endpoints(): void
    {
        $user = User::factory()->create([
            'active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/attendance/daily-timesheet')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access daily timesheet data.');

        $this->getJson('/api/v1/attendance/team-locations')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access team location data.');

        $this->getJson('/api/v1/attendance/present-users?date=2026-04-07')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access present users data.');

        $this->getJson('/api/v1/attendance/absent-users?date=2026-04-07')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access absent users data.');

        $this->getJson('/api/v1/attendance/locations-today?date=2026-04-07')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access team location data.');

        $this->getJson('/api/v1/attendance/check-user-locations-updates/2026-04-07')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to check location updates.');

        $this->getJson('/api/v1/attendance/check-timesheet-updates/2026-04-07/2026-04')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to check timesheet updates.');
    }

    public function test_authenticated_user_can_fetch_mobile_attendance_today_summary(): void
    {
        $user = User::factory()->create([
            'active' => true,
        ]);

        Attendance::query()->create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'punchin' => '09:00:00',
            'punchout' => '12:00:00',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/attendance/today');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_production_time', '03:00:00')
            ->assertJsonCount(1, 'data.punches');
    }

    public function test_authenticated_user_can_punch_in_and_out_from_mobile_api(): void
    {
        $attendanceType = AttendanceType::factory()->wifiIp()->create([
            'is_active' => true,
            'config' => [
                'ip_locations' => [],
                'validation_mode' => 'any',
                'allow_without_network' => true,
            ],
        ]);

        $user = User::factory()->create([
            'active' => true,
            'attendance_type_id' => $attendanceType->id,
        ]);

        Sanctum::actingAs($user);

        $firstPunchResponse = $this->postJson('/api/v1/attendance/punch', []);

        $firstPunchResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'punch_in');

        $secondPunchResponse = $this->postJson('/api/v1/attendance/punch', []);

        $secondPunchResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'punch_out');

        $attendance = Attendance::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($attendance);
        $this->assertNotNull($attendance->punchin);
        $this->assertNotNull($attendance->punchout);
    }

    public function test_mobile_punch_requires_active_attendance_type(): void
    {
        $user = User::factory()->create([
            'active' => true,
            'attendance_type_id' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', []);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'No active attendance type assigned to user.');
    }

    public function test_authenticated_user_can_fetch_monthly_mobile_attendance_history(): void
    {
        $user = User::factory()->create([
            'active' => true,
        ]);

        Attendance::query()->create([
            'user_id' => $user->id,
            'date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'punchin' => '09:00:00',
            'punchout' => '10:00:00',
        ]);

        Attendance::query()->create([
            'user_id' => $user->id,
            'date' => now()->startOfMonth()->addDays(3)->toDateString(),
            'punchin' => '11:00:00',
            'punchout' => '12:00:00',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/attendance/history?currentMonth='.now()->month.'&currentYear='.now()->year.'&perPage=10&page=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonCount(2, 'data.attendances');
    }

    public function test_manager_can_fetch_team_monthly_mobile_attendance_history_with_employee_filter(): void
    {
        $manager = User::factory()->create([
            'active' => true,
        ]);
        $this->assignRole($manager, 'Project Manager');

        $targetEmployee = User::factory()->create([
            'active' => true,
            'employee_id' => 'EMP-9001',
        ]);
        $this->assignRole($targetEmployee, 'Employee');

        $otherEmployee = User::factory()->create([
            'active' => true,
            'employee_id' => 'EMP-9002',
        ]);
        $this->assignRole($otherEmployee, 'Employee');

        Attendance::query()->create([
            'user_id' => $targetEmployee->id,
            'date' => now()->startOfMonth()->addDays(4)->toDateString(),
            'punchin' => '09:00:00',
            'punchout' => '12:00:00',
        ]);

        Attendance::query()->create([
            'user_id' => $targetEmployee->id,
            'date' => now()->startOfMonth()->addDays(5)->toDateString(),
            'punchin' => '09:30:00',
            'punchout' => '17:30:00',
        ]);

        Attendance::query()->create([
            'user_id' => $otherEmployee->id,
            'date' => now()->startOfMonth()->addDays(5)->toDateString(),
            'punchin' => '10:00:00',
            'punchout' => '15:00:00',
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson(
            '/api/v1/attendance/history?scope=team&currentMonth='.now()->month.'&currentYear='.now()->year.'&employee=EMP-9001&perPage=10&page=1'
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.attendances.0.user.employee_id', 'EMP-9001')
            ->assertJsonPath('data.attendances.1.user.employee_id', 'EMP-9001');
    }

    public function test_non_manager_cannot_fetch_team_mobile_attendance_history(): void
    {
        $user = User::factory()->create([
            'active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/attendance/history?scope=team&currentMonth='.now()->month.'&currentYear='.now()->year);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access team attendance history.');
    }

    public function test_authenticated_user_can_fetch_monthly_mobile_attendance_summary(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 20, 12, 0, 0));

        try {
            $user = User::factory()->create([
                'active' => true,
            ]);

            $otherUser = User::factory()->create([
                'active' => true,
            ]);

            Attendance::query()->create([
                'user_id' => $user->id,
                'date' => '2026-04-07',
                'punchin' => '09:30:00',
                'punchout' => '18:30:00',
            ]);

            Attendance::query()->create([
                'user_id' => $user->id,
                'date' => '2026-04-08',
                'punchin' => '09:00:00',
                'punchout' => '17:00:00',
            ]);

            Attendance::query()->create([
                'user_id' => $otherUser->id,
                'date' => '2026-04-08',
                'punchin' => '09:45:00',
                'punchout' => '19:45:00',
            ]);

            $leaveTypeId = $this->createLeaveType();

            $this->insertLeaveForUser($user->id, $leaveTypeId, [
                'from_date' => '2026-04-09',
                'to_date' => '2026-04-09',
                'no_of_days' => 1,
                'status' => 'Approved',
            ]);

            $this->insertHoliday([
                'title' => 'Holiday',
                'from_date' => '2026-04-10',
                'to_date' => '2026-04-10',
            ]);

            Sanctum::actingAs($user);

            $response = $this->getJson('/api/v1/attendance/monthly-summary?month=4&year=2026');

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.month', '2026-04')
                ->assertJsonPath('data.summary.working_days', 13)
                ->assertJsonPath('data.summary.present_days', 2)
                ->assertJsonPath('data.summary.absent_days', 10)
                ->assertJsonPath('data.summary.leave_days', 1)
                ->assertJsonPath('data.summary.late_arrivals', 1)
                ->assertJsonPath('data.summary.total_hours', 17)
                ->assertJsonPath('data.summary.average_daily_hours', 8.5)
                ->assertJsonPath('data.summary.overtime_hours', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_monthly_attendance_summary_validates_month_range(): void
    {
        $user = User::factory()->create([
            'active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/attendance/monthly-summary?month=13');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['month']);
    }

    public function test_manager_can_fetch_mobile_daily_timesheet_payload(): void
    {
        $manager = User::factory()->create([
            'active' => true,
        ]);
        $this->assignRole($manager, 'Project Manager');

        $presentEmployee = User::factory()->create([
            'active' => true,
            'employee_id' => 'EMP-1001',
        ]);
        $this->assignRole($presentEmployee, 'Employee');

        $absentEmployee = User::factory()->create([
            'active' => true,
            'employee_id' => 'EMP-1002',
        ]);
        $this->assignRole($absentEmployee, 'Employee');

        Attendance::query()->create([
            'user_id' => $presentEmployee->id,
            'date' => '2026-04-07',
            'punchin' => '09:00:00',
            'punchout' => '11:00:00',
        ]);

        Attendance::query()->create([
            'user_id' => $presentEmployee->id,
            'date' => '2026-04-07',
            'punchin' => '12:00:00',
            'punchout' => null,
        ]);

        $leaveTypeId = $this->createLeaveType([
            'type' => 'DailyTimesheet',
            'symbol' => 'DT',
        ]);

        $this->insertLeaveForUser($absentEmployee->id, $leaveTypeId, [
            'from_date' => '2026-04-07',
            'to_date' => '2026-04-07',
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/attendance/daily-timesheet?date=2026-04-07&page=1&perPage=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.selected_date', '2026-04-07')
            ->assertJsonPath('data.summary.present_count', 1)
            ->assertJsonPath('data.summary.absent_count', 1)
            ->assertJsonPath('data.summary.total_count', 2)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.attendances.0.user.id', $presentEmployee->id)
            ->assertJsonPath('data.attendances.0.total_work_minutes', 120)
            ->assertJsonPath('data.attendances.0.has_incomplete_punch', true)
            ->assertJsonPath('data.absent_users.0.id', $absentEmployee->id)
            ->assertJsonPath('data.leaves.0.user_id', $absentEmployee->id);
    }

    public function test_manager_can_fetch_mobile_team_locations_payload(): void
    {
        $manager = User::factory()->create([
            'active' => true,
        ]);
        $this->assignRole($manager, 'Project Manager');

        $attendanceType = AttendanceType::factory()->create([
            'name' => 'Geo Polygon Type',
            'slug' => 'geo_polygon_1',
            'is_active' => true,
            'config' => [
                'polygon' => [
                    ['lat' => 23.7806, 'lng' => 90.4070],
                    ['lat' => 23.7811, 'lng' => 90.4082],
                ],
            ],
        ]);

        $employee = User::factory()->create([
            'active' => true,
            'attendance_type_id' => $attendanceType->id,
        ]);
        $this->assignRole($employee, 'Employee');

        Attendance::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-04-07',
            'punchin' => '09:00:00',
            'punchout' => null,
            'punchin_location' => json_encode([
                'lat' => 23.7806,
                'lng' => 90.4070,
                'address' => 'Head Office',
            ]),
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/attendance/team-locations?date=2026-04-07');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.date', '2026-04-07')
            ->assertJsonPath('data.stats.total', 1)
            ->assertJsonPath('data.stats.checked_in', 1)
            ->assertJsonPath('data.stats.completed', 0)
            ->assertJsonPath('data.locations.0.user_id', $employee->id)
            ->assertJsonPath('data.locations.0.requires_photo', true)
            ->assertJsonPath('data.locations.0.cycles.0.is_complete', false);
    }

    public function test_manager_can_fetch_mobile_present_users_payload(): void
    {
        $manager = User::factory()->create([
            'active' => true,
        ]);
        $this->assignRole($manager, 'Project Manager');

        $employee = User::factory()->create([
            'active' => true,
            'employee_id' => 'EMP-2001',
        ]);
        $this->assignRole($employee, 'Employee');

        Attendance::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-04-07',
            'punchin' => '09:00:00',
            'punchout' => '11:00:00',
        ]);

        Attendance::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-04-07',
            'punchin' => '12:00:00',
            'punchout' => null,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/attendance/present-users?date=2026-04-07&page=1&perPage=10');

        $response->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('attendances.0.user_id', $employee->id)
            ->assertJsonPath('attendances.0.total_work_minutes', 120)
            ->assertJsonPath('attendances.0.has_incomplete_punch', true);
    }

    public function test_manager_can_fetch_mobile_absent_users_payload(): void
    {
        $manager = User::factory()->create([
            'active' => true,
        ]);
        $this->assignRole($manager, 'Project Manager');

        $presentEmployee = User::factory()->create([
            'active' => true,
            'employee_id' => 'EMP-3001',
        ]);
        $this->assignRole($presentEmployee, 'Employee');

        $absentEmployee = User::factory()->create([
            'active' => true,
            'employee_id' => 'EMP-3002',
        ]);
        $this->assignRole($absentEmployee, 'Employee');

        Attendance::query()->create([
            'user_id' => $presentEmployee->id,
            'date' => '2026-04-07',
            'punchin' => '09:00:00',
            'punchout' => '17:00:00',
        ]);

        $leaveTypeId = $this->createLeaveType([
            'type' => 'DailyTimesheet',
            'symbol' => 'DT',
        ]);

        $this->insertLeaveForUser($absentEmployee->id, $leaveTypeId, [
            'from_date' => '2026-04-07',
            'to_date' => '2026-04-07',
            'status' => 'Approved',
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/attendance/absent-users?date=2026-04-07');

        $response->assertOk()
            ->assertJsonPath('total_absent', 1)
            ->assertJsonPath('absent_users.0.id', $absentEmployee->id)
            ->assertJsonPath('leaves.0.user_id', $absentEmployee->id)
            ->assertJsonPath('leaves.0.leave_type_name', 'DailyTimesheet');
    }

    public function test_manager_can_fetch_mobile_locations_today_payload(): void
    {
        $manager = User::factory()->create([
            'active' => true,
        ]);
        $this->assignRole($manager, 'Project Manager');

        $attendanceType = AttendanceType::factory()->create([
            'name' => 'Geo Polygon Type 2',
            'slug' => 'geo_polygon_2',
            'is_active' => true,
            'config' => [
                'polygon' => [
                    ['lat' => 23.7806, 'lng' => 90.4070],
                    ['lat' => 23.7811, 'lng' => 90.4082],
                ],
            ],
        ]);

        $employee = User::factory()->create([
            'active' => true,
            'attendance_type_id' => $attendanceType->id,
        ]);
        $this->assignRole($employee, 'Employee');

        Attendance::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-04-07',
            'punchin' => '09:00:00',
            'punchout' => null,
            'punchin_location' => json_encode([
                'lat' => 23.7806,
                'lng' => 90.4070,
                'address' => 'Head Office',
            ]),
        ]);

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/attendance/locations-today?date=2026-04-07');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('date', '2026-04-07')
            ->assertJsonPath('locations.0.user_id', $employee->id)
            ->assertJsonPath('locations.0.requires_photo', true)
            ->assertJsonPath('attendance_type_configs.0.id', $attendanceType->id);
    }

    public function test_manager_can_check_mobile_attendance_update_status(): void
    {
        $manager = User::factory()->create([
            'active' => true,
        ]);
        $this->assignRole($manager, 'Project Manager');

        $employee = User::factory()->create([
            'active' => true,
        ]);
        $this->assignRole($employee, 'Employee');

        Attendance::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-04-07',
            'punchin' => '09:00:00',
            'punchout' => null,
        ]);

        Sanctum::actingAs($manager);

        $timesheetResponse = $this->getJson('/api/v1/attendance/check-timesheet-updates/2026-04-07/2026-04');

        $timesheetResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('has_updates', true)
            ->assertJsonPath('has_records', true);

        $this->assertNotNull($timesheetResponse->json('last_updated'));

        $locationResponse = $this->getJson('/api/v1/attendance/check-user-locations-updates/2026-04-07');

        $locationResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('has_updates', true);

        $this->assertNotNull($locationResponse->json('last_updated'));
    }

    public function test_mobile_attendance_update_status_validates_date_format_for_manager(): void
    {
        $manager = User::factory()->create([
            'active' => true,
        ]);
        $this->assignRole($manager, 'Project Manager');

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/attendance/check-timesheet-updates/07-04-2026/2026-04')
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid date format. Please use YYYY-MM-DD for date and YYYY-MM for month.');

        $this->getJson('/api/v1/attendance/check-user-locations-updates/07-04-2026')
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid date format. Please use YYYY-MM-DD.');
    }

    private function assignRole(User $user, string $roleName): void
    {
        Role::findOrCreate($roleName);
        $user->assignRole($roleName);
    }

    private function createLeaveType(array $overrides = []): int
    {
        $payload = array_merge([
            'type' => 'Casual',
            'symbol' => 'C',
            'days' => 12,
            'eligibility' => null,
            'carry_forward' => false,
            'earned_leave' => false,
            'is_earned' => false,
            'requires_approval' => true,
            'auto_approve' => false,
            'special_conditions' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        return (int) DB::table('leave_settings')->insertGetId($payload);
    }

    private function insertLeaveForUser(int $userId, int $leaveTypeId, array $overrides = []): int
    {
        $payload = array_merge([
            'leave_type' => $leaveTypeId,
            'from_date' => now()->addDays(2)->toDateString(),
            'to_date' => now()->addDays(2)->toDateString(),
            'no_of_days' => 1,
            'reason' => 'Test leave reason.',
            'status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        if (Schema::hasColumn('leaves', 'user')) {
            $payload['user'] = $userId;
        }

        if (Schema::hasColumn('leaves', 'user_id')) {
            $payload['user_id'] = $userId;
        }

        if (Schema::hasColumn('leaves', 'submitted_at') && ! array_key_exists('submitted_at', $payload)) {
            $payload['submitted_at'] = now();
        }

        if (Schema::hasColumn('leaves', 'approved_at') && ! array_key_exists('approved_at', $payload)) {
            $payload['approved_at'] = now();
        }

        return (int) DB::table('leaves')->insertGetId($payload);
    }

    private function insertHoliday(array $overrides = []): int
    {
        $payload = array_merge([
            'title' => 'Holiday',
            'from_date' => now()->toDateString(),
            'to_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        if (Schema::hasColumn('holidays', 'description') && ! array_key_exists('description', $payload)) {
            $payload['description'] = null;
        }

        if (Schema::hasColumn('holidays', 'type') && ! array_key_exists('type', $payload)) {
            $payload['type'] = 'company';
        }

        if (Schema::hasColumn('holidays', 'is_recurring') && ! array_key_exists('is_recurring', $payload)) {
            $payload['is_recurring'] = false;
        }

        if (Schema::hasColumn('holidays', 'recurrence_pattern') && ! array_key_exists('recurrence_pattern', $payload)) {
            $payload['recurrence_pattern'] = null;
        }

        if (Schema::hasColumn('holidays', 'is_active') && ! array_key_exists('is_active', $payload)) {
            $payload['is_active'] = true;
        }

        if (Schema::hasColumn('holidays', 'created_by') && ! array_key_exists('created_by', $payload)) {
            $payload['created_by'] = null;
        }

        if (Schema::hasColumn('holidays', 'updated_by') && ! array_key_exists('updated_by', $payload)) {
            $payload['updated_by'] = null;
        }

        return (int) DB::table('holidays')->insertGetId($payload);
    }
}
