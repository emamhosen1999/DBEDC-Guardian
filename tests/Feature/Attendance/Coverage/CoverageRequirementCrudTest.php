<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CoverageRequirementCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->assignRole('Admin');
        $a->givePermissionTo('attendance.settings');

        return $a;
    }

    public function test_store_creates_requirement(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);

        $this->actingAs($this->admin())->postJson(route('attendance.coverageRequirements.store'), [
            'work_location_id' => $loc->id, 'shift_id' => $shift->id,
            'required_headcount' => 3, 'weekday' => null, 'date' => null,
        ])->assertOk();

        $this->assertDatabaseHas('coverage_requirements', [
            'work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 3,
        ]);
    }

    public function test_store_rejects_bad_headcount_and_weekday(): void
    {
        $loc = WorkLocation::create(['name' => 'X']);
        $shift = Shift::factory()->create(['code' => 'D']);

        $this->actingAs($this->admin())->postJson(route('attendance.coverageRequirements.store'), [
            'work_location_id' => $loc->id, 'shift_id' => $shift->id,
            'required_headcount' => -1, 'weekday' => 9,
        ])->assertStatus(422);
    }

    public function test_update_and_delete(): void
    {
        $loc = WorkLocation::create(['name' => 'X']);
        $shift = Shift::factory()->create(['code' => 'D']);
        $req = CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 1, 'is_active' => true]);

        $this->actingAs($this->admin())->putJson(route('attendance.coverageRequirements.update', $req->id), [
            'work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 4,
        ])->assertOk();
        $this->assertDatabaseHas('coverage_requirements', ['id' => $req->id, 'required_headcount' => 4]);

        $this->actingAs($this->admin())->deleteJson(route('attendance.coverageRequirements.destroy', $req->id))->assertOk();
        $this->assertDatabaseMissing('coverage_requirements', ['id' => $req->id]);
    }

    public function test_requires_settings_permission(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.coverageRequirements.index'))->assertForbidden();
    }
}
