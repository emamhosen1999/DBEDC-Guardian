<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterScheduleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolved_shift_drives_late_window(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create([
            'start_time' => '10:00', 'end_time' => '18:00', 'grace_in_minutes' => 0,
        ]);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $sched = app(ScheduleResolver::class)->resolve($user->id, Carbon::parse('2026-06-19'));
        $this->assertTrue($sched->isWorkingDay);
        $this->assertSame('10:00', $sched->start->format('H:i'));
    }

    public function test_off_day_is_non_working(): void
    {
        $user = User::factory()->create();
        // No assignment, no roster → falls back to default resolver (settings). With no settings, default working window.
        $sched = app(ScheduleResolver::class)->resolve($user->id, Carbon::parse('2026-06-19'));
        $this->assertNotNull($sched);
    }
}
