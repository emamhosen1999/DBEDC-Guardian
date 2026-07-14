<?php

namespace App\Services\Attendance;

use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The single definition of "upcoming shift", shared by the web attendance page
 * and the mobile team-attendance screen.
 *
 *  - today   → shifts starting in [now, now + 12h]; the window crosses midnight,
 *              so at 22:00 tomorrow's 00-08 shift is upcoming.
 *  - future  → every working shift on that date (the whole day is ahead).
 *  - past    → nothing at all; the section is hidden, not rendered empty.
 */
class UpcomingShiftService
{
    public const WINDOW_HOURS = 12;

    public function __construct(
        private readonly ScheduleResolver $schedules,
        private readonly RosterService $roster,
    ) {}

    /**
     * Is an Upcoming section meaningful for this date? False for past dates.
     */
    public function isVisibleFor(CarbonInterface $date): bool
    {
        return ! $date->copy()->startOfDay()->lt(Carbon::now()->startOfDay());
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>  decorated + sorted by shift start
     */
    public function forDate(CarbonInterface $date, Collection $users): Collection
    {
        if (! $this->isVisibleFor($date)) {
            return collect();
        }

        return $date->copy()->startOfDay()->isToday()
            ? $this->forToday($users)
            : $this->forFutureDate($date, $users);
    }

    /**
     * The whole upcoming / absent / off split, in one place. Both the web
     * attendance page and the mobile team-attendance screen call this — the
     * logic used to live twice and drifted.
     *
     * @param  Collection<int, User>  $allUsers      every employee in scope
     * @param  Collection<int, User>  $absentUsers   those in scope with no punch-in for the date
     * @return array{upcoming: Collection<int, User>, absent: Collection<int, User>, off: Collection<int, User>}
     */
    public function partition(CarbonInterface $date, Collection $allUsers, Collection $absentUsers): array
    {
        $upcoming = $this->forDate($date, $allUsers);
        $upcomingIds = $upcoming->pluck('id')->all();

        $absent = collect();
        $off = collect();

        foreach ($absentUsers as $user) {
            // A user whose shift has not started yet belongs to Upcoming, not Absent.
            if (in_array($user->id, $upcomingIds, true)) {
                continue;
            }

            $schedule = $this->schedules->resolve($user->id, $date);

            if (! $schedule->isWorkingDay) {
                $off->push($user);

                continue;
            }

            // Working day, not upcoming → the shift already started (or the date is
            // in the past) and the user never punched in.
            $absent->push($this->decorate($user, $date, $schedule));
        }

        return [
            'upcoming' => $upcoming,
            'absent' => $this->sortByShiftStart($absent),
            'off' => $off->sortBy(fn (User $user) => (string) $user->name)->values(),
        ];
    }

    /**
     * Shifts starting inside [now, now + 12h]. Today's shift is checked first,
     * then tomorrow's — that second check is what lets a late-evening window
     * reach across midnight.
     */
    private function forToday(Collection $users): Collection
    {
        $now = Carbon::now();
        $windowEnd = $now->copy()->addHours(self::WINDOW_HOURS);
        $tomorrow = $now->copy()->addDay();

        $upcoming = $users->map(function (User $user) use ($now, $windowEnd, $tomorrow): ?User {
            foreach ([$now, $tomorrow] as $candidateDate) {
                $schedule = $this->schedules->resolve($user->id, $candidateDate);

                if ($this->startsInWindow($schedule, $now, $windowEnd)) {
                    return $this->decorate($user, $candidateDate, $schedule);
                }
            }

            return null;
        })->filter()->values();

        return $this->sortByShiftStart($upcoming);
    }

    private function forFutureDate(CarbonInterface $date, Collection $users): Collection
    {
        $upcoming = $users->map(function (User $user) use ($date): ?User {
            $schedule = $this->schedules->resolve($user->id, $date);

            return $schedule->isWorkingDay ? $this->decorate($user, $date, $schedule) : null;
        })->filter()->values();

        return $this->sortByShiftStart($upcoming);
    }

    private function startsInWindow(ShiftSchedule $schedule, Carbon $now, Carbon $windowEnd): bool
    {
        return $schedule->isWorkingDay
            && $schedule->start->gte($now)
            && $schedule->start->lte($windowEnd);
    }

    /**
     * Clone the user and attach the shift attributes the serializers read.
     * The roster shift (code/name/colour) is preferred; the resolved schedule
     * is the fallback when no Shift row backs the day.
     */
    public function decorate(User $user, CarbonInterface $shiftDate, ShiftSchedule $schedule): User
    {
        $decorated = clone $user;
        $shift = $this->roster->resolveShift($user->id, $shiftDate);

        if ($shift) {
            $start = Carbon::parse($shift->start_time);
            $decorated->shift_code = $shift->code;
            $decorated->shift_name = $shift->name;
            $decorated->shift_color = $shift->color;
            $decorated->shift_start = $start->format('g:i A');
            $decorated->shift_end = Carbon::parse($shift->end_time)->format('g:i A');
            $decorated->shift_start_minutes = $start->hour * 60 + $start->minute;
        } else {
            $decorated->shift_code = null;
            $decorated->shift_name = null;
            $decorated->shift_color = null;
            $decorated->shift_start = $schedule->start->format('g:i A');
            $decorated->shift_end = $schedule->end->format('g:i A');
            $decorated->shift_start_minutes = $schedule->start->hour * 60 + $schedule->start->minute;
        }

        $decorated->shift_start_time = $decorated->shift_start;

        return $decorated;
    }

    /**
     * Shift start clock time ascending (00-08 → 08-16 → 16-24), then name.
     * A midnight-crossing shift sorts by its START. Users with no resolvable
     * shift sort last.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public function sortByShiftStart(Collection $users): Collection
    {
        return $users
            ->sortBy([
                fn (User $user) => $user->shift_start_minutes ?? PHP_INT_MAX,
                fn (User $user) => (string) $user->name,
            ])
            ->values();
    }
}
