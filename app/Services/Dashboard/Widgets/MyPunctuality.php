<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Dashboard\DashboardWidget;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * My arrival times against my shift start (last 14 days) and the hours I worked this week against my scheduled
 * hours. Own records only: one query for punches, one for roster rows, one for the few shifts in use.
 *
 * Arrival: minutes after (positive) or before (negative) the rostered shift start on days with a punch and a shift.
 * Hours: first punch-in to last punch-out per day (a day without a punch-out has no worked figure), against the
 * shift's full-day minutes. Days without a shift have no scheduled hours.
 */
final class MyPunctuality extends DashboardWidget
{
    private const DAYS = 14;

    public function key(): string
    {
        return 'me.punctuality';
    }

    public function title(): string
    {
        return 'Arrivals and hours';
    }

    public function permissions(): array
    {
        return ['attendance.own.view'];
    }

    public function personas(): array
    {
        return ['employee', 'line_manager', 'department_admin', 'hr_manager'];
    }

    public function section(): string
    {
        return self::SECTION_ME;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_EMPLOYEE;
    }

    public function priority(): int
    {
        return 21;
    }

    public function span(): int
    {
        return 6;
    }

    public function type(): string
    {
        return 'analytics';
    }

    public function ttl(): int
    {
        return 300;
    }

    public function route(User $viewer): ?string
    {
        return 'attendance-employee';
    }

    public function mobileRoute(): ?string
    {
        return '/my-attendance';
    }

    public function data(User $viewer): array
    {
        $me = $this->id($viewer);
        $today = now()->startOfDay();
        $weekStart = $today->copy()->startOfWeek();
        $from = $today->copy()->subDays(self::DAYS - 1)->min($weekStart);
        $nextDay = $today->copy()->addDay()->toDateString();

        $punches = [];
        foreach (DB::table('attendances')->where('user_id', $me)
            ->where('date', '>=', $from->toDateString())->where('date', '<', $nextDay)
            ->where(fn ($q) => $q->whereNull('policy_status')->orWhere('policy_status', '!=', 'rejected'))
            ->whereNotNull('punchin')
            ->groupBy('date')->selectRaw('date, MIN(punchin) as first_in, MAX(punchout) as last_out')->get() as $row) {
            $punches[substr((string) $row->date, 0, 10)] = $row;
        }

        $roster = RosterDay::query()->where('user_id', $me)
            ->where('date', '>=', $from->toDateString())->where('date', '<', $nextDay)
            ->orderByRaw("CASE source WHEN 'manual' THEN 0 WHEN 'swap' THEN 1 ELSE 2 END")->orderBy('id')
            ->get(['id', 'date', 'shift_id', 'source'])
            ->unique(fn (RosterDay $r) => $r->date->toDateString());
        $shifts = Shift::query()->with('versions')->whereIn('id', $roster->pluck('shift_id')->filter()->unique()->values())->get()->keyBy('id');
        $scheduleOn = function (string $date) use ($roster, $shifts): ?ShiftSchedule {
            $shiftId = $roster->first(fn (RosterDay $r) => $r->date->toDateString() === $date)?->shift_id;

            return $shiftId !== null && isset($shifts[$shiftId]) ? $shifts[$shiftId]->toSchedule(Carbon::parse($date)) : null;
        };

        // Arrival versus shift start.
        $labels = $arrival = [];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $day = $today->copy()->subDays($i);
            $date = $day->toDateString();
            $labels[] = $day->format('d M');
            $schedule = $scheduleOn($date);
            $arrival[] = isset($punches[$date]) && $schedule !== null
                ? (int) round(($schedule->start->diffInMinutes(Carbon::parse($punches[$date]->first_in), false)))
                : null;
        }

        // This week: worked versus scheduled hours.
        $weekLabels = $worked = $scheduled = [];
        for ($d = $weekStart->copy(); $d->lte($weekStart->copy()->addDays(6)); $d->addDay()) {
            $date = $d->toDateString();
            $weekLabels[] = $d->format('D');
            $schedule = $scheduleOn($date);
            $scheduled[] = $schedule !== null && $schedule->isWorkingDay ? round($schedule->fullDayMinutes / 60, 1) : 0;
            $row = $punches[$date] ?? null;
            $worked[] = $d->lte($today) && $row !== null && $row->last_out !== null
                ? round(max(0, Carbon::parse($row->first_in)->diffInMinutes(Carbon::parse($row->last_out))) / 60, 1)
                : null;
        }

        $late = count(array_filter($arrival, fn ($m) => $m !== null && $m > 0));
        $counted = count(array_filter($arrival, fn ($m) => $m !== null));

        return [
            'scope' => ['kind' => 'self', 'label' => 'Only you'],
            'charts' => [
                $this->chart('arrivals', 'line', 'Arrival vs shift start',
                    $counted > 0 ? "Minutes after (+) or before (-) shift start, last 14 days; {$late} of {$counted} days after the start" : 'No arrivals with a rostered shift in the last 14 days',
                    ['categories' => $labels, 'unit' => 'min', 'baseline' => 0, 'series' => [['name' => 'Minutes vs shift start', 'tone' => 'theme', 'data' => $arrival]]],
                    ['from' => $today->copy()->subDays(self::DAYS - 1)->toDateString(), 'to' => $today->toDateString()], 'No punches against a rostered shift in this period.'),
                $this->chart('week_hours', 'bar', 'Hours this week',
                    'Hours worked against scheduled hours, Monday to Sunday of this week',
                    ['categories' => $weekLabels, 'unit' => 'h', 'series' => [
                        ['name' => 'Worked', 'tone' => 'good', 'data' => $worked],
                        ['name' => 'Scheduled', 'tone' => 'muted', 'data' => $scheduled],
                    ]], ['from' => $weekStart->toDateString(), 'to' => $weekStart->copy()->addDays(6)->toDateString()], 'No hours recorded this week.'),
            ],
        ];
    }
}
