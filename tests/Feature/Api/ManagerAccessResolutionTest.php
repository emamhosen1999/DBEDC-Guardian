<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTeamMembers;
use App\Http\Resources\UserResource;
use App\Models\HRM\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Manager-ness is relationship/permission based and server-authoritative:
 * a Department Manager who actually has reports IS a manager, and a plain
 * employee is not — regardless of any hard-coded role-name whitelist.
 */
class ManagerAccessResolutionTest extends TestCase
{
    use RefreshDatabase;

    private object $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Expose the protected trait methods for direct assertion.
        $this->resolver = new class
        {
            use ResolvesTeamMembers;

            public function isManager(User $user): bool
            {
                return $this->isManagerUser($user);
            }

            public function teamMemberIds(User $user): array
            {
                return $this->resolveTeamMemberIds($user);
            }
        };
    }

    public function test_department_manager_with_reports_is_a_manager_with_a_resolved_team(): void
    {
        Role::findOrCreate('Department Manager', 'web');

        $department = Department::create([
            'name' => 'Engineering',
            'code' => 'ENG',
            'is_active' => true,
        ]);

        $manager = User::factory()->create(['department_id' => $department->id]);
        $manager->assignRole('Department Manager');

        // The manager heads the department explicitly, too.
        $department->update(['manager_id' => $manager->id]);

        $directReport = User::factory()->create(['report_to' => $manager->id]);
        $departmentMember = User::factory()->create(['department_id' => $department->id]);
        $outsider = User::factory()->create();

        // Manager-ness.
        $this->assertTrue($this->resolver->isManager($manager->fresh()));

        // Team scope = reporting sub-tree UNION department members, excluding self.
        $teamIds = $this->resolver->teamMemberIds($manager->fresh());
        $this->assertContains($directReport->id, $teamIds);
        $this->assertContains($departmentMember->id, $teamIds);
        $this->assertNotContains($manager->id, $teamIds);
        $this->assertNotContains($outsider->id, $teamIds);

        // The serializer carries the authoritative flag.
        $payload = (new UserResource($manager->fresh()))->toArray(request());
        $this->assertTrue($payload['is_manager']);
    }

    public function test_manager_via_direct_reports_only_without_any_role(): void
    {
        $manager = User::factory()->create();
        User::factory()->create(['report_to' => $manager->id]);

        $this->assertTrue($this->resolver->isManager($manager->fresh()));
        $this->assertTrue((new UserResource($manager->fresh()))->toArray(request())['is_manager']);
    }

    public function test_plain_employee_is_not_a_manager(): void
    {
        $employee = User::factory()->create();

        $this->assertFalse($this->resolver->isManager($employee->fresh()));
        $this->assertSame([], $this->resolver->teamMemberIds($employee->fresh()));

        $payload = (new UserResource($employee->fresh()))->toArray(request());
        $this->assertFalse($payload['is_manager']);
    }
}
