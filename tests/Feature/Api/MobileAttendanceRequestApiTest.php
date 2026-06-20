<?php

namespace Tests\Feature\Api;

use App\Models\HRM\CompOffLedger;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\CompOffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    public function test_authenticated_employee_can_submit_overtime_request(): void
    {
        $employee = User::factory()->create();

        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/v1/attendance/overtime', [
            'date'               => '2026-06-10',
            'requested_minutes'  => 120,
            'reason'             => 'Project deadline',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('overtime_requests', [
            'user_id'            => $employee->id,
            'date'               => '2026-06-10',
            'requested_minutes'  => 120,
        ]);
    }

    public function test_overtime_submission_validates_required_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/attendance/overtime', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date', 'requested_minutes', 'reason']);
    }

    public function test_overtime_submission_validates_minutes_range(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/attendance/overtime', [
            'date'              => '2026-06-10',
            'requested_minutes' => 0,
            'reason'            => 'reason',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requested_minutes']);
    }

    public function test_authenticated_employee_can_list_own_overtime_requests(): void
    {
        $employee = User::factory()->create();
        $other    = User::factory()->create();

        Sanctum::actingAs($employee);

        $this->postJson('/api/v1/attendance/overtime', [
            'date'              => '2026-06-10',
            'requested_minutes' => 90,
            'reason'            => 'Deadline',
        ])->assertCreated();

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/attendance/overtime', [
            'date'              => '2026-06-11',
            'requested_minutes' => 60,
            'reason'            => 'Other user OT',
        ])->assertCreated();

        Sanctum::actingAs($employee);
        $response = $this->getJson('/api/v1/attendance/overtime/mine');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    // -------------------------------------------------------------------------
    // Comp-off
    // -------------------------------------------------------------------------

    public function test_authenticated_employee_can_view_comp_off_balance(): void
    {
        $employee = User::factory()->create();

        /** @var CompOffService $svc */
        $svc = app(CompOffService::class);
        $svc->credit($employee->id, 120, 'overtime', null, 'Test credit');

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/v1/attendance/comp-off/mine');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balance_minutes', 120);

        $this->assertCount(1, $response->json('data.entries'));
    }

    public function test_comp_off_balance_is_zero_when_no_entries_exist(): void
    {
        $employee = User::factory()->create();

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/v1/attendance/comp-off/mine');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balance_minutes', 0);
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
