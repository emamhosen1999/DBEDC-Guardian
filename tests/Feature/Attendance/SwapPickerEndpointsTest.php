<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SwapPickerEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
        $this->dept = Department::factory()->create();
    }

    private function employee(?int $departmentId = null): User
    {
        $u = User::factory()->create(['department_id' => $departmentId ?? $this->dept->id]);
        $u->assignRole('Employee');
        $u->givePermissionTo('attendance.own.view');

        return $u;
    }

    public function test_eligible_lists_only_same_dept_free_coworkers(): void
    {
        $me = $this->employee();
        $free = $this->employee();
        $busy = $this->employee();
        $otherDept = $this->employee(Department::factory()->create()->id);
        $shift = Shift::factory()->create();
        RosterDay::create(['user_id' => $busy->id, 'date' => '2026-07-01', 'shift_id' => $shift->id, 'source' => 'pattern']);

        $res = $this->actingAs($me)->getJson(route('attendance.swaps.eligible', ['date' => '2026-07-01']))->assertOk();

        $ids = collect($res->json('employees'))->pluck('id')->all();
        $this->assertContains($free->id, $ids);
        $this->assertNotContains($busy->id, $ids);       // working that day
        $this->assertNotContains($otherDept->id, $ids);  // different department
        $this->assertNotContains($me->id, $ids);         // not self
    }

    public function test_counterparty_roster_returns_working_days(): void
    {
        $me = $this->employee();
        $mate = $this->employee();
        $shift = Shift::factory()->create(['code' => 'DAY', 'start_time' => '08:00', 'end_time' => '20:00']);
        RosterDay::create(['user_id' => $mate->id, 'date' => '2026-07-03', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $mate->id, 'date' => '2026-07-04', 'shift_id' => null, 'source' => 'pattern']); // off

        $res = $this->actingAs($me)->getJson(route('attendance.swaps.counterpartyRoster', [
            'counterparty_id' => $mate->id, 'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertOk();

        $days = collect($res->json('days'));
        $this->assertSame(1, $days->count());
        $this->assertSame('2026-07-03', $days->first()['date']);
        $this->assertSame('DAY', $days->first()['code']);
    }

    public function test_counterparty_roster_blocks_cross_department(): void
    {
        $me = $this->employee();
        $stranger = $this->employee(Department::factory()->create()->id);

        $this->actingAs($me)->getJson(route('attendance.swaps.counterpartyRoster', [
            'counterparty_id' => $stranger->id, 'from' => '2026-07-01', 'to' => '2026-07-31',
        ]))->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // eligible() — eligibility context block
    // -------------------------------------------------------------------------

    public function test_eligible_returns_eligibility_counts(): void
    {
        // 5 rank-eligible dept teammates: 1 free on the date, 4 rostered.
        $me = $this->employee();
        $free = $this->employee();
        $busy1 = $this->employee();
        $busy2 = $this->employee();
        $busy3 = $this->employee();
        $busy4 = $this->employee();
        // Noise that must NOT count toward department_eligible_total.
        $otherDept = $this->employee(Department::factory()->create()->id);

        $shift = Shift::factory()->create();
        foreach ([$busy1, $busy2, $busy3, $busy4] as $u) {
            RosterDay::create(['user_id' => $u->id, 'date' => '2026-07-20', 'shift_id' => $shift->id, 'source' => 'pattern']);
        }
        // Cross-dept coworker is also rostered — irrelevant to the counts.
        RosterDay::create(['user_id' => $otherDept->id, 'date' => '2026-07-20', 'shift_id' => $shift->id, 'source' => 'pattern']);

        $res = $this->actingAs($me)
            ->getJson(route('attendance.swaps.eligible', ['date' => '2026-07-20']))
            ->assertOk();

        // employees stays top-level and holds only the free teammate.
        $ids = collect($res->json('employees'))->pluck('id')->all();
        $this->assertSame([$free->id], $ids);

        $res->assertJsonPath('eligibility.date', '2026-07-20')
            ->assertJsonPath('eligibility.department_eligible_total', 5)
            ->assertJsonPath('eligibility.available_count', 1)
            ->assertJsonPath('eligibility.busy_count', 4);
    }

    // -------------------------------------------------------------------------
    // pickup() — reverse "shifts I could take" direction
    // -------------------------------------------------------------------------

    public function test_pickup_returns_only_shifts_on_requester_free_dates(): void
    {
        $me = $this->employee();
        $mate = $this->employee();
        $shift = Shift::factory()->create(['code' => 'EVN', 'name' => 'Evening', 'start_time' => '15:00', 'end_time' => '23:00']);

        // Mate works two dates in range.
        RosterDay::create(['user_id' => $mate->id, 'date' => '2026-07-22', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $mate->id, 'date' => '2026-07-23', 'shift_id' => $shift->id, 'source' => 'pattern']);

        // Requester is already rostered on 07-23 → cannot pick that up (double-booking).
        RosterDay::create(['user_id' => $me->id, 'date' => '2026-07-23', 'shift_id' => $shift->id, 'source' => 'pattern']);

        $res = $this->actingAs($me)->getJson(route('attendance.swaps.pickup', [
            'from' => '2026-07-20', 'to' => '2026-07-31',
        ]))->assertOk();

        $shifts = collect($res->json('shifts'));
        $this->assertSame(1, $shifts->count());
        $row = $shifts->first();
        $this->assertSame('2026-07-22', $row['date']);          // the date the requester is free
        $this->assertSame($mate->id, $row['counterparty_id']);
        $this->assertSame($mate->name, $row['counterparty_name']);
        $this->assertSame('EVN', $row['shift_code']);
        $this->assertSame('Evening', $row['shift_name']);
        $this->assertSame('15:00', $row['start']);
        $this->assertSame('23:00', $row['end']);

        // 07-23 excluded because the requester works it.
        $this->assertNotContains('2026-07-23', $shifts->pluck('date')->all());
    }

    public function test_pickup_excludes_self_other_departments_and_higher_rank(): void
    {
        $mid = Designation::factory()->create(['hierarchy_level' => 5, 'department_id' => $this->dept->id]);
        $senior = Designation::factory()->create(['hierarchy_level' => 2, 'department_id' => $this->dept->id]);
        $junior = Designation::factory()->create(['hierarchy_level' => 8, 'department_id' => $this->dept->id]);

        $me = $this->employee();
        $me->forceFill(['designation_id' => $mid->id])->save(); // requester level 5

        $peer = $this->employee();
        $peer->forceFill(['designation_id' => $mid->id])->save();      // same rank → eligible
        $lower = $this->employee();
        $lower->forceFill(['designation_id' => $junior->id])->save();  // lower rank → eligible
        $higher = $this->employee();
        $higher->forceFill(['designation_id' => $senior->id])->save(); // higher rank → NOT eligible
        $stranger = $this->employee(Department::factory()->create()->id); // other dept → NOT eligible

        $shift = Shift::factory()->create(['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:00']);
        foreach ([$me, $peer, $lower, $higher, $stranger] as $u) {
            RosterDay::create(['user_id' => $u->id, 'date' => '2026-07-25', 'shift_id' => $shift->id, 'source' => 'pattern']);
        }
        // Requester is free on 07-26 so eligible peers' shifts that day are pickable.
        RosterDay::create(['user_id' => $peer->id, 'date' => '2026-07-26', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $lower->id, 'date' => '2026-07-26', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $higher->id, 'date' => '2026-07-26', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $stranger->id, 'date' => '2026-07-26', 'shift_id' => $shift->id, 'source' => 'pattern']);

        $res = $this->actingAs($me)->getJson(route('attendance.swaps.pickup', [
            'from' => '2026-07-24', 'to' => '2026-07-30',
        ]))->assertOk();

        $counterpartyIds = collect($res->json('shifts'))->pluck('counterparty_id')->unique()->values()->all();
        sort($counterpartyIds);
        $expected = [$peer->id, $lower->id];
        sort($expected);
        $this->assertSame($expected, $counterpartyIds); // only same/lower rank, same dept, not self
    }

    public function test_pickup_rejects_range_over_31_days(): void
    {
        $me = $this->employee();

        $this->actingAs($me)->getJson(route('attendance.swaps.pickup', [
            'from' => '2026-07-01', 'to' => '2026-08-15', // 45 days
        ]))->assertStatus(422)->assertJsonValidationErrors('to');
    }
}
