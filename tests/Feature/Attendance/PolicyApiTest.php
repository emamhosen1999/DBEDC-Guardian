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

    public function test_rule_overrides_round_trips_on_create_and_read(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');

        $ruleOverrides = [
            'breaks' => [
                'unpaid_meal_minutes'    => 30,
                'meal_threshold_minutes' => 360,
            ],
            'overtime' => [
                'daily_threshold_minutes'      => 480,
                'daily_multiplier'             => 1.5,
                'double_time_threshold_minutes'=> 720,
                'double_time_multiplier'       => 2.0,
                'require_preauthorization'     => true,
            ],
        ];

        $response = $this->actingAs($admin)->postJson(route('attendance.policies.store'), [
            'name'                   => 'OT Policy',
            'scope_type'             => 'org',
            'effective_from'         => '2026-07-01',
            'punch_strictness'       => 'warn',
            'outside_window_minutes' => 120,
            'rule_overrides'         => $ruleOverrides,
        ])->assertCreated();

        // Assert create response returns rule_overrides
        $response->assertJsonPath('rule_overrides.breaks.unpaid_meal_minutes', 30);
        $response->assertJsonPath('rule_overrides.breaks.meal_threshold_minutes', 360);
        $response->assertJsonPath('rule_overrides.overtime.daily_threshold_minutes', 480);
        $response->assertJsonPath('rule_overrides.overtime.daily_multiplier', 1.5);
        $response->assertJsonPath('rule_overrides.overtime.require_preauthorization', true);

        // Assert index response also returns rule_overrides
        $p = AttendancePolicy::first();
        $this->actingAs($admin)->getJson(route('attendance.policies.index'))
            ->assertOk()
            ->assertJsonPath('policies.0.rule_overrides.breaks.unpaid_meal_minutes', 30)
            ->assertJsonPath('policies.0.rule_overrides.overtime.require_preauthorization', true);

        // Assert update also round-trips rule_overrides
        $updatedOverrides = [
            'breaks' => [
                'unpaid_meal_minutes'    => 45,
                'meal_threshold_minutes' => 300,
            ],
        ];
        $this->actingAs($admin)->putJson(route('attendance.policies.update', $p->id), [
            'name'                   => 'OT Policy Updated',
            'scope_type'             => 'org',
            'effective_from'         => '2026-07-01',
            'punch_strictness'       => 'warn',
            'outside_window_minutes' => 120,
            'rule_overrides'         => $updatedOverrides,
        ])
            ->assertOk()
            ->assertJsonPath('rule_overrides.breaks.unpaid_meal_minutes', 45)
            // Updating with only `breaks` must DROP the previously-stored `overtime` axis.
            ->assertJsonPath('rule_overrides.overtime', null);

        $fresh = $p->fresh()->rule_overrides;
        $this->assertSame(45, $fresh['breaks']['unpaid_meal_minutes']);
        $this->assertArrayNotHasKey('overtime', $fresh);
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
