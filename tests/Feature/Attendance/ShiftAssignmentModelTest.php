<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftAssignmentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_links_to_shift(): void
    {
        $shift = Shift::factory()->create();
        $a = ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => 1,
            'shift_id' => $shift->id, 'rotation_pattern_id' => null,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $this->assertSame($shift->id, $a->shift->id);
        $this->assertSame('2026-06-01', $a->effective_from->toDateString());
    }

    public function test_rotation_pattern_definition_is_array(): void
    {
        $shift = Shift::factory()->create();
        $p = ShiftRotationPattern::factory()->create([
            'cycle_length_days' => 3,
            'definition' => [$shift->id, $shift->id, 'off'],
        ]);
        $this->assertSame('off', $p->fresh()->definition[2]);
    }
}
