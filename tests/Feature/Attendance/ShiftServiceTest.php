<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Services\Attendance\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ShiftServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_assignment_with_both_shift_and_pattern(): void
    {
        $shift = Shift::factory()->create();
        $this->expectException(InvalidArgumentException::class);

        app(ShiftService::class)->createAssignment([
            'scope_type' => 'user', 'scope_id' => 1,
            'shift_id' => $shift->id, 'rotation_pattern_id' => 1,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);
    }

    public function test_rejects_overlapping_effective_dates_for_same_scope(): void
    {
        $shift = Shift::factory()->create();
        $svc = app(ShiftService::class);

        $svc->createAssignment([
            'scope_type' => 'user', 'scope_id' => 5,
            'shift_id' => $shift->id, 'rotation_pattern_id' => null,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01', 'effective_to' => '2026-06-30',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $svc->createAssignment([
            'scope_type' => 'user', 'scope_id' => 5,
            'shift_id' => $shift->id, 'rotation_pattern_id' => null,
            'anchor_date' => '2026-06-15', 'effective_from' => '2026-06-15', 'effective_to' => '2026-07-15',
        ]);
    }

    public function test_allows_non_overlapping_assignment(): void
    {
        $shift = Shift::factory()->create();
        $svc = app(ShiftService::class);

        $svc->createAssignment([
            'scope_type' => 'user', 'scope_id' => 5, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01', 'effective_to' => '2026-06-30',
        ]);
        $a = $svc->createAssignment([
            'scope_type' => 'user', 'scope_id' => 5, 'shift_id' => $shift->id,
            'anchor_date' => '2026-07-01', 'effective_from' => '2026-07-01', 'effective_to' => null,
        ]);

        $this->assertNotNull($a->id);
    }
}
