<?php

namespace Tests\Feature\HR;

use App\Models\FeatureFlag;
use App\Models\HRM\Offboarding;
use App\Models\User;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LifecycleRouteGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['HR Manager' => 20, 'Team Lead' => 40] as $name => $level) {
            Role::create(['name' => $name, 'guard_name' => 'web', 'hierarchy_level' => $level]);
        }
        foreach ([
            'employees.view', 'hr.assets.view', 'hr.assets.manage', 'hr.payroll.view', 'hr.payroll.process',
            'hr.offboarding.view', 'hr.settlement.manage', 'hr.probation.manage',
        ] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    private function userWith(string $role, array $permissions): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->givePermissionTo($permissions);

        return $u;
    }

    private function setFlag(string $key, bool $enabled): void
    {
        FeatureFlag::updateOrCreate(['key' => $key, 'role' => null], ['is_enabled' => $enabled]);
        app(FeatureFlagService::class)->forgetMemo();
    }

    public function test_team_lead_with_employees_view_is_forbidden_from_payroll_generate_and_asset_create(): void
    {
        $this->setFlag('hr_payroll', true);
        $lead = $this->userWith('Team Lead', ['employees.view']);

        $this->actingAs($lead)->postJson('/hr/payroll/generate', [])->assertForbidden();
        $this->actingAs($lead)->postJson('/hr/assets', [])->assertForbidden();
        $this->actingAs($lead)->getJson('/hr/assets')->assertForbidden();
        $this->actingAs($lead)->getJson('/hr/payroll')->assertForbidden();
        $this->actingAs($lead)->postJson('/employees/X1/confirm')->assertForbidden();
    }

    public function test_hr_manager_with_assets_manage_can_create_asset(): void
    {
        $hr = $this->userWith('HR Manager', ['hr.assets.manage']);

        $this->actingAs($hr)->postJson('/hr/assets', [
            'asset_code' => 'AST-001',
            'name' => 'Laptop',
            'category' => 'it_hardware',
        ])->assertCreated();

        $this->assertDatabaseHas('assets', ['asset_code' => 'AST-001']);
    }

    public function test_payroll_routes_404_when_flag_off_and_pass_when_on(): void
    {
        $hr = $this->userWith('HR Manager', ['hr.payroll.view', 'hr.payroll.process']);

        $this->actingAs($hr)->getJson('/hr/payroll')->assertNotFound();
        $this->actingAs($hr)->postJson('/hr/payroll/generate', [])->assertNotFound();
        $this->actingAs($hr)->getJson('/hr/payroll/payslip/1')->assertNotFound();

        $this->setFlag('hr_payroll', true);
        $this->assertNotSame(404, $this->actingAs($hr)->getJson('/hr/payroll')->getStatusCode());
    }

    public function test_settlement_routes_404_when_flag_off_but_certificates_stay_available(): void
    {
        $hr = $this->userWith('HR Manager', ['hr.offboarding.view', 'hr.settlement.manage']);

        $this->actingAs($hr)->getJson('/hr/offboarding/1/settlement/calculate')->assertNotFound();
        $this->actingAs($hr)->postJson('/hr/offboarding/settlement', [])->assertNotFound();

        // Experience / release certificates are not behind the flag.
        $this->assertNotContains('feature:hr_final_settlement', app('router')->getRoutes()->getByName('hr.settlement.certificate')->gatherMiddleware());
    }

    public function test_eligible_employees_returns_200_while_another_offboarding_is_active(): void
    {
        $hr = $this->userWith('HR Manager', ['hr.offboarding.view']);
        $leaving = User::factory()->create();
        $staying = User::factory()->create();

        $this->actingAs($hr); // created_by is stamped from the authenticated user
        Offboarding::create([
            'employee_id' => $leaving->employee_id,
            'initiation_date' => now()->toDateString(),
            'last_working_date' => now()->addDays(30)->toDateString(),
            'reason' => 'resignation',
            'status' => Offboarding::STATUS_PENDING,
        ]);

        $response = $this->actingAs($hr)->getJson(route('hr.offboarding.eligible'))->assertOk();
        $ids = collect($response->json())->pluck('employee_id');

        $this->assertTrue($ids->contains($staying->employee_id));
        $this->assertFalse($ids->contains($leaving->employee_id));
    }
}
