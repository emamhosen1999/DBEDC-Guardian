<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CoverageEndpointTest extends TestCase
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

    public function test_coverage_endpoint_returns_payload(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 2, 'is_active' => true]);
        $u = User::factory()->create(['work_location_id' => $loc->id]);
        RosterDay::create(['user_id' => $u->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);

        $res = $this->actingAs($this->admin())->getJson(route('attendance.coverage.index', [
            'from' => '2026-07-06', 'to' => '2026-07-06',
        ]))->assertOk();

        $this->assertSame(2, $res->json("coverage.2026-07-06.{$loc->id}.{$shift->id}.total.required"));
        $this->assertSame(1, $res->json("coverage.2026-07-06.{$loc->id}.{$shift->id}.total.assigned"));
        $this->assertSame('understaffed', $res->json("coverage.2026-07-06.{$loc->id}.{$shift->id}.total.status"));
    }

    public function test_work_locations_endpoint_lists_active(): void
    {
        WorkLocation::create(['name' => 'Active Site', 'code' => 'AS', 'is_active' => true]);
        WorkLocation::create(['name' => 'Dead Site', 'code' => 'DS', 'is_active' => false]);

        $res = $this->actingAs($this->admin())->getJson(route('attendance.workLocations.index'))->assertOk();
        $names = collect($res->json('work_locations'))->pluck('name');

        $this->assertTrue($names->contains('Active Site'));
        $this->assertFalse($names->contains('Dead Site'));
    }

    public function test_coverage_requires_settings_permission(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.coverage.index', [
            'from' => '2026-07-06', 'to' => '2026-07-06',
        ]))->assertForbidden();
    }
}
