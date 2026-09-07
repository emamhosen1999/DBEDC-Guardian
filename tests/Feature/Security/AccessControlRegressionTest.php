<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccessControlRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'profile.own.view',
            'profile.own.update',
            'users.view',
            'users.update',
            'holidays.view',
            'quality.ncr.view',
            'attendance.view',
            'om.maintenance.view',
            'om.maintenance.manage',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_employee_can_view_own_profile_with_string_employee_id(): void
    {
        $employee = User::factory()->create(['employee_id' => 'EMP-02001']);
        $employee->givePermissionTo('profile.own.view');

        $this->actingAs($employee)
            ->get("/profile/{$employee->employee_id}")
            ->assertOk();
    }

    public function test_own_profile_permission_does_not_allow_viewing_another_employee(): void
    {
        $actor = User::factory()->create(['employee_id' => 'EMP-02002']);
        $target = User::factory()->create(['employee_id' => 'EMP-02003']);
        $actor->givePermissionTo('profile.own.view');

        $this->actingAs($actor)
            ->get("/profile/{$target->employee_id}")
            ->assertForbidden();
    }

    public function test_user_directory_permission_allows_viewing_another_employee_profile(): void
    {
        $actor = User::factory()->create(['employee_id' => 'EMP-02004']);
        $target = User::factory()->create(['employee_id' => 'EMP-02005']);
        $actor->givePermissionTo('users.view');

        $this->actingAs($actor)
            ->get("/profile/{$target->employee_id}")
            ->assertOk();
    }

    public function test_self_service_permission_cannot_update_another_employee_profile(): void
    {
        $actor = User::factory()->create(['employee_id' => 'EMP-02006']);
        $target = User::factory()->create(['employee_id' => 'EMP-02007']);
        $actor->givePermissionTo('profile.own.update');

        $this->actingAs($actor)
            ->postJson('/profile/update', [
                'id' => $target->employee_id,
                'ruleSet' => 'personal',
                'nid' => 'NID-10001',
                'passport_no' => null,
                'passport_exp_date' => null,
                'nationality' => 'Bangladeshi',
                'religion' => 'Islam',
                'marital_status' => 'Single',
                'employment_of_spouse' => null,
                'number_of_children' => 0,
            ])
            ->assertForbidden();

        $this->assertNull($target->fresh()->nid);
    }

    public function test_self_service_permission_cannot_update_salary(): void
    {
        $employee = User::factory()->create(['employee_id' => 'EMP-02008']);
        $employee->givePermissionTo('profile.own.update');

        $this->actingAs($employee)
            ->postJson('/profile/update', [
                'id' => $employee->employee_id,
                'ruleSet' => 'salary',
                'salary_basis' => 'monthly',
                'salary_amount' => 50000,
                'payment_type' => 'bank',
            ])
            ->assertForbidden();
    }

    public function test_profile_editor_cannot_add_education_to_another_employee(): void
    {
        $actor = User::factory()->create(['employee_id' => 'EMP-02009']);
        $target = User::factory()->create(['employee_id' => 'EMP-02010']);
        $actor->givePermissionTo('profile.own.update');

        $this->actingAs($actor)
            ->postJson('/education/update', [
                'educations' => [[
                    'institution' => 'Example University',
                    'subject' => 'Engineering',
                    'degree' => 'BSc',
                    'starting_date' => '2015-01-01',
                    'complete_date' => '2019-01-01',
                    'grade' => 'A',
                    'user_id' => $target->employee_id,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_view_permissions_cannot_mutate_guarded_modules(): void
    {
        $viewer = User::factory()->create(['employee_id' => 'EMP-02011']);
        $viewer->givePermissionTo([
            'holidays.view',
            'quality.ncr.view',
            'attendance.view',
            'om.maintenance.view',
        ]);

        $this->actingAs($viewer)->postJson('/holidays-add', [])->assertForbidden();
        $this->actingAs($viewer)->postJson('/quality/ncr', [])->assertForbidden();
        $this->actingAs($viewer)->postJson('/attendance/shifts', [])->assertForbidden();
        $this->actingAs($viewer)->postJson('/om/defects', [])->assertForbidden();
    }
}
