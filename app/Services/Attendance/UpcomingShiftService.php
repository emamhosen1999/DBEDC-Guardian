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
 *  - today   → shifts starting in [now, now + 24h]; the window crosses midnight,
 *              so at 22:00 tomorrow's 00-08 shift is upcoming.
 *  - future  → every working shift on that date (the whole day is ahead).
 *  - past    → nothing at all; the section is hidden, not rendered empty.
 */
class UpcomingShiftService
{
    public const WINDOW_HOURS = 24;

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
     * Strictly TODAY-based headcount for the summary band, mutually exclusive so
     * present + absent + off + upcoming == the whole team. Unlike partition(),
     * this never reaches into tomorrow: a working-day shift that has already
     * started with no punch is Absent even if the employee also has a shift
     * tomorrow (partition would resurrect them as "upcoming" via the 24h window).
     *
     * @param  Collection<int, User>  $nonPresentUsers  in-scope users with no punch-in for the date
     * @return array{absent: int, off: int, upcoming: int}
     */
    public function todaySummaryCounts(CarbonInterface $date, Collection $nonPresentUsers): array
    {
        $now = Carbon::now();
        $absent = 0;
        $off = 0;
        $upcoming = 0;

        foreach ($nonPresentUsers as $user) {
            $schedule = $this->schedules->resolve($user->id, $date);

            if (! $schedule->isWorkingDay) {
                $off++;

                continue;
            }

            // Working day today: not yet started → upcoming; already started and
            // never punched → absent.
            if ($schedule->start->gt($now)) {
                $upcoming++;
            } else {
                $absent++;
            }
        }

        return ['absent' => $absent, 'off' => $off, 'upcoming' => $upcoming];
    }

    /**
     * Shifts starting inside [now, now + 24h]. Today's shift is checked first,
     * then tomorrow's — that second check is what lets a late-evening window
     * reach across midnight.
     *
     * Asymmetry, deliberate: today's candidate is allowed to fall back to the
     * DefaultScheduleResolver's fabricated 09:00-17:00 day (that fallback is a
     * real obligation — it's what makes an unrostered employee count as absent
     * after 09:00). Tomorrow's candidate is NOT allowed that fallback: it only
     * counts when RosterService::resolveShift() proves the roster actually
     * assigned tomorrow a shift. Without that guard, an employee with no
     * roster row for tomorrow would get the fabricated 09:00 schedule, which
     * can fall inside the window and wrongly resurrect them as "upcoming" —
     * hiding an already-late employee from the absent list on a shift they
     * were never given.
     */
    private function forToday(Collection $users): Collection
    {
        $now = Carbon::now();
        $windowEnd = $now->copy()->addHours(self::WINDOW_HOURS);
        $tomorrow = $now->copy()->addDay();

        $upcoming = $users->map(function (User $user) use ($now, $windowEnd, $tomorrow): ?User {
            $todaySchedule = $this->schedules->resolve($user->id, $now);
            if ($this->startsInWindow($todaySchedule, $now, $windowEnd)) {
                return $this->decorate($user, $now, $todaySchedule);
            }

            // Tomorrow only counts against a REAL rostered/assigned shift — never
            // a fabricated default. A null return means no assignment or an
            // explicit off day; either way there is no upcoming shift tomorrow.
            if ($this->roster->resolveShift($user->id, $tomorrow) === null) {
                return null;
            }

            $tomorrowSchedule = $this->schedules->resolve($user->id, $tomorrow);
            if ($this->startsInWindow($tomorrowSchedule, $now, $windowEnd)) {
                return $this->decorate($user, $tomorrow, $tomorrowSchedule);
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

        // Absolute start moment, so a mixed today+tomorrow list sorts by how
        // soon each shift actually begins (tonight 23:00 before tomorrow 07:00),
        // not by clock time of day.
        $startClock = $shift ? Carbon::parse($shift->start_time) : $schedule->start;
        $decorated->shift_starts_at = $shiftDate->copy()->startOfDay()
            ->setTime($startClock->hour, $startClock->minute)
            ->getTimestamp();

        // The upcoming window spans today AND tomorrow, so a row like "[NGT] 11:00 PM"
        // is ambiguous without its date. Expose the shift's date plus a relative
        // label so the client can show "Today" / "Tomorrow" / a short date.
        $decorated->shift_date = $shiftDate->toDateString();
        $daysAhead = Carbon::now()->startOfDay()->diffInDays($shiftDate->copy()->startOfDay(), false);
        $decorated->shift_day_label = match (true) {
            $daysAhead <= 0 => 'Today',
            $daysAhead === 1 => 'Tomorrow',
            default => $shiftDate->format('D, M j'),
        };

        return $decorated;
    }

    /**
     * Soonest shift first: sorted by the shift's absolute start moment, so in a
     * mixed today+tomorrow window tonight's 23:00 precedes tomorrow's 07:00.
     * Falls back to clock-time-of-day for callers that never set the absolute
     * key; users with no resolvable shift sort last, ties break on name.
     *
     * NOTE: a single callback returning a tuple — an ARRAY of closures would hit
     * Collection::sortByMany, which invokes each closure as a two-arg comparator
     * and misreads a key extractor's return value as the comparison result.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public function sortByShiftStart(Collection $users): Collection
    {
        return $users
            ->sortBy(fn (User $user) => [
                $user->shift_starts_at ?? $user->shift_start_minutes ?? PHP_INT_MAX,
                (string) $user->name,
            ])
            ->values();
    }
}
