<?php

namespace Tests\Feature\Api;

use App\Models\HRM\CompOffLedger;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\HRM\Department;
use App\Services\Attendance\CompOffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MobileAttendanceRequestApiTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Guest 401 guards
    // -------------------------------------------------------------------------

    public function test_guest_cannot_access_my_roster(): void
    {
        $this->getJson('/api/v1/attendance/my-roster?from=2026-06-01&to=2026-06-30')
            ->assertUnauthorized();
    }

    public function test_guest_cannot_submit_regularization(): void
    {
        $this->postJson('/api/v1/attendance/regularizations', [])
            ->assertUnauthorized();
    }

    public function test_guest_cannot_list_own_regularizations(): void
    {
        $this->getJson('/api/v1/attendance/regularizations/mine')
            ->assertUnauthorized();
    }

    public function test_guest_cannot_submit_overtime(): void
    {
        $this->postJson('/api/v1/attendance/overtime', [])
            ->assertUnauthorized();
    }

    public function test_guest_cannot_list_own_overtime(): void
    {
        $this->getJson('/api/v1/attendance/overtime/mine')
            ->assertUnauthorized();
    }

    public function test_guest_cannot_view_comp_off_balance(): void
    {
        $this->getJson('/api/v1/attendance/comp-off/mine')
            ->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Regularization
    // -------------------------------------------------------------------------

    public function test_authenticated_employee_can_submit_regularization_request(): void
    {
        $manager = User::factory()->create();
        $employee = User::factory()->create(['report_to' => $manager->id]);

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/v1/attendance/regularizations', [
            'date'   => '2026-06-10',
            'type'   => 'missed_day',
            'reason' => 'I was sick but forgot to apply leave',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('attendance_regularizations', [
            'user_id' => $employee->id,
            'date'    => '2026-06-10',
            'status'  => 'pending',
        ]);
    }

    public function test_regularization_submission_validates_required_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/attendance/regularizations', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date', 'type', 'reason']);
    }

    public function test_regularization_submission_validates_type_enum(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/attendance/regularizations', [
            'date'   => '2026-06-10',
            'type'   => 'invalid_type',
            'reason' => 'reason',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_authenticated_employee_can_list_own_regularizations(): void
    {
        $manager = User::factory()->create();
        $employee = User::factory()->create(['report_to' => $manager->id]);
        $other    = User::factory()->create();

        Sanctum::actingAs($employee);

        // Submit one regularization for this employee
        $this->postJson('/api/v1/attendance/regularizations', [
            'date'   => '2026-06-10',
            'type'   => 'missed_day',
            'reason' => 'Forgot to apply leave',
        ])->assertCreated();

        // Submit another for a different user (should not appear in the response)
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/attendance/regularizations', [
            'date'   => '2026-06-11',
            'type'   => 'missing_punchin',
            'reason' => 'Forgot to punch in',
        ])->assertCreated();

        // Back to employee
        Sanctum::actingAs($employee);
        $response = $this->getJson('/api/v1/attendance/regularizations/mine');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    // -------------------------------------------------------------------------
    // Overtime
    // -------------------------------------------------------------------------

    /** A rostered rest day (Wednesday) worked 09:00-17:00 by the employee; every other day is a working day. */
    private function workedRestDay(User $employee, string $date = '2026-06-10'): void
    {
        $shift = Shift::factory()->create();
        \App\Models\HRM\ShiftAssignment::factory()->create(['scope_type' => 'user', 'scope_id' => $employee->id, 'shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-01']);
        RosterDay::create(['user_id' => $employee->id, 'date' => $date, 'shift_id' => null, 'source' => 'manual', 'locked' => true]);
        \App\Models\HRM\Attendance::create(['user_id' => $employee->id, 'date' => $date, 'punchin' => "$date 09:00:00", 'punchout' => "$date 17:00:00"]);
    }

    public function test_authenticated_employee_can_submit_an_overtime_claim_for_a_worked_rest_day(): void
    {
        \Illuminate\Support\Facades\Storage::fake('attachments');
        $employee = User::factory()->create();
        $this->workedRestDay($employee);
        Sanctum::actingAs($employee);

        $token = $this->postJson('/api/v1/attachments/stage', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('claim.jpg', \Tests\Support\Attachments\SyntheticDocuments::jpeg(\Tests\Support\Attachments\SyntheticDocuments::page(), 88))])
            ->assertCreated()->json('data.token');

        $this->postJson('/api/v1/attendance/overtime', ['date' => '2026-06-10', 'compensation' => 'replacement', 'attachment_tokens' => [$token]])
            ->assertCreated()->assertJsonPath('success', true)->assertJsonPath('data.day_credit', 1);

        $this->assertDatabaseHas('overtime_requests', ['user_id' => $employee->id, 'date' => '2026-06-10', 'claim_type' => 'rest_day', 'compensation' => 'replacement']);
    }

    public function test_overtime_for_a_working_day_is_refused(): void
    {
        \Illuminate\Support\Facades\Storage::fake('attachments');
        $employee = User::factory()->create();
        $this->workedRestDay($employee, '2026-06-10');
        \App\Models\HRM\Attendance::create(['user_id' => $employee->id, 'date' => '2026-06-11', 'punchin' => '2026-06-11 09:00:00', 'punchout' => '2026-06-11 21:00:00']);
        Sanctum::actingAs($employee);
        $token = $this->postJson('/api/v1/attachments/stage', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('claim.jpg', \Tests\Support\Attachments\SyntheticDocuments::jpeg(\Tests\Support\Attachments\SyntheticDocuments::page(), 88))])->json('data.token');

        $this->postJson('/api/v1/attendance/overtime', ['date' => '2026-06-11', 'compensation' => 'payment', 'attachment_tokens' => [$token]])
            ->assertStatus(422)->assertJsonValidationErrors(['date']);
    }

    public function test_overtime_submission_validates_required_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/attendance/overtime', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date', 'compensation', 'attachment_tokens']);
    }

    public function test_overtime_always_needs_a_supporting_document(): void
    {
        $employee = User::factory()->create();
        $this->workedRestDay($employee);
        Sanctum::actingAs($employee);

        $this->postJson('/api/v1/attendance/overtime', ['date' => '2026-06-10', 'compensation' => 'payment'])
            ->assertStatus(422)->assertJsonValidationErrors(['attachment_tokens']);
    }

    public function test_authenticated_employee_can_list_own_overtime_requests(): void
    {
        $employee = User::factory()->create();
        $other    = User::factory()->create();
        // Minute-based requests are read-only history; they are still listed (flagged legacy).
        \App\Models\HRM\OvertimeRequest::factory()->create(['user_id' => $employee->id, 'date' => '2026-06-10', 'requested_minutes' => 90]);
        \App\Models\HRM\OvertimeRequest::factory()->create(['user_id' => $other->id, 'date' => '2026-06-11', 'requested_minutes' => 60]);

        Sanctum::actingAs($employee);
        $response = $this->getJson('/api/v1/attendance/overtime/mine');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.legacy', true);
    }

    // -------------------------------------------------------------------------
    // Replacement leave balance (days)
    // -------------------------------------------------------------------------

    public function test_authenticated_employee_can_view_replacement_leave_balance_in_days(): void
    {
        $employee = User::factory()->create();

        /** @var CompOffService $svc */
        $svc = app(CompOffService::class);
        $svc->credit($employee->id, 1.0, 'overtime', null, 'Test credit', \Carbon\Carbon::today());

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/v1/attendance/comp-off/mine');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balance_days', 1)
            ->assertJsonPath('data.available_days', 1);

        $this->assertCount(1, $response->json('data.entries'));
        $this->assertCount(1, $response->json('data.credits'));
    }

    public function test_replacement_balance_is_zero_when_no_entries_exist(): void
    {
        $employee = User::factory()->create();

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/v1/attendance/comp-off/mine');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balance_days', 0);
    }

    // -------------------------------------------------------------------------
    // Swap eligibility (role resolution under the sanctum guard)
    // -------------------------------------------------------------------------

    public function test_swap_eligible_resolves_employee_role_under_sanctum_guard(): void
    {
        // Regression for the live swaps/eligible 500. The Employee role is
        // registered for the web guard, but auth:sanctum calls Auth::shouldUse('sanctum'),
        // so by the time the controller runs the request's default guard is 'sanctum'.
        // User::role('Employee') must pin the web guard or it throws RoleDoesNotExist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']); // created under the web guard

        $dept = Department::factory()->create();
        $me = User::factory()->create(['department_id' => $dept->id]);
        $free = User::factory()->create(['department_id' => $dept->id]);
        $me->assignRole('Employee');
        $free->assignRole('Employee');

        Sanctum::actingAs($me);
        // Mirror the post-auth-middleware state of a real sanctum request.
        config(['auth.defaults.guard' => 'sanctum']);

        $response = $this->getJson('/api/v1/attendance/swaps/eligible?date=2026-07-01');

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertContains($free->id, collect($response->json('data.employees'))->pluck('id')->all());
    }

    // -------------------------------------------------------------------------
    // My-roster
    // -------------------------------------------------------------------------

    public function test_authenticated_employee_can_view_own_roster(): void
    {
        $employee = User::factory()->create();

        $shift = Shift::create([
            'name'  => 'Morning',
            'code'  => 'MOR',
            'color' => '#ff0000',
            'start_time' => '09:00',
            'end_time'   => '17:00',
        ]);

        RosterDay::create([
            'user_id'  => $employee->id,
            'date'     => '2026-06-10',
            'shift_id' => $shift->id,
            'source'   => 'manual',
        ]);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/v1/attendance/my-roster?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertJsonPath('success', true);

        // The data.days key should have the date we seeded
        $days = $response->json('data.days');
        $this->assertArrayHasKey('2026-06-10', $days);
        $this->assertSame('MOR', $days['2026-06-10']['code']);
    }

    public function test_my_roster_validates_from_to_params(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/attendance/my-roster')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_my_roster_only_returns_the_authenticated_users_days(): void
    {
        $employee = User::factory()->create();
        $other    = User::factory()->create();

        $shift = Shift::create([
            'name'  => 'Evening',
            'code'  => 'EVE',
            'color' => '#0000ff',
            'start_time' => '14:00',
            'end_time'   => '22:00',
        ]);

        RosterDay::create([
            'user_id'  => $other->id,
            'date'     => '2026-06-15',
            'shift_id' => $shift->id,
            'source'   => 'manual',
        ]);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/v1/attendance/my-roster?from=2026-06-01&to=2026-06-30');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $days = $response->json('data.days');
        $this->assertEmpty($days);
    }
}
