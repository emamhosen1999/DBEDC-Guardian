<?php

namespace Tests\Feature\Access;

use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\SelfAdministrationLog;
use App\Notifications\SelfAdministrationNotification;
use App\Services\Access\SelfAdministration;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\Feature\Access\Concerns\TogglesSelfAdministration;
use Tests\TestCase;

/**
 * Owner-approved governed exception (access.self-administration): a Department Admin may act on
 * himself like on his staff; every such action is logged and announced to the global admins, while
 * role changes, identity changes and leaving his own departments stay closed.
 */
class SelfAdministrationTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;
    use TogglesSelfAdministration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->buildWorld();
        Notification::fake();
    }

    public function test_he_marks_himself_present_and_it_is_logged_and_announced(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('attendance.mark-as-present'), ['user_id' => $this->admin->employee_id, 'date' => self::DAY])
            ->assertOk();

        $this->assertTrue(Attendance::where('user_id', $this->admin->employee_id)->whereDate('date', self::DAY)->exists());
        $this->assertTrue(SelfAdministrationLog::where('actor_id', $this->admin->employee_id)->where('action', 'like', 'attendance%')->exists());
        Notification::assertSentTo($this->hr, SelfAdministrationNotification::class);
    }

    public function test_without_the_permission_he_cannot_mark_himself_present(): void
    {
        $this->withoutSelfAdministration();

        $this->actingAs($this->admin)
            ->postJson(route('attendance.mark-as-present'), ['user_id' => $this->admin->employee_id, 'date' => self::DAY])
            ->assertForbidden();

        $this->assertFalse(Attendance::where('user_id', $this->admin->employee_id)->whereDate('date', self::DAY)->exists());
        $this->assertSame(0, SelfAdministrationLog::count());
    }

    public function test_he_edits_his_own_salary_with_before_and_after_logged(): void
    {
        $this->admin->forceFill(['salary_amount' => 5000])->save();

        $this->actingAs($this->admin)
            ->putJson(route('users.update', $this->admin->employee_id), ['name' => $this->admin->name, 'salary_amount' => 6500])
            ->assertOk();

        $this->assertEquals(6500, (float) $this->admin->fresh()->salary_amount);
        $log = SelfAdministrationLog::where('actor_id', $this->admin->employee_id)->where('action', 'employee.update')->latest('id')->first();
        $this->assertNotNull($log, 'the self-edit is logged');
        $this->assertArrayHasKey('salary_amount', $log->changes);
        Notification::assertSentTo($this->hr, SelfAdministrationNotification::class);
    }

    public function test_roles_identity_departments_and_his_own_account_stay_closed(): void
    {
        $roles = $this->admin->getRoleNames()->sort()->values()->all();

        // Roles in his payload are ignored, never applied.
        $this->actingAs($this->admin)
            ->putJson(route('users.update', $this->admin->employee_id), ['name' => $this->admin->name, 'roles' => ['Super Administrator']])
            ->assertOk();
        $this->assertSame($roles, $this->admin->fresh()->getRoleNames()->sort()->values()->all());

        $this->actingAs($this->admin)
            ->putJson(route('users.update', $this->admin->employee_id), ['name' => $this->admin->name, 'employee_id' => 'HIJACK-9'])
            ->assertForbidden();
        $this->actingAs($this->admin)
            ->putJson(route('users.update-department', $this->admin->employee_id), ['department' => $this->d2->id])
            ->assertForbidden();
        $this->actingAs($this->admin)->deleteJson(route('users.destroy', $this->admin->employee_id))->assertForbidden();

        $this->assertNull($this->admin->fresh()->deleted_at);
        $this->assertSame($this->d1->id, (int) $this->admin->fresh()->department_id);
    }

    public function test_he_approves_his_own_leave_and_it_is_logged(): void
    {
        $leave = Leave::create([
            'user_id' => $this->admin->employee_id, 'leave_type' => LeaveSetting::first()->id,
            'from_date' => self::DAY, 'to_date' => self::DAY, 'no_of_days' => 1, 'status' => 'Pending', 'reason' => 'mine',
        ]);

        $this->actingAs($this->admin)->postJson(route('leaves.approve', $leave->id))->assertOk();

        $this->assertSame('approved', strtolower((string) $leave->fresh()->status));
        $log = SelfAdministrationLog::where('actor_id', $this->admin->employee_id)->where('action', 'like', 'leave%')->first();
        $this->assertNotNull($log, 'the self-approval is logged');
        $this->assertSame('pending', strtolower((string) $log->changes['status'][0]), 'the log keeps the status before the decision');
    }

    public function test_the_mobile_mark_present_endpoint_only_reaches_employees_he_manages(): void
    {
        Sanctum::actingAs($this->admin);
        $day = '2026-06-17'; // the shared world already seeds canary attendance on self::DAY

        $this->postJson('/api/v1/attendance/mark-present', ['user_id' => $this->c1->employee_id, 'date' => $day])->assertForbidden();
        $this->assertFalse(Attendance::where('user_id', $this->c1->employee_id)->whereDate('date', $day)->exists(), 'another department is untouchable');

        $this->postJson('/api/v1/attendance/mark-present', ['user_id' => $this->e1->employee_id, 'date' => $day])->assertOk();
        $this->assertTrue(Attendance::where('user_id', $this->e1->employee_id)->whereDate('date', $day)->exists());
    }

    public function test_the_exception_is_per_person_and_never_comes_with_the_role(): void
    {
        // Another Department Admin without a personal grant stays under the plain rule.
        $this->actingAs($this->peer)
            ->postJson(route('attendance.mark-as-present'), ['user_id' => $this->peer->employee_id, 'date' => self::DAY])
            ->assertForbidden();

        $this->assertNotContains(SelfAdministration::PERMISSION, Role::findByName('Department Admin')->permissions->pluck('name')->all());
    }

    public function test_the_console_grant_needs_a_reason_and_every_change_is_logged_and_announced(): void
    {
        $this->artisan('access:self-administration', ['action' => 'grant', 'employee' => $this->peer->employee_id])
            ->assertExitCode(Command::INVALID);
        $this->assertFalse($this->peer->fresh()->hasDirectPermission(SelfAdministration::PERMISSION));

        $this->artisan('access:self-administration', ['action' => 'grant', 'employee' => $this->peer->employee_id, '--reason' => 'sole administrator of D1'])
            ->assertExitCode(Command::SUCCESS);
        $this->assertTrue($this->peer->fresh()->hasDirectPermission(SelfAdministration::PERMISSION));
        $granted = SelfAdministrationLog::where('actor_id', $this->peer->employee_id)->where('action', 'exception.granted')->first();
        $this->assertNotNull($granted);
        $this->assertSame('sole administrator of D1', $granted->changes['reason']);
        Notification::assertSentTo($this->hr, SelfAdministrationNotification::class);

        $this->artisan('access:self-administration', ['action' => 'revoke', 'employee' => $this->peer->employee_id])
            ->assertExitCode(Command::SUCCESS);
        $this->assertFalse($this->peer->fresh()->hasDirectPermission(SelfAdministration::PERMISSION));
        $this->assertTrue(SelfAdministrationLog::where('actor_id', $this->peer->employee_id)->where('action', 'exception.revoked')->exists());
    }
}
