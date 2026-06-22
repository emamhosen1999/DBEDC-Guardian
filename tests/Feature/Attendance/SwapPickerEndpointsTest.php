<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
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
}
