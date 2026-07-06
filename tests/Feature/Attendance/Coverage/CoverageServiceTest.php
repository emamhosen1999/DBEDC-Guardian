<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\Designation;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\Attendance\CoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoverageServiceTest extends TestCase
{
    use RefreshDatabase;

    private function leaveType(): LeaveSetting
    {
        return LeaveSetting::create(['type' => 'Annual', 'symbol' => 'AL', 'days' => 20]);
    }

    public function test_counts_assigned_against_required_with_status(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);
        $svc = app(CoverageService::class);

        // Require 2 total on all days.
        CoverageRequirement::create([
            'work_location_id' => $loc->id, 'shift_id' => $shift->id,
            'required_headcount' => 2, 'is_active' => true,
        ]);

        // One user assigned at the location (home location fallback), one via per-day override.
        $u1 = User::factory()->create(['work_location_id' => $loc->id]);
        $u2 = User::factory()->create(); // home elsewhere; deployed via roster_day override
        RosterDay::create(['user_id' => $u1->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $u2->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'manual', 'work_location_id' => $loc->id]);

        $out = $svc->forRange('2026-07-06', '2026-07-06');
        $cell = $out['2026-07-06'][$loc->id][$shift->id]['total'];

        $this->assertSame(2, $cell['required']);
        $this->assertSame(2.0, $cell['assigned']);
        $this->assertSame('met', $cell['status']);
    }

    public function test_approved_full_and_half_leave_reduce_assigned_pending_does_not(): void
    {
        $loc = WorkLocation::create(['name' => 'Toll Plaza 1']);
        $shift = Shift::factory()->create(['code' => 'D']);
        $type = $this->leaveType();
        $svc = app(CoverageService::class);

        CoverageRequirement::create([
            'work_location_id' => $loc->id, 'shift_id' => $shift->id,
            'required_headcount' => 3, 'is_active' => true,
        ]);

        $full = User::factory()->create(['work_location_id' => $loc->id]);
        $half = User::factory()->create(['work_location_id' => $loc->id]);
        $pending = User::factory()->create(['work_location_id' => $loc->id]);
        foreach ([$full, $half, $pending] as $u) {
            RosterDay::create(['user_id' => $u->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        }
        Leave::create(['user_id' => $full->id, 'leave_type' => $type->id, 'from_date' => '2026-07-06', 'to_date' => '2026-07-06', 'status' => 'approved', 'is_half_day' => false, 'no_of_days' => 1, 'reason' => 't']);
        Leave::create(['user_id' => $half->id, 'leave_type' => $type->id, 'from_date' => '2026-07-06', 'to_date' => '2026-07-06', 'status' => 'approved', 'is_half_day' => true, 'half_day_session' => 'first_half', 'no_of_days' => 1, 'reason' => 't']);
        Leave::create(['user_id' => $pending->id, 'leave_type' => $type->id, 'from_date' => '2026-07-06', 'to_date' => '2026-07-06', 'status' => 'pending', 'is_half_day' => false, 'no_of_days' => 1, 'reason' => 't']);

        $cell = $svc->forRange('2026-07-06', '2026-07-06')['2026-07-06'][$loc->id][$shift->id]['total'];

        // full: 0, half: 0.5, pending: 1.0  => assigned 1.5 of 3 required
        $this->assertSame(1.5, $cell['assigned']);
        $this->assertSame('understaffed', $cell['status']);
    }

    public function test_date_override_beats_weekday_beats_all_days(): void
    {
        $loc = WorkLocation::create(['name' => 'Patrol Base']);
        $shift = Shift::factory()->create(['code' => 'D']);
        $svc = app(CoverageService::class);

        // 2026-07-06 is a Monday (dayOfWeek 1).
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 1, 'is_active' => true]); // all-days
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 2, 'weekday' => 1, 'is_active' => true]); // Monday
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 5, 'date' => '2026-07-06', 'is_active' => true]); // override

        $mon = $svc->forRange('2026-07-06', '2026-07-06')['2026-07-06'][$loc->id][$shift->id]['total'];
        $this->assertSame(5, $mon['required']); // date override wins

        $tue = $svc->forRange('2026-07-07', '2026-07-07')['2026-07-07'][$loc->id][$shift->id]['total'];
        $this->assertSame(1, $tue['required']); // Tuesday → all-days
    }

    public function test_role_requirement_counts_only_matching_designation(): void
    {
        $loc = WorkLocation::create(['name' => 'Toll Plaza 3']);
        $shift = Shift::factory()->create(['code' => 'D']);
        $supervisor = Designation::factory()->create();
        $svc = app(CoverageService::class);

        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'designation_id' => $supervisor->id, 'required_headcount' => 1, 'is_active' => true]);

        $sup = User::factory()->create(['work_location_id' => $loc->id, 'designation_id' => $supervisor->id]);
        $other = User::factory()->create(['work_location_id' => $loc->id]);
        RosterDay::create(['user_id' => $sup->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);
        RosterDay::create(['user_id' => $other->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern']);

        $roles = $svc->forRange('2026-07-06', '2026-07-06')['2026-07-06'][$loc->id][$shift->id]['roles'];
        $this->assertSame(1, $roles[$supervisor->id]['required']);
        $this->assertSame(1.0, $roles[$supervisor->id]['assigned']); // only the supervisor counts
        $this->assertSame('met', $roles[$supervisor->id]['status']);
    }

    public function test_query_count_bounded(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);
        CoverageRequirement::create(['work_location_id' => $loc->id, 'shift_id' => $shift->id, 'required_headcount' => 1, 'is_active' => true]);
        User::factory()->count(4)->create(['work_location_id' => $loc->id])->each(
            fn ($u) => RosterDay::create(['user_id' => $u->id, 'date' => '2026-07-06', 'shift_id' => $shift->id, 'source' => 'pattern'])
        );

        DB::enableQueryLog();
        app(CoverageService::class)->forRange('2026-07-01', '2026-07-31');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $count); // requirements + roster/users + leave overlay's 2 = constant
    }
}
