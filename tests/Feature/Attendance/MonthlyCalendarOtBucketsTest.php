<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3.1 Task 6: per-day OT/break/double-time buckets must additively
 * surface in the attendance monthly calendar payload (the seam consumed by
 * the paginate endpoint, AttendanceController::paginate / getUserAttendanceData,
 * and reused by the Excel export).
 */
class MonthlyCalendarOtBucketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_monthly_calendar_payload_exposes_ot_and_double_time_buckets_for_overtime_day(): void
    {
        $user = User::factory()->create(['name' => 'OT Employee']);
        $user->assignRole('Employee');

        $admin = User::factory()->create(['name' => 'Stats Admin']);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        // Active org-wide overtime policy: 480 min (8h) daily threshold, no double-time band.
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 9001,
            'effective_from' => '2026-01-01',
            'rule_overrides' => [
                'overtime' => ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5],
            ],
        ]);

        // 2026-06-17 is a Wednesday (working day under default 09:00-17:00 schedule).
        $date = Carbon::parse('2026-06-17');

        // Single punch spanning 10.5h (630 min) -> regular=480, ot=150, double_time=0.
        Attendance::create([
            'user_id' => $user->id,
            'date' => $date,
            'punchin' => $date->copy()->setTime(9, 0),
            'punchout' => $date->copy()->setTime(19, 30),
            'punchin_location' => json_encode(['test' => 'location']),
            'punchout_location' => json_encode(['test' => 'location']),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('attendancesAdmin.paginate').'?'.http_build_query([
                'currentMonth' => 6,
                'currentYear' => 2026,
            ]));

        $response->assertStatus(200);
        $response->assertJsonMissing(['error']);

        $payload = $response->json();
        $userRow = collect($payload['data'])->firstWhere('user_id', $user->id);

        $this->assertNotNull($userRow, 'Expected the OT employee row in the paginated payload.');

        $dayEntry = $userRow['2026-06-17'] ?? null;
        $this->assertNotNull($dayEntry, 'Expected a per-day entry for 2026-06-17.');

        // Back-compat: legacy keys must still be present and untouched.
        $this->assertSame('√', $dayEntry['status']);
        $this->assertArrayHasKey('punch_in', $dayEntry);
        $this->assertArrayHasKey('punch_out', $dayEntry);
        $this->assertArrayHasKey('total_work_hours', $dayEntry);
        $this->assertArrayHasKey('remarks', $dayEntry);

        // New additive bucket fields (Task 6).
        $this->assertArrayHasKey('ot_minutes', $dayEntry);
        $this->assertArrayHasKey('worked_minutes', $dayEntry);
        $this->assertArrayHasKey('double_time_minutes', $dayEntry);
        $this->assertArrayHasKey('regular_minutes', $dayEntry);
        $this->assertArrayHasKey('break_deducted_minutes', $dayEntry);
        $this->assertArrayHasKey('policy_events', $dayEntry);

        $this->assertSame(630, $dayEntry['worked_minutes']);
        $this->assertSame(480, $dayEntry['regular_minutes']);
        $this->assertSame(150, $dayEntry['ot_minutes']);
        $this->assertSame(0, $dayEntry['double_time_minutes']);
        $this->assertSame(0, $dayEntry['break_deducted_minutes']);
    }

    public function test_monthly_calendar_payload_exposes_break_deduction_when_breaks_policy_active(): void
    {
        $user = User::factory()->create(['name' => 'Break Employee']);
        $user->assignRole('Employee');

        $admin = User::factory()->create(['name' => 'Stats Admin 2']);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        // Active org-wide breaks policy: 30 min unpaid meal required once worked >= 360 min.
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 9002,
            'effective_from' => '2026-01-01',
            'rule_overrides' => [
                'breaks' => ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360],
            ],
        ]);

        $date = Carbon::parse('2026-06-17');

        // Single continuous punch (no break punches recorded) spanning 7h (420 min).
        // BreaksEvaluator: breakTaken=0 (firstIn..lastOut span equals workedMinutes),
        // so the full 30-minute shortfall is auto-deducted -> worked_minutes = 390.
        Attendance::create([
            'user_id' => $user->id,
            'date' => $date,
            'punchin' => $date->copy()->setTime(9, 0),
            'punchout' => $date->copy()->setTime(16, 0),
            'punchin_location' => json_encode(['test' => 'location']),
            'punchout_location' => json_encode(['test' => 'location']),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('attendancesAdmin.paginate').'?'.http_build_query([
                'currentMonth' => 6,
                'currentYear' => 2026,
            ]));

        $response->assertStatus(200);

        $payload = $response->json();
        $userRow = collect($payload['data'])->firstWhere('user_id', $user->id);
        $dayEntry = $userRow['2026-06-17'] ?? null;

        $this->assertNotNull($dayEntry);
        $this->assertSame(30, $dayEntry['break_deducted_minutes']);
        $this->assertSame(390, $dayEntry['worked_minutes']);
        $this->assertArrayHasKey('policy_events', $dayEntry);
        $this->assertIsArray($dayEntry['policy_events']);
    }
}
