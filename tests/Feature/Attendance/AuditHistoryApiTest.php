<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_returns_audit_rows_for_a_record_newest_first(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');
        $att = Attendance::factory()->create();

        AttendanceAuditLog::create([
            'actor_id' => $admin->id, 'attendance_id' => $att->id, 'action' => 'update',
            'before' => ['punchin' => '09:00'], 'after' => ['punchin' => '09:15'], 'reason' => 'fix',
        ]);

        $res = $this->actingAs($admin)->getJson(route('attendance.audit.history', $att->id));

        $res->assertOk();
        $res->assertJsonPath('logs.0.action', 'update');
        $res->assertJsonPath('logs.0.actor.id', $admin->id);
        $res->assertJsonPath('logs.0.reason', 'fix');
    }
}
