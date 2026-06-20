<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
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
        Permission::firstOrCreate(['name' => 'attendance.manage']);
    }

    public function test_manager_approves_a_provisional_punch(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('attendance.manage');
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
}
