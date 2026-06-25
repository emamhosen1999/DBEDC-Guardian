<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Locks in the overnight-close behavior for night shifts: a manual/web punch
 * (no check_type) at 08:00 must CLOSE the prior evening's 20:00 punch-in,
 * not open a new row — while a day-shift worker's morning punch must NOT be
 * wrongly paired to a forgotten prior-day punch-out.
 */
class NightShiftOvernightPunchTest extends TestCase
{
    use RefreshDatabase;

    private function makeShift(string $code, string $start, string $end, bool $crosses): Shift
    {
        return Shift::create([
            'name' => $code, 'code' => $code, 'type' => 'fixed',
            'start_time' => $start, 'end_time' => $end, 'crosses_midnight' => $crosses,
            'break_minutes' => 60, 'grace_in_minutes' => 15, 'grace_out_minutes' => 0,
            'full_day_minutes' => 600, 'half_day_minutes' => 300, 'min_present_minutes' => 60, 'is_active' => true,
        ]);
    }

    private function punch(User $user, array $input = []): array
    {
        return app(AttendancePunchService::class)->processPunch($user, new Request($input));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_web_punch_out_at_8am_closes_the_8pm_night_shift(): void
    {
        $user = User::factory()->create();
        $night = $this->makeShift('NIGHT', '20:00:00', '08:00:00', true);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-15', 'shift_id' => $night->id, 'source' => 'manual']);

        Carbon::setTestNow('2026-06-15 20:00:00');
        $this->punch($user); // web punch-in, no check_type

        Carbon::setTestNow('2026-06-16 08:00:00');
        $res = $this->punch($user); // web punch next morning, no check_type

        $this->assertSame('success', $res['status']);
        $this->assertSame('punch_out', $res['action'] ?? null);

        $rows = Attendance::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows, 'overnight punch must close the same row, not open a new one');
        $this->assertNotNull($rows->first()->punchout);
        $this->assertSame('2026-06-16 08:00:00', Carbon::parse($rows->first()->punchout)->format('Y-m-d H:i:s'));
    }

    public function test_day_shift_morning_punch_is_not_wrongly_paired_to_missed_prior_punchout(): void
    {
        $user = User::factory()->create();
        $day = $this->makeShift('DAY', '09:00:00', '17:00:00', false);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-15', 'shift_id' => $day->id, 'source' => 'manual']);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-16', 'shift_id' => $day->id, 'source' => 'manual']);

        Carbon::setTestNow('2026-06-15 09:00:00');
        $this->punch($user); // in; user forgets to punch out

        Carbon::setTestNow('2026-06-16 09:00:00');
        $res = $this->punch($user); // next morning

        // Must OPEN a new row (day shift doesn't cross midnight → no overnight pairing).
        $this->assertSame('punch_in', $res['action'] ?? null);
        $this->assertCount(2, Attendance::where('user_id', $user->id)->get());
    }
}
