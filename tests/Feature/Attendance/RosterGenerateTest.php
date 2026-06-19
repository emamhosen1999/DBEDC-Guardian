<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterGenerateTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_and_is_idempotent_and_preserves_manual(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();
        $manualShift = Shift::factory()->create();
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => $shift->id, 'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        // Pre-existing manual override that must survive generation.
        RosterDay::create([
            'user_id' => $user->id, 'date' => '2026-06-02',
            'shift_id' => $manualShift->id, 'source' => 'manual', 'locked' => true,
        ]);

        $svc = app(RosterService::class);
        $first = $svc->generateRoster([$user->id], '2026-06-01', '2026-06-03');
        $second = $svc->generateRoster([$user->id], '2026-06-01', '2026-06-03'); // idempotent re-run

        // Both calls write/update the 2 pattern days; manual+locked day is skipped
        $this->assertSame(2, $first);
        $this->assertSame(2, $second);

        $this->assertSame(3, RosterDay::where('user_id', $user->id)->count());
        $manual = RosterDay::where('user_id', $user->id)->whereDate('date', '2026-06-02')->first();
        $this->assertSame('manual', $manual->source);
        $this->assertSame($manualShift->id, $manual->shift_id);

        $generated = RosterDay::where('user_id', $user->id)->whereDate('date', '2026-06-01')->first();
        $this->assertSame('pattern', $generated->source);
        $this->assertSame($shift->id, $generated->shift_id);
    }
}
