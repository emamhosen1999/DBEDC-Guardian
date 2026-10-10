<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Daily Timesheet map draws the corridor behind the punches. It asks the existing locations endpoint for the
 * centreline (`with_alignment=1`); every other caller keeps the body it always had.
 */
class TimesheetMapAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('attendance.view');
    }

    public function test_the_default_response_is_unchanged(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('getUserLocationsForDate', ['date' => '2026-06-03']));

        $response->assertOk()->assertJsonStructure(['success', 'locations', 'attendance_type_configs']);
        $this->assertArrayNotHasKey('alignment', $response->json());
    }

    public function test_the_centreline_is_added_on_request_in_the_map_payload_shape(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('getUserLocationsForDate', ['date' => '2026-06-03', 'with_alignment' => 1]));

        $response->assertOk()->assertJsonStructure(['success', 'locations', 'attendance_type_configs', 'alignment' => ['points', 'length_m', 'source']]);
    }

    public function test_the_endpoint_still_needs_the_attendance_permission(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->getJson(route('getUserLocationsForDate', ['date' => '2026-06-03', 'with_alignment' => 1]))->assertForbidden();
    }
}
