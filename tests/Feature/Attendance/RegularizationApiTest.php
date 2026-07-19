<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RegularizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Manager']);
        // The manager approve/reject routes are guarded by
        // permission:attendance.correct|attendance.create|attendance.update; the
        // fixture only granted attendance.manage, so the request 403'd (drift).
        foreach (['attendance.own.view', 'attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
    }

    public function test_employee_requests_then_manager_approves(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $manager->givePermissionTo(['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update']);
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.own.view');
        Attendance::factory()->for($emp)->create(['date' => '2026-06-18', 'punchin' => '2026-06-18 09:00:00', 'punchout' => null]);

        $this->actingAs($emp)->postJson(route('attendance.regularizations.store'), [
            'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00', 'reason' => 'forgot',
        ])->assertCreated();

        $req = AttendanceRegularization::first();
        $this->actingAs($manager)->postJson(route('attendance.regularizations.approve', $req->id))->assertOk();

        $this->assertSame('approved', $req->fresh()->status);
        $this->assertTrue($req->fresh()->applied);
    }

    public function test_employee_cannot_access_pending_queue(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.own.view');
        $this->actingAs($emp)->getJson(route('attendance.regularizations.pending'))->assertForbidden();
    }
}
