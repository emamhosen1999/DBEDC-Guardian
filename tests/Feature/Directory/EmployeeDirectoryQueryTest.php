<?php

namespace Tests\Feature\Directory;

use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Directory\EmployeeDirectoryQuery;
use App\Services\Directory\ScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeDirectoryQueryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'web']));
        return $u;
    }

    private function svc(): EmployeeDirectoryQuery
    {
        return new EmployeeDirectoryQuery(new ScopeResolver());
    }

    public function test_search_matches_name_and_employee_id_and_ranks_exact_id_first(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Rahim Uddin', 'employee_id' => 'E-100']);
        $exact = User::factory()->create(['name' => 'Karim', 'employee_id' => 'RAHIM']);

        $rows = $this->svc()->search($admin, ['q' => 'RAHIM', 'limit' => 10]);

        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertSame($exact->id, $rows->first()['id']); // exact employee_id ranks first
        $this->assertArrayHasKey('department_name', $rows->first());
    }

    public function test_search_honors_exclude_ids(): void
    {
        $admin = $this->admin();
        $drop = User::factory()->create(['name' => 'Zed One']);
        User::factory()->create(['name' => 'Zed Two']);

        $rows = $this->svc()->search($admin, ['q' => 'Zed', 'excludeIds' => [$drop->id]]);

        $this->assertFalse($rows->pluck('id')->contains($drop->id));
    }

    public function test_search_respects_permission_scope_ceiling(): void
    {
        // users.department_id is FK-constrained to departments.id, so real rows
        // are created here instead of the brief's bare literals 5/9.
        $deptA = Department::create(['name' => 'Department A']);
        $deptB = Department::create(['name' => 'Department B']);

        $mgr = User::factory()->create(['department_id' => $deptA->id]);
        $mgr->assignRole(Role::firstOrCreate(['name' => 'Department Manager', 'guard_name' => 'web']));
        User::factory()->create(['name' => 'InDept', 'department_id' => $deptA->id]);
        User::factory()->create(['name' => 'OutDept', 'department_id' => $deptB->id]);

        // Manager passes scope=all but may not widen beyond own department.
        $rows = $this->svc()->search($mgr, ['q' => 'Dept', 'scope' => 'all']);

        $this->assertTrue($rows->pluck('name')->contains('InDept'));
        $this->assertFalse($rows->pluck('name')->contains('OutDept'));
    }

    public function test_base_query_sorts_by_requested_column(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Bravo']);
        User::factory()->create(['name' => 'Alpha']);

        $names = $this->svc()
            ->baseQuery($admin, ['sort' => 'name', 'direction' => 'asc'])
            ->pluck('name');

        $this->assertSame('Alpha', $names->first());
    }
}
