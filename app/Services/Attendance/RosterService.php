<?php

namespace App\Services\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RosterService
{
    public function generateRoster(array $userIds, string $fromDate, string $toDate): int
    {
        $from = \Carbon\Carbon::parse($fromDate)->startOfDay();
        $to = \Carbon\Carbon::parse($toDate)->startOfDay();
        $written = 0;

        DB::transaction(function () use ($userIds, $from, $to, &$written) {
            foreach ($userIds as $userId) {
                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    $existing = RosterDay::where('user_id', $userId)->whereDate('date', $d->toDateString())->first();

                    // Never overwrite locked / manual / swap rows.
                    if ($existing && ($existing->locked || in_array($existing->source, ['manual', 'swap'], true))) {
                        continue;
                    }

                    $shift = $this->resolveShift($userId, $d);

                    RosterDay::updateOrCreate(
                        ['user_id' => $userId, 'date' => $d->toDateString()],
                        ['shift_id' => $shift?->id, 'source' => 'pattern'],
                    );
                    $written++;
                }
            }
        });

        return $written;
    }

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

    public function applySwap(ShiftSwapRequest $swap): void
    {
        if ($swap->status !== 'approved') {
            return;
        }

        DB::transaction(function () use ($swap) {
            $reqDate = $swap->requester_date->toDateString();
            $requesterShiftId = $this->effectiveShiftId($swap->requester_id, $reqDate);

            if ($swap->type === 'cover') {
                // Counterparty takes over the requester's shift; requester gets the day off.
                $this->writeSwapDay($swap->requester_id, $reqDate, null);
                $this->writeSwapDay($swap->counterparty_id, $reqDate, $requesterShiftId);

                return;
            }

            // swap: trade two specific rostered shifts (4-cell exchange).
            // Guard: an open/give-away swap has no counterparty_date; treat as requester-off only.
            if (! $swap->counterparty_date || ! $swap->counterparty_id) {
                $this->writeSwapDay($swap->requester_id, $reqDate, null);

                return;
            }

            $cpDate = $swap->counterparty_date->toDateString();
            $counterpartyShiftId = $this->effectiveShiftId($swap->counterparty_id, $cpDate);

            $this->writeSwapDay($swap->requester_id, $reqDate, null);
            $this->writeSwapDay($swap->counterparty_id, $reqDate, $requesterShiftId);
            $this->writeSwapDay($swap->counterparty_id, $cpDate, null);
            $this->writeSwapDay($swap->requester_id, $cpDate, $counterpartyShiftId);
        });
    }

    /**
     * The effective shift_id for a user on a date from the MATERIALIZED roster
     * (roster_days of ANY source) first, else the resolved assignment/pattern.
     * Unlike resolveShift(), this honors source=pattern rows — it is the truth
     * the employee sees and what swaps/covers operate on. null = off / no work.
     */
    public function effectiveShiftId(int $userId, string $date): ?int
    {
        $rosterDay = RosterDay::where('user_id', $userId)->whereDate('date', $date)->first();
        if ($rosterDay) {
            return $rosterDay->shift_id;
        }

        return $this->resolveShift($userId, \Carbon\Carbon::parse($date))?->id;
    }

    private function writeSwapDay(int $userId, string $date, ?int $shiftId): void
    {
        RosterDay::updateOrCreate(
            ['user_id' => $userId, 'date' => $date],
            ['shift_id' => $shiftId, 'source' => 'swap', 'locked' => true],
        );
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
