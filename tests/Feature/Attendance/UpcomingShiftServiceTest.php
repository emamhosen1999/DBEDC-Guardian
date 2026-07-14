<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\UpcomingShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpcomingShiftServiceTest extends TestCase
{
    use RefreshDatabase;

    private function shift(string $code, string $start, string $end, bool $crosses = false): Shift
    {
        return Shift::factory()->create([
            'code' => $code,
            'name' => $code.' shift',
            'start_time' => $start,
            'end_time' => $end,
            'crosses_midnight' => $crosses,
            'color' => '#123456',
        ]);
    }

    private function roster(User $user, Shift $shift, string $date): void
    {
        RosterDay::create([
            'user_id' => $user->id,
            'date' => $date,
            'shift_id' => $shift->id,
            'source' => 'manual',
        ]);
    }

    public function test_today_returns_only_shifts_starting_within_twelve_hours(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $inWindow = User::factory()->create(['name' => 'In Window']);
        $outOfWindow = User::factory()->create(['name' => 'Out Of Window']);

        // 16:00 start is 7h away -> in the 12h window.
        $this->roster($inWindow, $this->shift('E', '16:00', '00:00', true), '2026-07-14');
        // 08:00 start already began -> not upcoming.
        $this->roster($outOfWindow, $this->shift('M', '08:00', '16:00'), '2026-07-14');

        $result = app(UpcomingShiftService::class)
            ->forDate(Carbon::now(), User::query()->get());

        $this->assertSame(['In Window'], $result->pluck('name')->all());
    }

    public function test_today_window_crosses_midnight_into_tomorrow(): void
    {
        Carbon::setTestNow('2026-07-14 22:00:00');

        $tomorrowNight = User::factory()->create(['name' => 'Night Owl']);
        // 00:00 start tomorrow is 2h away -> in the 12h window even though it is a different date.
        $this->roster($tomorrowNight, $this->shift('N', '00:00', '08:00'), '2026-07-15');

        $result = app(UpcomingShiftService::class)
            ->forDate(Carbon::now(), User::query()->get());

        $this->assertSame(['Night Owl'], $result->pluck('name')->all());
        $this->assertSame('N', $result->first()->shift_code);
    }

    public function test_results_are_sorted_by_shift_start_ascending(): void
    {
        Carbon::setTestNow('2026-07-14 23:30:00');

        $night = User::factory()->create(['name' => 'Night']);
        $morning = User::factory()->create(['name' => 'Morning']);

        // Both start tomorrow, inside the 12h window: 00:00 and 08:00.
        $this->roster($night, $this->shift('N', '00:00', '08:00'), '2026-07-15');
        $this->roster($morning, $this->shift('M', '08:00', '16:00'), '2026-07-15');

        $result = app(UpcomingShiftService::class)
            ->forDate(Carbon::now(), User::query()->get());

        $this->assertSame(['N', 'M'], $result->pluck('shift_code')->all());
    }

    public function test_future_date_returns_every_working_shift_that_day(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $user = User::factory()->create(['name' => 'Future Worker']);
        // A shift far outside any 12h window from now, but the whole day is ahead.
        $this->roster($user, $this->shift('E', '16:00', '00:00', true), '2026-07-20');

        $result = app(UpcomingShiftService::class)
            ->forDate(Carbon::parse('2026-07-20'), User::query()->get());

        $this->assertSame(['Future Worker'], $result->pluck('name')->all());
    }

    public function test_past_date_is_not_visible_and_returns_nothing(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        $user = User::factory()->create(['name' => 'Yesterday']);
        $this->roster($user, $this->shift('M', '08:00', '16:00'), '2026-07-13');

        $service = app(UpcomingShiftService::class);
        $past = Carbon::parse('2026-07-13');

        $this->assertFalse($service->isVisibleFor($past));
        $this->assertTrue($service->forDate($past, User::query()->get())->isEmpty());
    }

    public function test_partition_splits_upcoming_absent_and_off(): void
    {
        Carbon::setTestNow('2026-07-14 09:00:00');

        // Shift starts at 16:00 -> inside the 12h window -> upcoming, not absent.
        $upcoming = User::factory()->create(['name' => 'Upcoming Person']);
        $this->roster($upcoming, $this->shift('E', '16:00', '23:59'), '2026-07-14');

        // Shift started at 08:00 and they never punched in -> absent.
        $absent = User::factory()->create(['name' => 'Absent Person']);
        $this->roster($absent, $this->shift('M', '08:00', '16:00'), '2026-07-14');

        // Explicit day-off roster row (shift_id null) -> off. A user with NO
        // roster/assignment row at all instead falls back to the company's
        // default working hours (DefaultScheduleResolver), which is a working
        // day, not "off" — so the off case must be represented explicitly.
        $off = User::factory()->create(['name' => 'Off Person']);
        RosterDay::create([
            'user_id' => $off->id,
            'date' => '2026-07-14',
            'shift_id' => null,
            'source' => 'manual',
        ]);

        $all = collect([$upcoming, $absent, $off]);

        // Nobody punched in, so every user is in the not-present set.
        $result = app(UpcomingShiftService::class)->partition(Carbon::now(), $all, $all);

        $this->assertSame(['Upcoming Person'], $result['upcoming']->pluck('name')->all());
        $this->assertSame(['Absent Person'], $result['absent']->pluck('name')->all());
        $this->assertSame(['Off Person'], $result['off']->pluck('name')->all());
        $this->assertSame('M', $result['absent']->first()->shift_code);
    }
}
