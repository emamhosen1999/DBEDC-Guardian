<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\GrantsAttendancePermissions;
use Tests\TestCase;

/**
 * Mobile attendance writes enforce the SAME permission as the web route they mirror:
 *   punch (live and offline-queued) -> attendance.own.punch
 *   mark present                    -> attendance.correct | attendance.create | attendance.update
 */
class MobilePermissionParityTest extends TestCase
{
    use GrantsAttendancePermissions;
    use RefreshDatabase;

    private function punchableUser(): User
    {
        $type = AttendanceType::factory()->wifiIp()->create([
            'is_active' => true,
            'config' => ['ip_locations' => [], 'validation_mode' => 'any', 'allow_without_network' => true],
        ]);

        return User::factory()->create(['attendance_type_id' => $type->id]);
    }

    public function test_mobile_punch_without_the_own_punch_permission_is_forbidden(): void
    {
        Permission::findOrCreate('attendance.own.punch', 'web');
        $user = $this->punchableUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/attendance/punch', [])->assertForbidden();
        $this->assertDatabaseCount('attendances', 0);

        $this->grantPunch($user);
        $this->postJson('/api/v1/attendance/punch', [])->assertOk()->assertJsonPath('action', 'punch_in');
    }

    public function test_offline_queued_punch_without_the_permission_fails(): void
    {
        Permission::findOrCreate('attendance.own.punch', 'web');
        $user = $this->punchableUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/sync/push', ['mutations' => [[
            'idempotency_key' => 'punch-perm-'.$user->employee_id,
            'module' => 'attendance',
            'action' => 'punch',
            'payload' => [],
        ]]])->assertOk()
            ->assertJsonPath('data.summary.applied', 0)
            ->assertJsonPath('data.summary.failed', 1);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_a_manager_without_attendance_write_permission_cannot_mark_present(): void
    {
        foreach (['attendance.correct', 'attendance.create', 'attendance.update'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $manager = User::factory()->create();
        $member = User::factory()->create(['report_to' => $manager->employee_id]);
        Sanctum::actingAs($manager);

        // A direct report makes him a manager of the team, but not a writer of attendance.
        $this->postJson('/api/v1/attendance/mark-present', ['user_id' => $member->employee_id, 'date' => '2026-07-15'])
            ->assertForbidden();
    }
}
