<?php

namespace Tests\Feature\Access;

use App\Models\HRM\AttendanceType;
use App\Models\HRM\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * departments.default_roles: every employee holds Employee + the functional roles their department
 * lists (Quality Control -> Daily Works Contributor). Applied on create (HR form, API fallback,
 * department admin) and on transfer (old department's defaults leave, new one's arrive).
 */
class DepartmentDefaultRolesTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    private const DWC = 'Daily Works Contributor';

    private Department $qc;

    private AttendanceType $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();

        $this->qc = Department::query()->where('name', 'Quality Control')->first()
            ?? Department::factory()->create(['name' => 'Quality Control']);
        $this->qc->forceFill(['default_roles' => [self::DWC]])->save();
        $this->method = AttendanceType::factory()->create(['is_active' => true]);
    }

    private function payload(string $id, ?Department $department, array $extra = []): array
    {
        return array_merge([
            'name' => 'New '.$id, 'user_name' => 'new'.$id, 'email' => "new{$id}@example.com", 'employee_id' => $id,
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'department_id' => $department?->id, 'attendance_type_ids' => [$this->method->id],
        ], $extra);
    }

    private function rolesOf(string $employeeId): array
    {
        return User::find($employeeId)->roles->pluck('name')->sort()->values()->all();
    }

    public function test_hr_created_qc_employee_with_no_roles_gets_employee_plus_dwc(): void
    {
        $this->actingAs($this->hr)->postJson(route('users.store'), $this->payload('7001', $this->qc))->assertCreated();

        $this->assertSame([self::DWC, 'Employee'], $this->rolesOf('7001'));
    }

    public function test_hr_created_employee_of_another_department_gets_no_dwc(): void
    {
        $this->actingAs($this->hr)->postJson(route('users.store'), $this->payload('7002', $this->d1))->assertCreated();

        $this->assertSame(['Employee'], $this->rolesOf('7002'));
    }

    public function test_only_a_role_manager_can_choose_roles_at_creation(): void
    {
        // Owner decision O-15: HR no longer administers access, so an HR hire is Employee + the department's defaults
        // whatever the form sent. A Super Administrator's choice is honoured as sent.
        $this->actingAs($this->hr)->postJson(route('users.store'), $this->payload('7003', $this->qc, ['roles' => ['Employee']]))->assertCreated();
        $this->assertSame([self::DWC, 'Employee'], $this->rolesOf('7003'));

        $superAdmin = $this->person('Super Admin', null, ['Super Administrator', 'Employee']);
        $this->actingAs($superAdmin)->postJson(route('users.store'), $this->payload('7006', $this->qc, ['roles' => ['Employee']]))->assertCreated();
        $this->assertSame(['Employee'], $this->rolesOf('7006'));
    }

    public function test_department_admin_hire_gets_employee_plus_his_departments_defaults_server_side(): void
    {
        $this->d1->forceFill(['default_roles' => null])->save();
        // Whatever roles the client sends, a department admin's hire is Employee + defaults only.
        $this->actingAs($this->admin)->postJson(route('users.store'), $this->payload('7004', $this->d1, ['roles' => ['Administrator', self::DWC]]))->assertCreated();
        $this->assertSame(['Employee'], $this->rolesOf('7004'));

        $qcAdmin = $this->person('Qc Admin', $this->qc, ['Department Admin', 'Employee']);
        $this->actingAs($qcAdmin)->postJson(route('users.store'), $this->payload('7005', $this->qc, ['roles' => ['Administrator']]))->assertCreated();
        $this->assertSame([self::DWC, 'Employee'], $this->rolesOf('7005'));
    }

    public function test_transfer_into_qc_adds_dwc_and_out_removes_it_but_keeps_other_roles(): void
    {
        $user = $this->person('Mover', $this->d1, ['Employee', 'Line Manager']);

        $this->actingAs($this->hr)->putJson(route('users.update-department', $user->employee_id), ['department' => $this->qc->id])->assertOk();
        $this->assertSame([self::DWC, 'Employee', 'Line Manager'], $this->rolesOf($user->employee_id));

        $this->actingAs($this->hr)->putJson(route('users.update-department', $user->employee_id), ['department' => $this->d1->id])->assertOk();
        $this->assertSame(['Employee', 'Line Manager'], $this->rolesOf($user->employee_id));
    }

    public function test_transfer_never_removes_a_role_no_department_manages_or_privileged_ones(): void
    {
        $user = $this->person('Mover Two', $this->qc, ['Employee', 'HR Manager', self::DWC]);
        // Someone lists a privileged role as a default by mistake: it must still never be stripped.
        $this->d1->forceFill(['default_roles' => ['HR Manager']])->save();
        $this->qc->forceFill(['default_roles' => [self::DWC, 'HR Manager']])->save();

        $this->actingAs($this->hr)->putJson(route('users.update-department', $user->employee_id), ['department' => $this->d1->id])->assertOk();

        $this->assertContains('HR Manager', $this->rolesOf($user->employee_id));
        $this->assertContains('Employee', $this->rolesOf($user->employee_id));
        $this->assertNotContains(self::DWC, $this->rolesOf($user->employee_id));
    }

    public function test_only_a_global_administrator_edits_a_departments_default_roles(): void
    {
        $super = $this->person('Boss', null, ['Super Administrator']);

        $this->actingAs($super)->putJson(route('departments.update', $this->d1->id), ['name' => $this->d1->name, 'default_roles' => [self::DWC]])->assertOk();
        $this->assertSame([self::DWC], $this->d1->fresh()->default_roles);

        // A privileged role can never be a department default.
        $this->actingAs($super)->putJson(route('departments.update', $this->d1->id), ['name' => $this->d1->name, 'default_roles' => ['Administrator']])->assertUnprocessable();

        // HR Manager may edit the department but not its default roles; echoing the current value is a no-op.
        $this->actingAs($this->hr)->putJson(route('departments.update', $this->d1->id), ['name' => $this->d1->name, 'default_roles' => []])->assertForbidden();
        $this->actingAs($this->hr)->putJson(route('departments.update', $this->d1->id), ['name' => 'Inspection Renamed', 'default_roles' => [self::DWC]])->assertOk();
        $this->assertSame([self::DWC], $this->d1->fresh()->default_roles);
    }

    public function test_only_base_roles_means_employee_plus_department_default_roles(): void
    {
        $plain = $this->person('Plain', $this->d1, ['Employee']);
        $qcStaff = $this->person('Qc Staff', $this->qc, ['Employee', self::DWC]);
        $admin = $this->person('Adm', $this->d1, ['Employee', 'Department Admin']);

        $this->assertTrue($plain->hasOnlyBaseRoles());
        $this->assertTrue($qcStaff->hasOnlyBaseRoles());
        $this->assertFalse($admin->hasOnlyBaseRoles());
        $this->assertSame(['Employee'], User::BASE_ROLES);
    }

    public function test_a_department_admin_cannot_change_roles_his_edits_preserve_them(): void
    {
        $target = $this->person('Keeps Roles', $this->d1, ['Employee', 'Line Manager']);
        $before = $this->rolesOf($target->employee_id);

        // Profile edit with a hostile roles payload: ignored, roles untouched.
        $this->actingAs($this->admin)->putJson(route('users.update', $target->employee_id), [
            'name' => 'Renamed Person', 'roles' => ['Administrator'],
        ])->assertOk();
        $this->assertSame($before, $this->rolesOf($target->employee_id));
        $this->assertSame('Renamed Person', $target->fresh()->name);

        // The dedicated role endpoints are closed to him, for others and for himself.
        $this->actingAs($this->admin)->postJson(route('users.updateRole', $target->employee_id), ['roles' => ['Department Admin']])->assertForbidden();
        $ownBefore = $this->rolesOf($this->admin->employee_id);
        $this->actingAs($this->admin)->postJson(route('users.updateRole', $this->admin->employee_id), ['roles' => ['Administrator']])->assertForbidden();
        $this->assertSame($before, $this->rolesOf($target->employee_id));
        $this->assertSame($ownBefore, $this->rolesOf($this->admin->employee_id));
    }
}
