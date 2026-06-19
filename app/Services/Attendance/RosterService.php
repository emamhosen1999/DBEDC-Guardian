<?php

namespace App\Services\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonInterface;

class RosterService
{
    public function resolveShift(int $userId, CarbonInterface $date): ?Shift
    {
        $dateStr = $date->copy()->startOfDay()->toDateString();

        // 1. Manual override / approved swap materialized in roster_days wins.
        $rosterDay = RosterDay::where('user_id', $userId)
            ->whereDate('date', $dateStr)
            ->whereIn('source', ['manual', 'swap'])
            ->first();
        if ($rosterDay) {
            return $rosterDay->shift_id ? $rosterDay->shift : null; // null = off day
        }

        // 2. Effective-dated assignment, highest precedence scope first.
        $assignment = $this->resolveAssignment($userId, $date);
        if (! $assignment) {
            return null;
        }

        if ($assignment->shift_id) {
            return $assignment->shift;
        }

        // 3. Rotation pattern → phase by anchor_date.
        $pattern = $assignment->rotationPattern;
        if (! $pattern || empty($pattern->definition)) {
            return null;
        }

        $phase = $assignment->anchor_date->startOfDay()->diffInDays($date->copy()->startOfDay()) % $pattern->cycle_length_days;
        $entry = $pattern->definition[$phase] ?? 'off';

        return $entry === 'off' ? null : Shift::find($entry);
    }

    public function resolveAssignment(int $userId, CarbonInterface $date): ?ShiftAssignment
    {
        $user = User::find($userId);
        $dateStr = $date->copy()->startOfDay()->toDateString();

        // Precedence: user > designation > department > org.
        $scopes = [
            ['type' => 'user', 'id' => $userId],
            ['type' => 'designation', 'id' => $user?->designation_id],
            ['type' => 'department', 'id' => $user?->department_id],
            ['type' => 'org', 'id' => null],
        ];

        foreach ($scopes as $scope) {
            if ($scope['id'] === null && $scope['type'] !== 'org') {
                continue;
            }

            $query = ShiftAssignment::where('scope_type', $scope['type'])
                ->whereDate('effective_from', '<=', $dateStr)
                ->where(function ($q) use ($dateStr) {
                    $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dateStr);
                })
                ->orderByDesc('priority')
                ->orderByDesc('effective_from');

            $scope['id'] === null ? $query->whereNull('scope_id') : $query->where('scope_id', $scope['id']);

            $assignment = $query->first();
            if ($assignment) {
                return $assignment;
            }
        }

        return null;
    }
}
