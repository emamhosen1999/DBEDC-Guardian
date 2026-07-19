<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Services\Attendance\CompOffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OvertimeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Manager']);
        foreach (['attendance.own.view', 'attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
    }

    public function test_overtime_approval_grants_comp_off(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $manager->givePermissionTo(['attendance.manage', 'attendance.correct', 'attendance.create', 'attendance.update']);
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.own.view');

        $this->actingAs($emp)->postJson(route('attendance.overtime.store'), [
            'date' => '2026-06-18', 'requested_minutes' => 120, 'reason' => 'release',
        ])->assertCreated();

        $ot = OvertimeRequest::first();
        $this->actingAs($manager)->postJson(route('attendance.overtime.approve', $ot->id), [
            'grant_comp_off' => true,
        ])->assertOk();

        $this->assertSame('approved', $ot->fresh()->status);
        $this->assertTrue($ot->fresh()->comp_off_granted);
        $this->assertSame(120, app(CompOffService::class)->balance($emp->id));
    }
}
