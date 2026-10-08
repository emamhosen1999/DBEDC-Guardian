<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\Holiday;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;

/**
 * The next company holidays. Company-wide public information (not employee-owned), shown
 * to anyone with self-service access; deleted or inactive holidays never appear.
 */
final class UpcomingHolidays extends DashboardWidget
{
    public function key(): string
    {
        return 'me.holidays';
    }

    public function title(): string
    {
        return 'Upcoming holidays';
    }

    public function permissions(): array
    {
        return ['holidays.view', 'leave.own.view', 'attendance.own.view'];
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
        return 31;
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
        return 3600;
    }

    public function route(User $viewer): ?string
    {
        return $viewer->can('holidays.view') ? 'holidays' : 'leaves-employee';
    }

    public function mobileRoute(): ?string
    {
        return '/leaves';
    }

    public function data(User $viewer): array
    {
        $today = now()->startOfDay();

        $items = Holiday::query()
            ->active()
            ->where('to_date', '>=', $today->toDateString())
            ->orderBy('from_date')
            ->orderBy('id')
            ->limit(3)
            ->get(['id', 'title', 'from_date', 'to_date', 'type'])
            ->map(function (Holiday $holiday) use ($today, $viewer): array {
                $from = $holiday->from_date->copy()->startOfDay();
                $to = $holiday->to_date->copy()->startOfDay();
                $inDays = max(0, (int) $today->diffInDays($from, false));
                $span = $from->equalTo($to) ? $from->format('D, j M') : $from->format('j M').' – '.$to->format('j M');

                return $this->item(
                    (string) $holiday->id,
                    (string) $holiday->title,
                    $span,
                    $from->lessThanOrEqualTo($today) ? 'on now' : ($inDays === 1 ? 'tomorrow' : "in {$inDays} days"),
                    $from->lessThanOrEqualTo($today) ? 'good' : 'neutral',
                    $this->route($viewer),
                );
            })
            ->all();

        return ['items' => $items];
    }
}
