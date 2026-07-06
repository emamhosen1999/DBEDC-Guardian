<?php

namespace Tests\Feature\Attendance\Coverage;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoverageRequirementModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_coverage_requirement_persists_with_relations(): void
    {
        $loc = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);

        $req = CoverageRequirement::create([
            'work_location_id' => $loc->id,
            'shift_id' => $shift->id,
            'designation_id' => null,
            'required_headcount' => 3,
            'weekday' => null,
            'date' => null,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('coverage_requirements', [
            'id' => $req->id, 'work_location_id' => $loc->id, 'required_headcount' => 3,
        ]);
        $this->assertTrue($req->is_active);
        $this->assertSame($loc->id, $req->workLocation->id);
        $this->assertSame($shift->id, $req->shift->id);
    }

    public function test_roster_day_stores_work_location(): void
    {
        $loc = WorkLocation::create(['name' => 'Toll Plaza 2']);
        $user = User::factory()->create();
        $shift = Shift::factory()->create(['code' => 'D']);

        $day = RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-07-06',
            'shift_id' => $shift->id, 'source' => 'manual', 'work_location_id' => $loc->id,
        ]);

        $this->assertSame($loc->id, $day->fresh()->work_location_id);
        $this->assertSame($loc->id, $day->workLocation->id);
    }
}
