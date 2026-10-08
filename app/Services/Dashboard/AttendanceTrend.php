<?php

namespace App\Services\Dashboard;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daily attendance counts for a SET of employees over a window ending today - the series behind the dashboard's
 * sparklines and trend chart. The bucket rules are TodaySummary's (and so AttendanceDayPartitionService's), applied
 * per calendar day from the materialized roster (roster_days: manual > swap > pattern):
 *
 *   present   first punch-in (or a manual check mark) that day
 *   late      present, with a rostered shift whose start + grace passed before the first punch
 *   on_leave  approved leave covering the day and no punch
 *   absent    rostered to work, no punch, no leave, not a company holiday (today: only once the shift has started)
 *   rostered  rostered to work that day (the denominator for "present vs rostered")
 *
 * Constant number of queries whatever the headcount (punches, leaves, roster rows, the few shifts in use, holidays);
 * all classification is in memory. The caller narrows WHO through DepartmentScope; this class never widens it.
 */
class AttendanceTrend
{
    public function __construct(private readonly HolidayService $holidays) {}

    /**
     * @param  Builder<User>  $people  query over users, already scoped by the caller
     * @return array{from: string, to: string, dates: array<int, string>, present: array<int, int>, late: array<int, int>, absent: array<int, int>, on_leave: array<int, int>, rostered: array<int, int>}
     */
    public function forPeople(Builder $people, int $days = 30, ?CarbonInterface $at = null): array
    {
        $now = $at ? Carbon::instance($at) : now();
        $end = $now->copy()->startOfDay();
        $start = $end->copy()->subDays($days - 1);
        $dates = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $out = ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'dates' => $dates]
            + array_fill_keys(['present', 'late', 'absent', 'on_leave', 'rostered'], array_fill(0, count($dates), 0));

        $ids = (clone $people)->select('users.employee_id');
        if (! (clone $people)->exists()) {
            return $out;
        }
        $nextDay = $end->copy()->addDay()->toDateString();

        // First punch per (employee, day).
        $punches = [];
        foreach (DB::table('attendances')->whereIn('user_id', $ids)
            ->where('date', '>=', $start->toDateString())->where('date', '<', $nextDay)
            ->where(fn ($q) => $q->whereNull('policy_status')->orWhere('policy_status', '!=', 'rejected'))
            ->where(fn ($q) => $q->whereNotNull('punchin')->orWhere('symbol', '√'))
            ->groupBy('user_id', 'date')
            ->selectRaw('user_id, date, MIN(punchin) as first_in')
            ->get() as $row) {
            $punches[substr((string) $row->date, 0, 10)][(string) $row->user_id] = $row->first_in;
        }

        // Approved leave, expanded to days inside the window.
        $leave = [];
        foreach (DB::table('leaves')->whereIn('user_id', $ids)->whereRaw('LOWER(status) = ?', ['approved'])
            ->where('from_date', '<', $nextDay)->where('to_date', '>=', $start->toDateString())
            ->get(['user_id', 'from_date', 'to_date']) as $row) {
            $from = Carbon::parse($row->from_date)->startOfDay()->max($start);
            $to = Carbon::parse($row->to_date)->startOfDay()->min($end);
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $leave[$d->toDateString()][(string) $row->user_id] = true;
            }
        }

        // Effective roster row per (employee, day).
        $roster = RosterDay::query()->whereIn('user_id', $ids)
            ->where('date', '>=', $start->toDateString())->where('date', '<', $nextDay)
            ->orderByRaw("CASE source WHEN 'manual' THEN 0 WHEN 'swap' THEN 1 ELSE 2 END")->orderBy('id')
            ->get(['id', 'user_id', 'date', 'shift_id', 'source']);
        $effective = [];
        foreach ($roster as $row) {
            $effective[$row->date->toDateString()][(string) $row->user_id] ??= $row->shift_id;
        }

        $shifts = Shift::query()->with('versions')->whereIn('id', $roster->pluck('shift_id')->filter()->unique()->values())->get()->keyBy('id');

        $holidayDays = [];
        foreach ($this->holidays->forRange($start, $end) as $holiday) {
            $from = Carbon::parse($holiday->from_date)->startOfDay()->max($start);
            $to = Carbon::parse($holiday->to_date)->startOfDay()->min($end);
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $holidayDays[$d->toDateString()] = true;
            }
        }

        foreach ($dates as $i => $date) {
            $day = Carbon::parse($date);
            $punched = $punches[$date] ?? [];
            $rosteredToday = array_filter($effective[$date] ?? [], fn ($shiftId) => $shiftId !== null);
            $onLeave = array_diff_key($leave[$date] ?? [], $punched);
            $starts = [];

            $late = 0;
            foreach ($rosteredToday as $uid => $shiftId) {
                $schedule = isset($shifts[$shiftId]) ? ($starts[$shiftId] ??= $shifts[$shiftId]->toSchedule($day)) : null;
                if (array_key_exists($uid, $punched)) {
                    if ($schedule !== null && $punched[$uid] !== null
                        && Carbon::parse($punched[$uid])->greaterThan($schedule->start->copy()->addMinutes($schedule->graceInMinutes))) {
                        $late++;
                    }

                    continue;
                }
                if (isset($leave[$date][$uid]) || isset($holidayDays[$date]) || $schedule === null) {
                    continue;
                }
                if ($date < $end->toDateString() || $schedule->start->lessThanOrEqualTo($now)) {
                    $out['absent'][$i]++;
                }
            }

            $out['present'][$i] = count($punched);
            $out['late'][$i] = $late;
            $out['on_leave'][$i] = count($onLeave);
            $out['rostered'][$i] = count($rosteredToday);
        }

        return $out;
    }
}
