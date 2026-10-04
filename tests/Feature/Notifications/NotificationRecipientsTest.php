<?php

namespace Tests\Feature\Notifications;

use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Notification\NotificationRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** F-11: recipients by permission + DepartmentScope, never by role name; actor and inactive users excluded. */
class NotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'hr.offboarding.view';

    private Department $dept;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::PERMISSION, 'web');

        $this->dept = new Department(['name' => 'Recipients Lab', 'code' => 'RCP-LAB']);
        $this->dept->forceFill(['is_active' => true])->save();
        $this->employee = User::factory()->create(['department_id' => $this->dept->id]);
    }

    private function holder(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->givePermissionTo(self::PERMISSION);

        return $user;
    }

    private function ids($users): array
    {
        return collect($users)->pluck('employee_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    public function test_only_permission_holders_in_scope_of_the_subject_are_recipients(): void
    {
        $head = $this->holder(['department_id' => $this->dept->id]);
        $this->dept->forceFill(['manager_id' => $head->employee_id])->save();
        $stranger = $this->holder();                       // holds it, but manages nobody here
        $plain = User::factory()->create();                // no permission
        $plain2 = User::factory()->create(['department_id' => $this->dept->id]);
        $this->dept->forceFill(['manager_id' => $head->employee_id])->save();

        $got = app(NotificationRecipients::class)->forPermission(self::PERMISSION, $this->employee);

        $this->assertSame([(string) $head->employee_id], $this->ids($got));
        $this->assertNotContains((string) $stranger->employee_id, $this->ids($got));
        $this->assertNotContains((string) $plain->employee_id, $this->ids($got));
    }

    public function test_role_names_are_irrelevant_only_the_permission_counts(): void
    {
        // A role literally named like the old admin roles, but WITHOUT the permission: not a recipient.
        Role::findOrCreate('HR Manager', 'web');
        $namedOnly = User::factory()->create();
        $namedOnly->assignRole('HR Manager');

        // A made-up role that carries the permission: a recipient (global scope is not needed without a subject).
        $role = Role::findOrCreate('Some Future Role', 'web');
        $role->givePermissionTo(self::PERMISSION);
        $viaRole = User::factory()->create();
        $viaRole->assignRole($role);

        $got = app(NotificationRecipients::class)->forPermission(self::PERMISSION);

        $this->assertSame([(string) $viaRole->employee_id], $this->ids($got));
    }

    public function test_actor_inactive_and_soft_deleted_users_are_excluded(): void
    {
        $actor = $this->holder();
        $inactive = $this->holder(['is_active' => false]);
        $deleted = $this->holder();
        $deleted->delete();
        $ok = $this->holder();

        $got = app(NotificationRecipients::class)->forPermission(self::PERMISSION, null, $actor);

        $this->assertSame([(string) $ok->employee_id], $this->ids($got));
    }

    public function test_explicit_employees_get_the_same_filtering(): void
    {
        $actor = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $ok = User::factory()->create();

        $got = app(NotificationRecipients::class)->forEmployees([$actor->employee_id, $inactive->employee_id, $ok->employee_id, null], null, $actor);

        $this->assertSame([(string) $ok->employee_id], $this->ids($got));
    }

    public function test_an_unregistered_permission_yields_nobody_instead_of_throwing(): void
    {
        $this->assertTrue(app(NotificationRecipients::class)->forPermission('does.not.exist')->isEmpty());
    }
}
