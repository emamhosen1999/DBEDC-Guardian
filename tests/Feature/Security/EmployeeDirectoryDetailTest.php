<?php

namespace Tests\Feature\Security;

use App\Models\HRM\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeDirectoryDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_detail_is_scoped_and_excludes_private_profile_fields(): void
    {
        $department = Department::factory()->create();
        $actor = User::factory()->create(['department_id' => $department->id]);
        $actor->givePermissionTo(Permission::findOrCreate('employees.view', 'web'));
        $peer = User::factory()->create(['department_id' => $department->id]);
        $outsider = User::factory()->create(['department_id' => Department::factory()->create()->id]);

        $this->actingAs($actor)->getJson(route('employees.show', $peer->getKey()))
            ->assertOk()
            ->assertJsonPath('employee.employee_id', $peer->getKey())
            ->assertJsonMissingPath('employee.salary_amount')
            ->assertJsonMissingPath('employee.birthday')
            ->assertJsonMissingPath('employee.address')
            ->assertJsonMissingPath('employee.active_device');

        $this->getJson(route('employees.show', $outsider->getKey()))->assertNotFound();
    }

    public function test_employee_detail_requires_directory_permission(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor)->getJson(route('employees.show', $actor->getKey()))->assertForbidden();
    }
}
