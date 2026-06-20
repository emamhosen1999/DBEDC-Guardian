<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PolicyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_admin_creates_and_activates_a_policy(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');

        $this->actingAs($admin)->postJson(route('attendance.policies.store'), [
            'name' => 'Night strict', 'scope_type' => 'org', 'effective_from' => '2026-06-01',
            'punch_strictness' => 'restrict', 'outside_window_minutes' => 60,
        ])->assertCreated();

        $p = AttendancePolicy::first();
        $this->assertSame('draft', $p->status);

        $this->actingAs($admin)->postJson(route('attendance.policies.activate', $p->id))->assertOk();
        $this->assertSame('active', $p->fresh()->status);
    }

    public function test_employee_cannot_manage_policies(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.policies.index'))->assertForbidden();
    }

    public function test_cannot_update_a_non_draft_policy(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');

        $this->actingAs($admin)->postJson(route('attendance.policies.store'), [
            'name' => 'Night strict', 'scope_type' => 'org', 'effective_from' => '2026-06-01',
            'punch_strictness' => 'restrict', 'outside_window_minutes' => 60,
        ])->assertCreated();

        $p = AttendancePolicy::first();

        $this->actingAs($admin)->postJson(route('attendance.policies.activate', $p->id))->assertOk();
        $this->assertSame('active', $p->fresh()->status);

        $this->actingAs($admin)->putJson(route('attendance.policies.update', $p->id), [
            'name' => 'Renamed', 'scope_type' => 'org', 'effective_from' => '2026-06-01',
            'punch_strictness' => 'warn', 'outside_window_minutes' => 30,
        ])->assertStatus(422);

        $p->refresh();
        $this->assertSame('Night strict', $p->name);
        $this->assertSame('restrict', $p->punch_strictness);
        $this->assertSame(60, $p->outside_window_minutes);
    }
}
