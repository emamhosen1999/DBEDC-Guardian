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
use Tests\TestCase;

class MobileAttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_mobile_attendance_endpoints(): void
    {
        $this->getJson('/api/v1/attendance/today')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/monthly-summary')->assertUnauthorized();
        $this->getJson('/api/v1/attendance/history')->assertUnauthorized();
        $this->postJson('/api/v1/attendance/punch', [])->assertUnauthorized();
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
