<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceType;
use App\Models\HRM\Onboarding;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * Nobody is created or onboarded without a way to check in: an explicit attendance method OR the
 * work location's default. A check-in with none resolvable answers an actionable message.
 */
class AttendanceMethodRequiredTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    private AttendanceType $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->method = AttendanceType::factory()->create(['is_active' => true]);
    }

    private function payload(string $id, array $extra = []): array
    {
        return array_merge([
            'name' => 'New '.$id, 'user_name' => 'new'.$id, 'email' => "new{$id}@example.com", 'employee_id' => $id,
            'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'department_id' => $this->d1->id,
        ], $extra);
    }

    private function location(?AttendanceType $default): WorkLocation
    {
        return WorkLocation::create(['name' => 'Plaza '.uniqid(), 'is_active' => true, 'attendance_type_id' => $default?->id]);
    }

    public function test_create_without_any_method_is_422_with_a_clear_message(): void
    {
        $this->actingAs($this->hr)->postJson(route('users.store'), $this->payload('7101'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.attendance_type_ids.0', User::NO_ATTENDANCE_METHOD_MESSAGE);

        $this->assertDatabaseMissing('users', ['employee_id' => '7101']);
    }

    public function test_create_with_an_explicit_method_succeeds(): void
    {
        $this->actingAs($this->hr)->postJson(route('users.store'), $this->payload('7102', ['attendance_type_ids' => [$this->method->id]]))->assertCreated();

        $this->assertTrue(User::find('7102')->hasResolvableAttendanceMethod());
    }

    public function test_create_with_a_location_that_has_a_default_succeeds(): void
    {
        $location = $this->location($this->method);

        $this->actingAs($this->hr)->postJson(route('users.store'), $this->payload('7103', ['work_location_id' => $location->id]))->assertCreated();

        $this->assertTrue(User::find('7103')->hasResolvableAttendanceMethod());
    }

    public function test_a_location_without_a_default_or_an_inactive_method_does_not_count(): void
    {
        $bare = $this->location(null);
        $this->actingAs($this->hr)->postJson(route('users.store'), $this->payload('7104', ['work_location_id' => $bare->id]))->assertUnprocessable();

        $inactive = AttendanceType::factory()->create(['is_active' => false]);
        $this->actingAs($this->hr)->postJson(route('users.store'), $this->payload('7105', ['attendance_type_ids' => [$inactive->id]]))->assertUnprocessable();
    }

    public function test_a_department_admin_hire_is_held_to_the_same_rule(): void
    {
        $this->actingAs($this->admin)->postJson(route('users.store'), $this->payload('7106'))->assertUnprocessable();
        $this->actingAs($this->admin)->postJson(route('users.store'), $this->payload('7107', ['attendance_type_ids' => [$this->method->id]]))->assertCreated();
    }

    public function test_onboarding_an_employee_without_a_method_is_422_and_with_one_succeeds(): void
    {
        $this->actingAs($this->hr);
        $payload = ['employee_id' => $this->e1->employee_id, 'start_date' => self::DAY, 'expected_completion_date' => '2026-06-30'];

        $this->postJson('/hr/onboarding', $payload)->assertUnprocessable()->assertJsonPath('message', User::NO_ATTENDANCE_METHOD_MESSAGE);
        $this->assertSame(0, Onboarding::where('employee_id', $this->e1->employee_id)->count());

        $this->e1->attendanceTypes()->sync([$this->method->id]);
        $this->postJson('/hr/onboarding', $payload)->assertCreated();
    }

    public function test_employee_list_flags_active_employees_who_cannot_check_in(): void
    {
        $this->e1->attendanceTypes()->sync([$this->method->id]);

        $rows = collect($this->actingAs($this->hr)->getJson(route('employees.paginate', ['perPage' => 100]))->assertOk()->json('employees.data'))->keyBy('employee_id');

        $this->assertFalse($rows[$this->e1->employee_id]['attendance_method_missing']);
        $this->assertTrue($rows[$this->e1b->employee_id]['attendance_method_missing']);
    }

    public function test_mobile_check_in_without_a_method_gets_the_actionable_message(): void
    {
        Sanctum::actingAs($this->e1b);

        $this->postJson('/api/v1/attendance/punch', [])
            ->assertStatus(422)
            ->assertJsonPath('message', "Your check-in method isn't set up yet. Ask your department admin or HR to assign one.");
    }
}
