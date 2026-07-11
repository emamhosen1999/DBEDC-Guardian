<?php

namespace Tests\Feature\Directory;

use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Directory\ScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    public function test_hr_manager_sees_everyone(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole($this->role('HR Manager'));
        User::factory()->count(3)->create();

        $resolver = new ScopeResolver();
        $count = $resolver->applyBaseScope(User::query(), $hr)->count();

        $this->assertTrue($resolver->isGlobal($hr));
        $this->assertSame(User::count(), $count);
    }

    public function test_department_manager_sees_only_own_department(): void
    {
        // users.department_id is FK-constrained to departments.id, so real rows
        // are created here instead of the brief's bare literals 10/20.
        $deptA = Department::create(['name' => 'Department A']);
        $deptB = Department::create(['name' => 'Department B']);

        $mgr = User::factory()->create(['department_id' => $deptA->id]);
        $mgr->assignRole($this->role('Department Manager'));
        User::factory()->count(2)->create(['department_id' => $deptA->id]);
        User::factory()->count(4)->create(['department_id' => $deptB->id]);

        $resolver = new ScopeResolver();
        $ids = $resolver->applyBaseScope(User::query(), $mgr)->pluck('id');

        $this->assertFalse($resolver->isGlobal($mgr));
        $this->assertTrue($ids->contains($mgr->id));
        $this->assertCount(3, $ids); // mgr + 2 same-dept
    }

    public function test_plain_employee_sees_only_self(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole($this->role('Employee'));
        User::factory()->count(5)->create();

        $resolver = new ScopeResolver();
        $ids = $resolver->applyBaseScope(User::query(), $emp)->pluck('id');

        $this->assertSame([$emp->id], $ids->all());
    }
}
