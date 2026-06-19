<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_schedule_builds_window_for_a_date(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '09:00', 'end_time' => '17:30',
            'crosses_midnight' => false, 'grace_in_minutes' => 15,
            'full_day_minutes' => 480, 'half_day_minutes' => 240,
        ]);

        $sched = $shift->toSchedule(Carbon::parse('2026-06-19'));
        $this->assertTrue($sched->isWorkingDay);
        $this->assertSame('2026-06-19 09:00:00', $sched->start->format('Y-m-d H:i:s'));
        $this->assertSame(480, $sched->fullDayMinutes);
    }

    public function test_night_shift_end_rolls_to_next_day(): void
    {
        $shift = Shift::factory()->create([
            'start_time' => '20:00', 'end_time' => '04:00', 'crosses_midnight' => true,
        ]);
        $sched = $shift->toSchedule(Carbon::parse('2026-06-19'));
        $this->assertSame('2026-06-20 04:00:00', $sched->end->format('Y-m-d H:i:s'));
    }
}
