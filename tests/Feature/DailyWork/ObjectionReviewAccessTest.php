<?php

namespace Tests\Feature\DailyWork;

use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reviewing RFI objections was decided by role names that do not exist ('Super Admin', 'Admin', 'Project Manager',
 * 'Consultant'): only HR Manager could review. Now the global roles and holders of daily-works.objections.review can.
 */
class ObjectionReviewAccessTest extends TestCase
{
    use RefreshDatabase;

    private function holder(string $role, array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_review_follows_the_permission_and_the_global_roles_not_role_names(): void
    {
        $objection = new RfiObjection(['status' => RfiObjection::STATUS_SUBMITTED]);

        $manager = $this->holder('Daily Works Manager', ['daily-works.view', 'daily-works.update', 'daily-works.objections.review']);
        $admin = $this->holder('Administrator', ['daily-works.view', 'daily-works.update']);
        $hr = $this->holder('HR Manager', ['daily-works.view', 'daily-works.update']);
        $contributor = $this->holder('Daily Works Contributor', ['daily-works.view', 'daily-works.update']);

        $this->assertTrue(Gate::forUser($manager)->allows('review', $objection), 'Daily Works Manager reviews by permission');
        $this->assertTrue(Gate::forUser($admin)->allows('review', $objection), 'Administrator is a global role');
        $this->assertTrue(Gate::forUser($hr)->allows('review', $objection), 'HR Manager keeps the access it had');
        $this->assertFalse(Gate::forUser($contributor)->allows('review', $objection), 'a contributor cannot review others');
    }
}
