<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceAuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceAuditEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        foreach (['attendance.correct', 'attendance.manage'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
    }

    public function test_marking_a_user_present_writes_an_audit_row(): void
    {
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.manage');

        $user = User::factory()->create();

        $this->actingAs($admin)
            ->postJson(route('attendance.mark-as-present'), [
                'user_id' => $user->id,
                'date' => '2026-06-19',
            ])
            ->assertOk();

        $attendance = Attendance::where('user_id', $user->id)->where('date', '2026-06-19')->first();
        $this->assertNotNull($attendance);

        $log = AttendanceAuditLog::where('attendance_id', $attendance->id)->where('action', 'mark_present')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_correcting_a_record_writes_an_audit_row(): void
    {
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.correct');

        $user = User::factory()->create();
        $a = Attendance::factory()->for($user)->create([
            'date' => '2026-06-19',
            'punchin' => Carbon::parse('2026-06-19 09:00'),
            'punchout' => Carbon::parse('2026-06-19 17:00'),
        ]);

        $this->actingAs($admin)
            ->postJson(route('attendance.correct.update', $a->id), [
                'punchin' => '2026-06-19 09:15:00',
                'reason' => 'late correction',
            ])
            ->assertOk();

        $log = AttendanceAuditLog::where('attendance_id', $a->id)->where('action', 'update')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('late correction', $log->reason);
    }

    public function test_deleting_a_record_writes_an_audit_row(): void
    {
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.correct');
        $a = Attendance::factory()->create(['date' => '2026-06-19']);

        $this->actingAs($admin)
            ->deleteJson(route('attendance.correct.delete', $a->id), ['reason' => 'duplicate'])
            ->assertOk();

        $this->assertDatabaseHas('attendance_audit_logs', [
            'attendance_id' => $a->id, 'action' => 'delete', 'actor_id' => $admin->id,
        ]);
    }

    public function test_correcting_punchout_before_existing_punchin_fails_validation(): void
    {
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.correct');

        $user = User::factory()->create();
        $a = Attendance::factory()->for($user)->create([
            'date' => '2026-06-19',
            'punchin' => Carbon::parse('2026-06-19 09:00'),
            'punchout' => Carbon::parse('2026-06-19 17:00'),
        ]);

        // Attempting to set punchout to 08:30 (before punchin 09:00)
        $this->actingAs($admin)
            ->postJson(route('attendance.correct.update', $a->id), [
                'punchout' => '2026-06-19 08:30:00',
                'reason' => 'invalid update',
            ])
            ->assertStatus(422);
    }
}
