<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OvernightPunchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nightShiftSettings(): void
    {
        // Default schedule resolver reads AttendanceSetting; 22:00->06:00 crosses midnight.
        AttendanceSetting::create([
            'office_start_time' => '22:00', 'office_end_time' => '06:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
    }

    public function test_post_midnight_out_closes_prior_day_open_row(): void
    {
        $this->nightShiftSettings();
        $user = User::factory()->create();

        // Punch IN at 23:00 on Wed Jun 17 (working day under the Fri/Sat weekend; row dated Jun 17, open).
        Carbon::setTestNow(Carbon::parse('2026-06-17 23:00:00'));
        $svc = app(AttendancePunchService::class);
        $svc->processPunch($user, new Request(['check_type' => 'in']));

        $open = Attendance::where('user_id', $user->id)->whereDate('date', '2026-06-17')->first();
        $this->assertNotNull($open);
        $this->assertNull($open->punchout);

        // Punch OUT at 06:00 on Thu Jun 18.
        Carbon::setTestNow(Carbon::parse('2026-06-18 06:00:00'));
        $res = $svc->processPunch($user, new Request(['check_type' => 'out']));

        $this->assertSame('success', $res['status']);
        $open->refresh();
        $this->assertNotNull($open->punchout);                       // prior-day row closed
        $this->assertSame('2026-06-18 06:00:00', Carbon::parse($open->punchout)->format('Y-m-d H:i:s'));
        // No stray Jun 18 row created.
        $this->assertSame(0, Attendance::whereDate('date', '2026-06-18')->count());
    }

    public function test_day_shift_out_does_not_match_prior_day(): void
    {
        // Default 09:00-17:00 day shift (no AttendanceSetting => not crossing midnight).
        $user = User::factory()->create();
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-20 06:00:00'));
        $res = app(AttendancePunchService::class)->processPunch($user, new Request(['check_type' => 'out']));
        // No crossesMidnight shift => prior-day row NOT matched => 422, prior row stays open.
        $this->assertSame('error', $res['status']);
        $this->assertNull(Attendance::whereDate('date', '2026-06-19')->first()->punchout);
    }

    /**
     * Web/manual punch (no check_type) at 08:00 should close an overnight
     * night-shift row from the previous evening — not open a new row.
     */
    public function test_manual_punch_closes_overnight_night_shift_row(): void
    {
        $this->nightShiftSettings();
        $user = User::factory()->create();

        // Punch IN at 20:00 via web (no check_type).
        Carbon::setTestNow(Carbon::parse('2026-06-17 20:00:00'));
        $svc = app(AttendancePunchService::class);
        $res = $svc->processPunch($user, new Request([]));
        $this->assertSame('success', $res['status']);
        $this->assertSame('punch_in', $res['action']);

        $open = Attendance::where('user_id', $user->id)->whereDate('date', '2026-06-17')->first();
        $this->assertNotNull($open);
        $this->assertNull($open->punchout);

        // Next morning at 08:00, second web punch (no check_type) — should close
        // the overnight row, NOT create a new punch-in dated Jun 18.
        Carbon::setTestNow(Carbon::parse('2026-06-18 08:00:00'));
        $res = $svc->processPunch($user, new Request([]));

        $this->assertSame('success', $res['status']);
        $this->assertSame('punch_out', $res['action']);

        $open->refresh();
        $this->assertNotNull($open->punchout);
        $this->assertSame('2026-06-18 08:00:00', Carbon::parse($open->punchout)->format('Y-m-d H:i:s'));

        // No stray Jun 18 row should exist.
        $this->assertSame(0, Attendance::whereDate('date', '2026-06-18')->count());
    }

    /**
     * Day-shift worker's manual punch the next morning must NOT wrongly
     * close a prior-day row (shift does not cross midnight).
     */
    public function test_manual_punch_does_not_close_day_shift_prior_day_row(): void
    {
        // No AttendanceSetting → defaults to day shift (e.g. 09:00-17:00), not crossing midnight.
        $user = User::factory()->create();

        // Leave an open day-shift row from yesterday (forgot to punch out).
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:00:00', 'punchout' => null,
        ]);

        // At 08:00 the next day, a manual punch should open a NEW row, not close yesterday's.
        Carbon::setTestNow(Carbon::parse('2026-06-20 08:00:00'));
        $res = app(AttendancePunchService::class)->processPunch($user, new Request([]));

        $this->assertSame('success', $res['status']);
        $this->assertSame('punch_in', $res['action']);

        // Yesterday's row is still open (unclosed).
        $this->assertNull(Attendance::whereDate('date', '2026-06-19')->first()->punchout);
        // A new Jun 20 row was created.
        $this->assertSame(1, Attendance::whereDate('date', '2026-06-20')->count());
    }
}
