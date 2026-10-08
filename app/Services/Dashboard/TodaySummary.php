<?php

namespace App\Services\Dashboard;

use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Today's attendance picture for a SET of employees, in a constant number of queries.
 *
 * It applies the same bucket rules as AttendanceDayPartitionService (the single source
 * of truth behind the web timesheet and mobile team-day): present > approved leave >
 * off (no shift, or a company holiday) > upcoming | absent. The partition service
 * resolves a schedule per employee (several queries each), which is right for a roster
 * page but would make a company-wide dashboard card grow with headcount; this class
 * reads the materialized roster (roster_days, manual > swap > pattern) in bulk instead.
 * tests/Feature/Dashboard/TodaySummaryParityTest pins the two to the same counts.
 *
 * The caller decides WHO is counted by passing a users query already narrowed through
 * DepartmentScope - this class never widens it.
 */
class TodaySummary
{
    public function __construct(private readonly HolidayService $holidays) {}

    /**
     * @param  Builder<User>  $people  query over users (already scoped by the caller)
     * @return array{total: int, present: int, late: int, absent: int, on_leave: int, upcoming: int, off: int, unrostered: int}
     */
    public function summarize(Builder $people, ?CarbonInterface $at = null): array
    {
        $now = $at ? Carbon::instance($at) : now();
        $day = $now->copy()->startOfDay();

        $total = (clone $people)->count();
        $empty = ['total' => $total, 'present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0, 'upcoming' => 0, 'off' => 0, 'unrostered' => 0];
        if ($total === 0) {
            return $empty;
        }

        $ids = (clone $people)->select('users.employee_id');
        $nextDay = $day->copy()->addDay()->toDateString();

        // First punch-in per member today (a manual check mark counts as present without a time).
        $punches = Attendance::query()->whereIn('user_id', $ids)
            ->where('date', '>=', $day->toDateString())->where('date', '<', $nextDay)
            ->where(fn (Builder $q) => $q->whereNull('policy_status')->orWhere('policy_status', '!=', 'rejected'))
            ->where(fn (Builder $q) => $q->whereNotNull('punchin')->orWhere('symbol', '√'))
            ->groupBy('user_id')
            ->selectRaw('user_id, MIN(punchin) as first_in')
            ->pluck('first_in', 'user_id')
            ->mapWithKeys(fn ($firstIn, $uid) => [(string) $uid => $firstIn])
            ->all();

        $leaveSet = array_flip(array_map('strval', Leave::query()->whereIn('user_id', $ids)
            ->where('from_date', '<', $nextDay)->where('to_date', '>=', $day->toDateString())
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->distinct()->pluck('user_id')->all()));

        $roster = RosterDay::query()->whereIn('user_id', $ids)
            ->where('date', '>=', $day->toDateString())->where('date', '<', $nextDay)
            ->orderByRaw("CASE source WHEN 'manual' THEN 0 WHEN 'swap' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get(['id', 'user_id', 'shift_id', 'source'])
            ->unique('user_id');

        $schedules = Shift::query()->with('versions')
            ->whereIn('id', $roster->pluck('shift_id')->filter()->unique()->values())
            ->get()
            ->mapWithKeys(fn (Shift $shift) => [$shift->id => $shift->toSchedule($day)]);

        $holiday = $this->holidays->onDate($day) !== null;

        $present = count($punches);
        $onLeave = count(array_diff_key($leaveSet, $punches));
        $late = $absent = $upcoming = $off = 0;

        foreach ($roster as $row) {
            $uid = (string) $row->user_id;
            $schedule = $row->shift_id ? ($schedules[$row->shift_id] ?? null) : null;

            // array_key_exists, not isset: a manual check-mark punch has a null first-in but is present.
            if (array_key_exists($uid, $punches)) {
                $firstIn = $punches[$uid];
                if ($schedule !== null && $firstIn !== null
                    && Carbon::parse($firstIn)->greaterThan($schedule->start->copy()->addMinutes($schedule->graceInMinutes))) {
                    $late++;
                }

                continue;
            }

            if (isset($leaveSet[$uid])) {
                continue;
            }

            if ($row->shift_id === null || $holiday) {
                $off++;
            } elseif ($schedule !== null) {
                $schedule->start->lessThanOrEqualTo($now) ? $absent++ : $upcoming++;
            }
        }

        $unrostered = max(0, $total - $present - $onLeave - $absent - $upcoming - $off);

        return compact('total', 'present', 'late', 'absent', 'upcoming', 'off', 'unrostered') + ['on_leave' => $onLeave];
    }
}
