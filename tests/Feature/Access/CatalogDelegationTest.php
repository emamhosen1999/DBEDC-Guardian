<?php

namespace Tests\Feature\Access;

use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Access\DepartmentDefaultRoles;
use App\Services\Access\DepartmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Access\Concerns\BuildsCatalogWorld;
use Tests\TestCase;

/**
 * Who may administer whom once the role catalog is in force (docs/audit/ROLE_CATALOG_2026-10-03.md, section 4.5), on
 * a production mirror: Quality Control with its 22 people, 123 Abul Bashar as head, 169 Fahim as Department Admin,
 * 896 Wang Fu as head of O&M. Every row of the section 4.5 table is a test here, and the functional and default roles
 * (Line Manager, Quality Manager, Daily Works Manager, Quality Contributor ...) must not change a single answer.
 */
class CatalogDelegationTest extends TestCase
{
    use BuildsCatalogWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCatalogWorld('target');
    }

    private function scope(): DepartmentScope
    {
        return app(DepartmentScope::class);
    }

    private function manages(string $actor, string $target): bool
    {
        $this->scope()->forget();

        return $this->scope()->canManage($this->people[$actor]->fresh(), $this->people[$target]->fresh());
    }

    public function test_fahim_manages_19_of_his_21_quality_control_colleagues(): void
    {
        $colleagues = array_values(array_filter(array_map('strval', array_keys($this->people)), fn ($id) => $this->people[$id]->department_id === 11 && $id !== '169'));
        $this->assertCount(21, $colleagues);

        $managed = array_values(array_filter($colleagues, fn ($id) => $this->manages('169', $id)));
        $protected = array_values(array_diff($colleagues, $managed));

        $this->assertCount(19, $managed);
        $this->assertEqualsCanonicalizing(['123', '896'], $protected, '123 is his manager (ancestor guard); 896 heads O&M too (departments {11, 21} are not a subset of {11})');
    }

    public function test_the_new_functional_roles_do_not_shield_anyone_from_a_department_admin(): void
    {
        // Line Manager (120, 126, 127, 1536), Quality Manager and Daily Works Manager (127) and Maintenance Inspector (127)
        // all sit at level 40 or below in power: a Department Admin (25) still outranks them.
        foreach (['120', '126', '127', '1536', '152', '7'] as $id) {
            $this->assertTrue($this->manages('169', $id), "169 manages {$id}");
        }
    }

    public function test_the_ancestor_guard_protects_the_head_and_the_subset_rule_protects_the_dual_head(): void
    {
        $this->assertFalse($this->manages('169', '123'), '123 is 169\'s manager');
        $this->assertFalse($this->manages('169', '896'), '896 administers O&M as well as Quality Control');
        $this->assertFalse($this->manages('123', '896'), '896\'s departments {11, 21} are not a subset of 123\'s {11}');
        $this->assertFalse($this->manages('123', '169'), 'Fahim\'s administrator permissions are not a subset of Bashar\'s');
    }

    public function test_known_gap_a_functional_role_still_outranks_an_administrator_until_phase_b(): void
    {
        // The section 4.5 table: 896 -> 169 is allowed today and after Phase A (the O&M Director's level 15 beats the
        // Department Admin's 25). Phase B raises the functional roles to level 45 and this assertion flips.
        $this->assertTrue($this->manages('896', '169'));
    }

    public function test_the_toll_inspector_incharge_still_administers_his_inspector(): void
    {
        $this->assertTrue($this->manages('1537', '160002'));
        $this->assertFalse($this->manages('1537', '120'), 'nobody outside his department');
    }

    public function test_a_line_manager_approves_for_his_subtree_only_and_edits_nobody(): void
    {
        $lead = $this->people['126'];

        $this->assertEqualsCanonicalizing(['122', '131'], $this->scope()->reportingSubtreeIds($lead), '126 leads 122 and 131');
        foreach (['employees.view', 'attendance.view', 'leaves.view', 'leaves.approve', 'holidays.view'] as $permission) {
            $this->assertTrue($lead->can($permission), "a Line Manager holds {$permission}");
        }
        foreach (['employees.update', 'employees.create', 'attendance.update', 'attendance.correct', 'leaves.update', 'users.update', 'employees.access.manage'] as $permission) {
            $this->assertFalse($lead->can($permission), "a Line Manager must not hold {$permission}");
        }

        $this->actingAs($lead)->putJson(route('users.update', '122'), ['name' => 'Renamed by a line manager'])->assertForbidden();
        $this->assertNotSame('Renamed by a line manager', $this->people['122']->fresh()->name);
    }

    public function test_130_leads_the_whole_contract_department_through_his_headship(): void
    {
        // 130 holds Employee + Line Manager; as the recorded head of Contract he sees all of it (and only it).
        $this->scope()->forget();
        $this->assertSame([12], $this->scope()->managedDepartmentIds($this->people['130']->fresh()));
        $visible = $this->scope()->visibleEmployeeIds($this->people['130']->fresh());
        foreach (['154', '155', '301', '302', '130'] as $id) {
            $this->assertContains($id, $visible);
        }
        $this->assertNotContains('120', $visible);
    }

    public function test_department_default_roles_stay_ordinary_for_the_subset_rule(): void
    {
        $this->assertEqualsCanonicalizing(['Daily Works Contributor', 'Quality Contributor'], app(DepartmentDefaultRoles::class)->allManaged());

        // A Department Admin whose peer holds Employee + Department Admin + the two QC defaults can manage that peer
        // even though he lacks the quality permissions: defaults are not "elevated", only the administrator role is.
        $actor = $this->person('7001', 'Plain Admin', 11, ['Employee', 'Department Admin']);
        $peer = $this->person('7002', 'Peer Admin', 11, ['Employee', 'Department Admin', 'Daily Works Contributor', 'Quality Contributor']);
        $this->scope()->forget();
        $this->assertTrue($this->scope()->withinDelegation($actor, $peer));

        // ...whereas a functional manager role IS elevated: a peer holding Quality Manager stays protected.
        $manager = $this->person('7003', 'Quality Peer', 11, ['Employee', 'Department Admin', 'Quality Manager']);
        $this->scope()->forget();
        $this->assertFalse($this->scope()->withinDelegation($actor, $manager));
    }

    public function test_every_quality_control_member_holds_the_two_default_roles_in_the_plan(): void
    {
        foreach ($this->people as $id => $person) {
            $roles = $person->roles->pluck('name')->all();
            if ($person->department_id === 11) {
                $this->assertContains('Daily Works Contributor', $roles, "{$id}");
                $this->assertContains('Quality Contributor', $roles, "{$id}");
            } else {
                $this->assertNotContains('Quality Contributor', $roles, "{$id} is not in Quality Control");
            }
            $this->assertContains('Employee', $roles, "{$id} keeps the base role");
        }
    }

    public function test_only_a_super_administrator_edits_a_departments_default_roles(): void
    {
        $administrator = $this->person('7010', 'An Administrator', null, ['Employee', 'Administrator']);
        $hr = $this->person('7011', 'An HR Manager', null, ['Employee', 'HR Manager']);
        $superAdmin = $this->person('7012', 'The Super Admin', null, ['Employee', 'Super Administrator']);
        $qc = Department::find(11);

        foreach ([$administrator, $hr, $this->people['169']] as $actor) {
            $this->actingAs($actor)->putJson(route('departments.update', $qc->id), ['name' => $qc->name, 'default_roles' => ['Daily Works Contributor']])
                ->assertStatus(403);
            $this->actingAs($actor)->getJson(route('departments.default-role-options'))->assertForbidden();
        }
        $this->assertSame(['Daily Works Contributor', 'Quality Contributor'], $qc->fresh()->default_roles);

        $this->actingAs($superAdmin)->putJson(route('departments.update', $qc->id), ['name' => $qc->name, 'default_roles' => ['Daily Works Contributor']])->assertOk();
        $this->assertSame(['Daily Works Contributor'], $qc->fresh()->default_roles);
    }

    private function person(string $id, string $name, ?int $department, array $roles): User
    {
        $user = User::factory()->create(['employee_id' => $id, 'name' => $name, 'department_id' => $department]);
        $user->syncRoles($roles);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
