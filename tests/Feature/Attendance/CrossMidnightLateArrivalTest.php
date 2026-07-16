<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\DayAttendance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Locks in cross-midnight business-date resolution for punch-in: a night
 * shift rostered on day D (e.g. 23:00 -> 07:00, crosses_midnight) must have
 * a LATE arrival past midnight land on attendances.date = D (the rostered
 * day), not D+1 (the punch's raw calendar date) — otherwise the officer is
 * wrongly marked ABSENT on D and a phantom unscheduled row is created on D+1.
 */
class CrossMidnightLateArrivalTest extends TestCase
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

    public function test_late_arrival_past_midnight_binds_to_rostered_day_and_scores_late_not_absent(): void
    {
        $user = User::factory()->create();
        $night = $this->makeShift('NGT', '23:00:00', '07:00:00', true);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-15', 'shift_id' => $night->id, 'source' => 'manual']);

        // Officer arrives 1h15m late — well past midnight.
        Carbon::setTestNow('2026-06-16 00:15:00');
        $res = $this->punch($user);

        $this->assertSame('success', $res['status']);
        $this->assertSame('punch_in', $res['action'] ?? null);

        $rows = Attendance::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('2026-06-15', Carbon::parse($row->date)->format('Y-m-d'), 'must bind to the ROSTERED day, not the raw calendar date of the punch');
        $this->assertSame('2026-06-16 00:15:00', Carbon::parse($row->punchin)->format('Y-m-d H:i:s'), 'the real capture moment must be preserved verbatim');

        // Complete the shift with an ordinary out-punch so the engine has a
        // full worked-minutes picture (an open row would score SHORT, not LATE).
        Carbon::setTestNow('2026-06-16 07:00:00');
        $this->punch($user);
        $row->refresh();

        // The engine must score this LATE, not ABSENT.
        $schedule = $night->toSchedule(Carbon::parse('2026-06-15'));
        $day = app(AttendanceStatusService::class)->resolve(collect([$row]), $schedule);
        $this->assertSame(DayAttendance::LATE, $day->status);
    }

    public function test_early_arrival_before_midnight_is_unaffected(): void
    {
        $user = User::factory()->create();
        $night = $this->makeShift('NGT', '23:00:00', '07:00:00', true);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-15', 'shift_id' => $night->id, 'source' => 'manual']);

        // Arrives early, before midnight — same calendar day as the roster.
        Carbon::setTestNow('2026-06-15 22:50:00');
        $res = $this->punch($user);

        $this->assertSame('success', $res['status']);
        $this->assertSame('punch_in', $res['action'] ?? null);

        $row = Attendance::where('user_id', $user->id)->first();
        $this->assertSame('2026-06-15', Carbon::parse($row->date)->format('Y-m-d'));
    }

    public function test_day_shift_worker_punching_after_midnight_is_never_rebound(): void
    {
        $user = User::factory()->create();
        $day = $this->makeShift('DAY', '09:00:00', '17:00:00', false);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-15', 'shift_id' => $day->id, 'source' => 'manual']);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-16', 'shift_id' => $day->id, 'source' => 'manual']);

        // A stray/unscheduled punch just after midnight for a day-shift worker.
        Carbon::setTestNow('2026-06-16 00:15:00');
        $res = $this->punch($user);

        $this->assertSame('success', $res['status']);
        $this->assertSame('punch_in', $res['action'] ?? null);

        $row = Attendance::where('user_id', $user->id)->first();
        // Day-shift schedules never cross midnight -> resolveBusinessDate must
        // return the raw calendar date of the punch (D+1), never rebind.
        $this->assertSame('2026-06-16', Carbon::parse($row->date)->format('Y-m-d'));
    }

    public function test_already_closed_prior_night_shift_is_not_rebound_for_a_new_late_night_punch(): void
    {
        $user = User::factory()->create();
        $night = $this->makeShift('NGT', '23:00:00', '07:00:00', true);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-15', 'shift_id' => $night->id, 'source' => 'manual']);

        // Full, ordinary in/out cycle for the rostered night shift.
        Carbon::setTestNow('2026-06-15 23:05:00');
        $this->punch($user); // punch in
        Carbon::setTestNow('2026-06-16 07:05:00');
        $this->punch($user); // punch out — closes the 2026-06-15 row

        $closed = Attendance::where('user_id', $user->id)->whereDate('date', '2026-06-15')->first();
        $this->assertNotNull($closed);
        $this->assertNotNull($closed->punchout);

        // A further punch-in, still inside the (already-closed) shift's tolerance
        // window, must NOT be folded back into the closed 2026-06-15 row.
        Carbon::setTestNow('2026-06-16 06:30:00');
        $res = $this->punch($user);

        $this->assertSame('success', $res['status']);
        $this->assertSame('punch_in', $res['action'] ?? null, 'a completed prior-day row must not be closed again nor rebind — this is a distinct event');

        $rows = Attendance::where('user_id', $user->id)->get();
        $this->assertCount(2, $rows);
        $newRow = $rows->firstWhere('id', '!=', $closed->id);
        $this->assertSame('2026-06-16', Carbon::parse($newRow->date)->format('Y-m-d'));

        // The originally closed row must remain untouched.
        $closed->refresh();
        $this->assertSame('2026-06-16 07:05:00', Carbon::parse($closed->punchout)->format('Y-m-d H:i:s'));
    }

    public function test_out_punch_pairing_still_works_after_cross_midnight_rebind(): void
    {
        $user = User::factory()->create();
        $night = $this->makeShift('NGT', '23:00:00', '07:00:00', true);
        RosterDay::create(['user_id' => $user->id, 'date' => '2026-06-15', 'shift_id' => $night->id, 'source' => 'manual']);

        // Late arrival — rebound to the rostered 2026-06-15 row.
        Carbon::setTestNow('2026-06-16 00:15:00');
        $in = $this->punch($user);
        $this->assertSame('punch_in', $in['action'] ?? null);

        // Ordinary out-punch the same morning must close the SAME (rebound) row.
        Carbon::setTestNow('2026-06-16 07:05:00');
        $out = $this->punch($user);

        $this->assertSame('success', $out['status']);
        $this->assertSame('punch_out', $out['action'] ?? null);

        $rows = Attendance::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows, 'the out-punch must close the same rebound row, not open a new one');
        $row = $rows->first();
        $this->assertSame('2026-06-15', Carbon::parse($row->date)->format('Y-m-d'));
        $this->assertSame('2026-06-16 00:15:00', Carbon::parse($row->punchin)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-16 07:05:00', Carbon::parse($row->punchout)->format('Y-m-d H:i:s'));
    }
}
