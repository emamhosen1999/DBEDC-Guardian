<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The viewer's own punches today (or the approved leave that explains no punch).
 * Own records only — nothing here reaches another employee.
 */
final class MyAttendanceToday extends DashboardWidget
{
    public function key(): string
    {
        return 'me.attendance_today';
    }

    public function title(): string
    {
        return 'My attendance today';
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
        return 5;
    }

    public function span(): int
    {
        return 6;
    }

    public function ttl(): int
    {
        return 60;
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
        $day = now()->startOfDay();

        $punches = $this->onDay(Attendance::query()->where('user_id', $me), 'date', $day)
            ->where(fn (Builder $q) => $q->whereNull('policy_status')->orWhere('policy_status', '!=', 'rejected'))
            ->where(fn (Builder $q) => $q->whereNotNull('punchin')->orWhere('symbol', '√'))
            ->orderBy('punchin')
            ->get(['id', 'user_id', 'date', 'punchin', 'punchout']);

        $leave = null;
        if ($punches->isEmpty()) {
            $leave = $this->spans(Leave::query()->where('user_id', $me), 'from_date', 'to_date', $day)
                ->whereRaw('LOWER(status) = ?', ['approved'])
                ->with('leaveSetting:id,type')
                ->first(['id', 'leave_type', 'from_date', 'to_date', 'status']);
        }

        $firstIn = $punches->pluck('punchin')->filter()->first();
        $lastOut = $punches->pluck('punchout')->filter()->last();
        $open = $punches->isNotEmpty() && $punches->last()->punchin !== null && $punches->last()->punchout === null;

        [$status, $headline, $tone] = match (true) {
            $punches->isNotEmpty() && $open => ['checked_in', 'Checked in', 'good'],
            $punches->isNotEmpty() => ['checked_out', 'Checked out', 'good'],
            $leave !== null => ['on_leave', 'On approved leave', 'info'],
            default => ['not_punched', 'Not punched in yet', 'neutral'],
        };

        return [
            'date' => $day->toDateString(),
            'status' => $status,
            'headline' => $headline,
            'tone' => $tone,
            'leave' => $leave ? ['type' => $leave->leaveSetting?->type] : null,
            'stats' => [
                $this->stat('punch_in', 'Punch in', $firstIn?->format('H:i') ?? '—', 'neutral', 'attendance-employee', '/my-attendance'),
                $this->stat('punch_out', 'Punch out', $lastOut?->format('H:i') ?? '—', 'neutral', 'attendance-employee', '/my-attendance'),
            ],
        ];
    }
}
