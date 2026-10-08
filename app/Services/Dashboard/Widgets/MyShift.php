<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\RosterDay;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;

/**
 * The viewer's own rostered shift today and for the next seven days (roster_days:
 * manual > swap > pattern). Own rows only. One query for the roster; the handful of
 * shifts in use come with it through the relation.
 */
final class MyShift extends DashboardWidget
{
    private const UPCOMING_DAYS = 7;

    public function key(): string
    {
        return 'me.shift';
    }

    public function title(): string
    {
        return 'My shift';
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
        return 10;
    }

    public function span(): int
    {
        return 6;
    }

    public function type(): string
    {
        return 'list';
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
        $today = now()->startOfDay();
        $rows = RosterDay::query()
            ->where('user_id', $this->id($viewer))
            ->where('date', '>=', $today->toDateString())
            ->where('date', '<=', $today->copy()->addDays(self::UPCOMING_DAYS)->toDateString())
            ->with('shift:id,name,code')
            ->orderBy('date')
            ->orderByRaw("CASE source WHEN 'manual' THEN 0 WHEN 'swap' THEN 1 ELSE 2 END")
            ->get(['id', 'user_id', 'date', 'shift_id', 'source'])
            ->unique(fn (RosterDay $row) => $row->date->toDateString());

        $items = $rows->map(function (RosterDay $row) use ($today): array {
            $isToday = $row->date->isSameDay($today);
            $label = $row->shift_id === null ? 'Day off' : ($row->shift?->name ?? $row->shift?->code ?? 'Shift');

            return $this->item(
                $row->date->toDateString(),
                $label,
                $isToday ? 'Today' : $row->date->format('D, d M'),
                $row->source === 'swap' ? 'Swapped' : null,
                $row->shift_id === null ? 'neutral' : ($isToday ? 'info' : 'neutral'),
            );
        })->values()->all();

        $todayRow = $rows->first(fn (RosterDay $row) => $row->date->isSameDay($today));

        return [
            'scope' => ['kind' => 'self', 'label' => 'Only you'],
            'today' => $todayRow === null ? null : ($todayRow->shift_id === null ? 'Day off' : ($todayRow->shift?->name ?? 'Shift')),
            'items' => $items,
        ];
    }
}
