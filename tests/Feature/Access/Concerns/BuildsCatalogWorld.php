<?php

namespace Tests\Feature\Access\Concerns;

use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Access\CatalogPlan;
use App\Services\Access\RoleCatalog;
use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Database\Seeders\OmRbacSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * A production mirror: the five departments (heads included), the 35 people of the owner-approved assignment plan
 * with their reporting lines, and the catalog roles the seeders create. `buildCatalogWorld('target')` gives every person
 * the roles the plan ends with; `'current'` gives the roles production had when the plan was drawn up (a renamed or
 * retired role name is mapped to its catalog name, and nobody holds a retired role).
 */
trait BuildsCatalogWorld
{
    /** employee id => [department id, reports to]: section 5 of docs/audit/ROLE_CATALOG_2026-10-03.md */
    protected const REPORTING = [
        '120' => [11, '123'], '122' => [11, '126'], '123' => [11, '123'], '126' => [11, '123'], '127' => [11, '123'], '131' => [11, '126'],
        '142' => [11, '127'], '143' => [11, '120'], '145' => [11, '1536'], '149' => [11, '123'], '152' => [11, '123'], '159' => [11, '120'],
        '169' => [11, '123'], '272' => [11, '1536'], '304' => [11, '123'], '307' => [11, '123'], '356' => [11, '127'], '538' => [11, '1536'],
        '1536' => [11, '123'], '7' => [11, '127'], '896' => [11, '123'], '97' => [11, '120'],
        '130' => [12, '123'], '154' => [12, '130'], '155' => [12, '130'], '301' => [12, '130'], '302' => [12, '130'],
        '151' => [22, '896'], '306' => [22, '151'], '308' => [22, '151'], '309' => [22, '151'], '397' => [22, '151'], '310' => [22, '151'],
        '1537' => [30, null], '160002' => [30, '1537'],
    ];

    /** department id => [name, head] */
    protected const DEPARTMENTS = [11 => ['Quality Control', '123'], 12 => ['Contract', '130'], 21 => ['Operation and Maintenance', '896'], 22 => ['Traffic Monitoring Center', '151'], 30 => ['Inspection', null]];

    protected CatalogPlan $plan;

    /** @var array<string, User> */
    protected array $people = [];

    protected function buildCatalogWorld(string $state = 'target'): void
    {
        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(ComprehensiveRolePermissionSeeder::class);
        $this->seed(OmRbacSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->plan = CatalogPlan::load();

        foreach (self::DEPARTMENTS as $id => [$name, $head]) {
            $department = new Department(['name' => $name, 'code' => 'D'.$id, 'default_roles' => $id === 11 ? ['Daily Works Contributor', 'Quality Contributor'] : null]);
            $department->forceFill(['id' => $id, 'is_active' => true])->save();
        }

        foreach ($this->plan->users() as $id => $entry) {
            [$department, $reportTo] = self::REPORTING[$id];
            $this->people[$id] = User::factory()->create([
                'employee_id' => $id, 'name' => $entry['name'], 'department_id' => $department, 'report_to' => $reportTo,
            ]);
            $this->people[$id]->syncRoles($state === 'target' ? $entry['target']['roles'] : $this->currentRoles($entry['current']['roles']));
            foreach ($state === 'target' ? $entry['target']['direct_permissions'] : $entry['current']['direct_permissions'] as $permission) {
                $this->people[$id]->givePermissionTo($permission);
            }
        }

        foreach (self::DEPARTMENTS as $id => [$name, $head]) {
            Department::whereKey($id)->update(['manager_id' => $head]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array<int, string> $roles */
    private function currentRoles(array $roles): array
    {
        return array_values(array_unique(array_map(fn ($role) => RoleCatalog::RENAMES[$role] ?? $role, $roles)));
    }
}
