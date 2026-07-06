<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RosterCellWorkLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_update_cell_persists_work_location(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');
        $emp = User::factory()->create();
        $shift = Shift::factory()->create(['code' => 'D']);
        $loc = WorkLocation::create(['name' => 'Toll Plaza 9']);

        $this->actingAs($admin)->putJson(route('attendance.roster.cell'), [
            'user_id' => $emp->id, 'date' => '2026-07-06', 'shift_id' => $shift->id,
            'work_location_id' => $loc->id,
        ])->assertOk();

        $this->assertDatabaseHas('roster_days', [
            'user_id' => $emp->id, 'date' => '2026-07-06', 'work_location_id' => $loc->id,
        ]);
    }
}
