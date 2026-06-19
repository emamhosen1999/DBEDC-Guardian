<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\User;
use App\Services\Attendance\RosterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterResolveShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_user_assignment_resolves(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => $shift->id, 'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $resolved = app(RosterService::class)->resolveShift($user->id, Carbon::parse('2026-06-19'));
        $this->assertSame($shift->id, $resolved->id);
    }

    public function test_rotation_pattern_resolves_by_cycle_day(): void
    {
        $user = User::factory()->create();
        $morning = Shift::factory()->create(['code' => 'MOR']);
        $night = Shift::factory()->create(['code' => 'NGT']);
        $pattern = ShiftRotationPattern::factory()->create([
            'cycle_length_days' => 3,
            'definition' => [$morning->id, $night->id, 'off'],
        ]);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => null, 'rotation_pattern_id' => $pattern->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $svc = app(RosterService::class);
        // 2026-06-01 = day0 → morning; 06-02 = night; 06-03 = off (null)
        $this->assertSame($morning->id, $svc->resolveShift($user->id, Carbon::parse('2026-06-01'))->id);
        $this->assertSame($night->id, $svc->resolveShift($user->id, Carbon::parse('2026-06-02'))->id);
        $this->assertNull($svc->resolveShift($user->id, Carbon::parse('2026-06-03')));
    }

    public function test_manual_roster_override_beats_assignment(): void
    {
        $user = User::factory()->create();
        $assigned = Shift::factory()->create(['code' => 'ASG']);
        $override = Shift::factory()->create(['code' => 'OVR']);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => $assigned->id, 'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);
        RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-06-19',
            'shift_id' => $override->id, 'source' => 'manual', 'locked' => true,
        ]);

        $resolved = app(RosterService::class)->resolveShift($user->id, Carbon::parse('2026-06-19'));
        $this->assertSame($override->id, $resolved->id);
    }

    public function test_falls_back_through_designation_department_org(): void
    {
        $dept = Department::factory()->create();
        $desig = Designation::factory()->create(['department_id' => $dept->id]);
        $user = User::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id]);
        $orgShift = Shift::factory()->create(['code' => 'ORG']);
        ShiftAssignment::factory()->create([
            'scope_type' => 'org', 'scope_id' => null,
            'shift_id' => $orgShift->id, 'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01', 'priority' => 0,
        ]);

        $resolved = app(RosterService::class)->resolveShift($user->id, Carbon::parse('2026-06-19'));
        $this->assertSame($orgShift->id, $resolved->id);
    }
}
