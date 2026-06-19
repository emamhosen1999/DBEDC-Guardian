<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RosterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_admin_can_create_shift_and_override_a_roster_cell(): void
    {
        $admin = User::factory()->create(); $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();

        $shift = Shift::factory()->create();

        $this->actingAs($admin)->putJson(route('attendance.roster.cell'), [
            'user_id' => $emp->id, 'date' => '2026-06-19', 'shift_id' => $shift->id,
        ])->assertOk();

        $this->assertDatabaseHas('roster_days', [
            'user_id' => $emp->id, 'source' => 'manual', 'shift_id' => $shift->id,
        ]);
    }

    public function test_employee_without_settings_permission_is_forbidden(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->putJson(route('attendance.roster.cell'), [
            'user_id' => $emp->id, 'date' => '2026-06-19', 'shift_id' => null,
        ])->assertForbidden();
    }
}
