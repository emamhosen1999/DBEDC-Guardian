<?php

namespace App\Services\Leave;

use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use Carbon\Carbon;

class LeaveOverlapService
{
    /**
     * Check for overlapping leaves for a user
     */
    public function checkOverlappingLeaves(int $userId, Carbon $fromDate, Carbon $toDate, ?int $excludeLeaveId = null): array
    {
        $query = Leave::with('employee')
            ->join('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
            ->select('leaves.*', 'leave_settings.type as leave_type')
            ->where('leaves.user_id', $userId)
            ->where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('from_date', [$fromDate, $toDate])
                    ->orWhereBetween('to_date', [$fromDate, $toDate])
                    ->orWhere(function ($q) use ($fromDate, $toDate) {
                        $q->where('from_date', '<=', $fromDate)
                            ->where('to_date', '>=', $toDate);
                    });
            });

        if ($excludeLeaveId) {
            $query->where('leaves.id', '!=', $excludeLeaveId);
        }

        $overlapping = $query->get();

        if ($overlapping->isEmpty()) {
            return [];
        }

        return $overlapping->map(function ($leave) {
            // Convert string dates to Carbon instances if they're not already
            $fromDate = $leave->from_date instanceof Carbon ? $leave->from_date : new Carbon($leave->from_date);
            $toDate = $leave->to_date instanceof Carbon ? $leave->to_date : new Carbon($leave->to_date);

            return $fromDate->equalTo($toDate)
                ? "\"{$leave->leave_type}\" leave already exists for: ".$fromDate->format('Y-m-d')
                : "\"{$leave->leave_type}\" leave already exists from ".$fromDate->format('Y-m-d').' to '.$toDate->format('Y-m-d');
        })->toArray();
    }

    public function checkOverLappingHoliday(Carbon $fromDate, Carbon $toDate, ?int $excludeHolidayId = null): array
    {
        $query = Holiday::where(function ($q) use ($fromDate, $toDate) {
            $q->whereBetween('from_date', [$fromDate, $toDate])
                ->orWhereBetween('to_date', [$fromDate, $toDate])
                ->orWhere(function ($q) use ($fromDate, $toDate) {
                    $q->where('from_date', '<=', $fromDate)
                        ->where('to_date', '>=', $toDate);
                });
        });

        if ($excludeHolidayId) {
            $query->where('id', '!=', $excludeHolidayId);
        }

        $overlapping = $query->get();

        if ($overlapping->isEmpty()) {
            return [];
        }

        return $overlapping->map(function ($holiday) {
            // Convert string dates to Carbon instances if they're not already
            $fromDate = $holiday->from_date instanceof Carbon ? $holiday->from_date : new Carbon($holiday->from_date);
            $toDate = $holiday->to_date instanceof Carbon ? $holiday->to_date : new Carbon($holiday->to_date);

            return $fromDate->equalTo($toDate)
                ? "\"{$holiday->title}\" holiday on this date: ".$fromDate->format('Y-m-d')
                : "\"{$holiday->title}\" holiday on this dates: ".$fromDate->format('Y-m-d').' to '.$toDate->format('Y-m-d');
        })->toArray();
    }

    /**
     * Non-blocking team-conflict advisory: teammates (same department) with
     * pending/approved leave overlapping the requested range. Returned as
     * warnings — informs the approver, never blocks the request.
     */
    public function teamConflictWarnings(int $userId, Carbon $fromDate, Carbon $toDate): array
    {
        $departmentId = \App\Models\User::where('id', $userId)->value('department_id');
        if (! $departmentId) {
            return [];
        }

        $threshold = max(1, (int) config('leave.team_conflict_warn_threshold', 1));

        $conflicts = Leave::query()
            ->join('users', 'users.id', '=', 'leaves.user_id')
            ->where('users.department_id', $departmentId)
            ->where('leaves.user_id', '!=', $userId)
            ->whereIn('leaves.status', ['pending', 'approved'])
            ->where('leaves.from_date', '<=', $toDate)
            ->where('leaves.to_date', '>=', $fromDate)
            ->select('users.name', 'leaves.from_date', 'leaves.to_date', 'leaves.status')
            ->limit(10)
            ->get();

        if ($conflicts->count() < $threshold) {
            return [];
        }

        return $conflicts->map(function ($row) {
            $from = Carbon::parse($row->from_date)->format('Y-m-d');
            $to = Carbon::parse($row->to_date)->format('Y-m-d');
            $range = $from === $to ? $from : "{$from} to {$to}";

            return "{$row->name} also has {$row->status} leave ({$range}) in your team.";
        })->all();
    }

    /**
     * Check if there are any overlapping leaves and return error message
     */
    public function getOverlapErrorMessage(int $userId, Carbon $fromDate, Carbon $toDate, ?int $excludeLeaveId = null): ?string
    {
        $overlapsLeave = $this->checkOverlappingLeaves($userId, $fromDate, $toDate, $excludeLeaveId);
        $overlapsHoliday = $this->checkOverLappingHoliday($fromDate, $toDate, $excludeLeaveId);

        $overlaps = array_merge($overlapsLeave, $overlapsHoliday);

        return empty($overlaps) ? null : implode(', ', $overlaps);
    }
}
