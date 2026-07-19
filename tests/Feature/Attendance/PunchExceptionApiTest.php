<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PunchExceptionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Manager']);

        // These routes are guarded by
        // permission:attendance.correct|attendance.create|attendance.update.
        // The fixture used to grant only the (unrelated) attendance.manage, so
        // every manager request 403'd — the permission set drifted after these
        // tests were written.
        foreach (['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }

    public function test_manager_approves_a_provisional_punch(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update']);
        $emp = User::factory()->create();
        $att = Attendance::factory()->for($emp)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 06:00:00', 'punchout' => '2026-06-19 14:00:00',
            'policy_status' => 'provisional', 'needs_approval' => true, 'policy_exception_reason' => 'outside permitted window',
        ]);

        $this->actingAs($manager)->getJson(route('attendance.punch-exceptions.pending'))
            ->assertOk()->assertJsonFragment(['id' => $att->id]);

        $this->actingAs($manager)->postJson(route('attendance.punch-exceptions.approve', $att->id))->assertOk();
        $this->assertSame('accepted', $att->fresh()->policy_status);
        $this->assertFalse((bool) $att->fresh()->needs_approval);
    }

    public function test_employee_cannot_access_pending(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.punch-exceptions.pending'))->assertForbidden();
    }

    public function test_manager_rejects_a_provisional_punch(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update']);
        $emp = User::factory()->create();
        $att = Attendance::factory()->for($emp)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 06:00:00', 'punchout' => '2026-06-19 14:00:00',
            'policy_status' => 'provisional', 'needs_approval' => true, 'policy_exception_reason' => 'outside permitted window',
        ]);

        $this->actingAs($manager)->postJson(route('attendance.punch-exceptions.reject', $att->id), ['reason' => 'bad punch'])
            ->assertOk();

        $fresh = $att->fresh();
        $this->assertSame('rejected', $fresh->policy_status);
        $this->assertFalse((bool) $fresh->needs_approval);
        $this->assertSame('bad punch', $fresh->policy_exception_reason);
    }

    public function test_reject_without_reason_returns_validation_error(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update']);
        $emp = User::factory()->create();
        $att = Attendance::factory()->for($emp)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 06:00:00', 'punchout' => '2026-06-19 14:00:00',
            'policy_status' => 'provisional', 'needs_approval' => true, 'policy_exception_reason' => 'outside permitted window',
        ]);

        $this->actingAs($manager)->postJson(route('attendance.punch-exceptions.reject', $att->id), [])
            ->assertStatus(422);
    }

    public function test_rejected_punch_contributes_zero_worked_minutes_to_monthly_stats(): void
    {
        $emp = User::factory()->create();

        // Accepted day: 8 full hours worked, counts toward the monthly totals.
        Attendance::factory()->for($emp)->create([
            'date' => '2026-06-10', 'punchin' => '2026-06-10 09:00:00', 'punchout' => '2026-06-10 17:00:00',
            'policy_status' => 'accepted', 'needs_approval' => false,
        ]);

        // Rejected day: would be 10 hours if counted, but must contribute 0 worked minutes
        // because calculateMonthlyStats() excludes policy_status = 'rejected' at the query level.
        Attendance::factory()->for($emp)->create([
            'date' => '2026-06-11', 'punchin' => '2026-06-11 08:00:00', 'punchout' => '2026-06-11 18:00:00',
            'policy_status' => 'rejected', 'needs_approval' => false, 'policy_exception_reason' => 'bad punch',
        ]);

        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, false, $emp->id);

        // Only the accepted day's man-day and hours should be reflected.
        $this->assertSame(1, $stats['attendance']['present']);
        $this->assertSame(8.0, $stats['hours']['totalWork']);
    }
}
