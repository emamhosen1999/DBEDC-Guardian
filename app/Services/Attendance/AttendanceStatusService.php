<?php

namespace App\Services\Attendance;

use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pure, deterministic attendance status engine. No DB access.
 * Single source of truth for status / worked minutes / late / OT.
 */
class AttendanceStatusService
{
    public function resolve(
        Collection $punches,
        ShiftSchedule $shift,
        bool $isHoliday = false,
        bool $isOnLeave = false,
        ?CarbonInterface $now = null
    ): DayAttendance {
        $sorted = $punches
            ->filter(fn ($p) => $p->punchin !== null)
            ->sortBy(fn ($p) => Carbon::parse($p->punchin)->getTimestamp())
            ->values();

        $flags = [];
        $workedMinutes = 0;
        $isComplete = true;
        $firstIn = null;
        $lastOut = null;

        foreach ($sorted as $p) {
            $in = Carbon::parse($p->punchin);
            $firstIn ??= $in;

            if ($p->punchout === null) {
                $isComplete = false;
                $flags[] = 'missing_punch_out';

                continue;
            }

            $out = Carbon::parse($p->punchout);
            // Datetimes are authoritative — no addDay() heuristic.
            $workedMinutes += (int) round($in->diffInMinutes($out));
            $lastOut = $out;
        }

        $hasPunches = $sorted->isNotEmpty();

        // No punches: holiday > leave > weekend/off > absent.
        if (! $hasPunches) {
            $status = match (true) {
                $isHoliday => DayAttendance::HOLIDAY,
                $isOnLeave => DayAttendance::ON_LEAVE,
                ! $shift->isWorkingDay => DayAttendance::WEEKEND,
                default => DayAttendance::ABSENT,
            };

            return new DayAttendance(
                status: $status,
                worked_minutes: 0,
                late_minutes: 0,
                early_leave_minutes: 0,
                ot_minutes: 0,
                first_in: null,
                last_out: null,
                is_complete: true,
                flags: $flags,
            );
        }

        // Has punches → derive metrics.
        $lateMinutes = 0;
        $earlyLeaveMinutes = 0;
        $otMinutes = 0;

        if ($shift->isWorkingDay) {
            $lateThreshold = $shift->start->copy()->addMinutes($shift->graceInMinutes);
            if ($firstIn->greaterThan($lateThreshold)) {
                $lateMinutes = (int) round($lateThreshold->diffInMinutes($firstIn));
            }

            if ($lastOut !== null && $shift->graceOutMinutes >= 0) {
                $earlyThreshold = $shift->end->copy()->subMinutes($shift->graceOutMinutes);
                if ($lastOut->lessThan($earlyThreshold)) {
                    $earlyLeaveMinutes = (int) round($lastOut->diffInMinutes($earlyThreshold));
                }
            }

            if ($shift->fullDayMinutes > 0 && $workedMinutes > $shift->fullDayMinutes) {
                $otMinutes = $workedMinutes - $shift->fullDayMinutes;
            }
        }

        // Status precedence among present-day outcomes.
        $status = DayAttendance::PRESENT;

        if ($shift->minPresentMinutes > 0 && $workedMinutes < $shift->minPresentMinutes) {
            $status = DayAttendance::SHORT;
        } elseif ($shift->halfDayMinutes > 0 && $workedMinutes < $shift->halfDayMinutes) {
            $status = DayAttendance::HALF_DAY;
        } elseif ($lateMinutes > 0) {
            $status = DayAttendance::LATE;
        }

        return new DayAttendance(
            status: $status,
            worked_minutes: $workedMinutes,
            late_minutes: $lateMinutes,
            early_leave_minutes: $earlyLeaveMinutes,
            ot_minutes: $otMinutes,
            first_in: $firstIn,
            last_out: $lastOut,
            is_complete: $isComplete,
            flags: array_values(array_unique($flags)),
        );
    }
}
