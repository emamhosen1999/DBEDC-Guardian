<?php

namespace App\Services\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Evaluates a user's rostered shift sequence against the configurable
 * working-time compliance rules in config('attendance.compliance'): minimum
 * inter-shift rest, maximum scheduled hours in any rolling 24h window,
 * maximum consecutive night shifts, maximum consecutive working days, and
 * maximum weekly scheduled hours.
 *
 * This is a WARN-FIRST engine: it only computes and returns violations.
 * Callers decide whether to merely surface them or, when
 * config('attendance.compliance.enforce') is true, block the action for a
 * severity=error violation.
 */
class WorkTimeComplianceService
{
    /**
     * Evaluate a user's MATERIALIZED roster (roster_days + shift) over a date
     * range, deriving the shift sequence the same way the roster grid does.
     *
     * @return array<int, array{date: string, rule: string, message: string, severity: string, details: array}>
     */
    public function evaluate(int $userId, string $fromDate, string $toDate): array
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();

        $rows = RosterDay::with('shift')
            ->where('user_id', $userId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();

        $days = $rows->map(fn (RosterDay $row) => [
            'date' => $row->date->toDateString(),
            'shift' => $row->shift,
        ])->all();

        return $this->evaluateSequence($days);
    }

    /**
     * Pure variant for tests / simulated (not-yet-persisted) sequences.
     * Each entry: ['date' => 'Y-m-d'|CarbonInterface, 'shift' => Shift|null].
     * Multiple entries MAY share the same date (e.g. a day shift plus a
     * manually overridden night shift on the same calendar day) to model an
     * actual double-booking; both are evaluated as separate scheduled
     * instances.
     *
     * @param  array<int, array{date: string|CarbonInterface, shift: Shift|null}>  $days
     * @return array<int, array{date: string, rule: string, message: string, severity: string, details: array}>
     */
    public function evaluateSequence(array $days): array
    {
        $entries = array_map(fn ($d) => [
            'date' => $d['date'] instanceof CarbonInterface
                ? $d['date']->toDateString()
                : Carbon::parse($d['date'])->toDateString(),
            'shift' => $d['shift'] ?? null,
        ], $days);

        usort($entries, fn ($a, $b) => $a['date'] <=> $b['date']);

        $violations = [];

        $this->checkMinRest($entries, $violations);
        $this->checkMaxSpanIn24h($entries, $violations);
        $this->checkConsecutiveNights($entries, $violations);
        $this->checkConsecutiveWorkingDays($entries, $violations);
        $this->checkMaxWeeklyHours($entries, $violations);

        usort($violations, fn ($a, $b) => [$a['date'], $a['rule']] <=> [$b['date'], $b['rule']]);

        return $violations;
    }

    /**
     * Real scheduled shift instances (start/end datetimes), sorted by start.
     *
     * @return array<int, array{date: string, start: Carbon, end: Carbon, shift: Shift}>
     */
    private function scheduledInstances(array $entries): array
    {
        $instances = [];

        foreach ($entries as $entry) {
            if (! $entry['shift']) {
                continue;
            }

            $schedule = $entry['shift']->toSchedule(Carbon::parse($entry['date']));
            $instances[] = [
                'date' => $entry['date'],
                'start' => $schedule->start,
                'end' => $schedule->end,
                'shift' => $entry['shift'],
            ];
        }

        usort($instances, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        return $instances;
    }

    private function isNightShift(Shift $shift): bool
    {
        return (bool) $shift->crosses_midnight || Carbon::parse($shift->start_time)->hour >= 21;
    }

    /**
     * Rule 1: gap between one shift's end and the next shift's start must be
     * >= min_rest_hours. Flags the date of the SECOND (under-rested) shift.
     */
    private function checkMinRest(array $entries, array &$violations): void
    {
        $minRestHours = (float) config('attendance.compliance.min_rest_hours', 11);
        if ($minRestHours <= 0) {
            return;
        }

        $instances = $this->scheduledInstances($entries);

        for ($i = 1; $i < count($instances); $i++) {
            $prev = $instances[$i - 1];
            $curr = $instances[$i];
            $restHours = ($curr['start']->getTimestamp() - $prev['end']->getTimestamp()) / 3600;

            if ($restHours < $minRestHours) {
                $violations[] = [
                    'date' => $curr['date'],
                    'rule' => 'min_rest',
                    'message' => sprintf(
                        'Only %.1fh rest before this shift (minimum %.1fh required).',
                        max($restHours, 0),
                        $minRestHours,
                    ),
                    'severity' => 'error',
                    'details' => [
                        'rest_hours' => round($restHours, 2),
                        'min_rest_hours' => $minRestHours,
                        'previous_shift_end' => $prev['end']->toIso8601String(),
                        'next_shift_start' => $curr['start']->toIso8601String(),
                    ],
                ];
            }
        }
    }

    /**
     * Rule 2: total scheduled hours inside ANY rolling 24h window must not
     * exceed max_span_in_24h_hours. Implemented by anchoring a candidate
     * window at each scheduled instance's start (the maximum can only occur
     * starting at one of those points) and summing overlapping duration.
     */
    private function checkMaxSpanIn24h(array $entries, array &$violations): void
    {
        $maxSpanHours = (float) config('attendance.compliance.max_span_in_24h_hours', 16);
        if ($maxSpanHours <= 0) {
            return;
        }

        $instances = $this->scheduledInstances($entries);
        $flagged = [];

        foreach ($instances as $anchor) {
            $windowStart = $anchor['start'];
            $windowEnd = $windowStart->copy()->addHours(24);
            $totalSeconds = 0;

            foreach ($instances as $other) {
                $start = max($other['start']->getTimestamp(), $windowStart->getTimestamp());
                $end = min($other['end']->getTimestamp(), $windowEnd->getTimestamp());
                if ($end > $start) {
                    $totalSeconds += $end - $start;
                }
            }

            $totalHours = $totalSeconds / 3600;

            if ($totalHours > $maxSpanHours && ! isset($flagged[$anchor['date']])) {
                $flagged[$anchor['date']] = true;
                $violations[] = [
                    'date' => $anchor['date'],
                    'rule' => 'max_span_in_24h',
                    'message' => sprintf(
                        '%.1fh scheduled within a rolling 24h window starting %s (maximum %.1fh).',
                        $totalHours,
                        $windowStart->format('Y-m-d H:i'),
                        $maxSpanHours,
                    ),
                    'severity' => 'error',
                    'details' => [
                        'window_start' => $windowStart->toIso8601String(),
                        'window_end' => $windowEnd->toIso8601String(),
                        'total_hours' => round($totalHours, 2),
                        'max_hours' => $maxSpanHours,
                    ],
                ];
            }
        }
    }

    /**
     * @return array<string, array<int, Shift|null>> date => list of shifts scheduled that calendar day
     */
    private function groupByDate(array $entries): array
    {
        $byDate = [];
        foreach ($entries as $entry) {
            $byDate[$entry['date']][] = $entry['shift'];
        }
        ksort($byDate);

        return $byDate;
    }

    /**
     * Rule 3: consecutive calendar days rostered onto a "night" shift
     * (crosses midnight OR starts >= 21:00) must not exceed max_consecutive_nights.
     */
    private function checkConsecutiveNights(array $entries, array &$violations): void
    {
        $maxNights = (int) config('attendance.compliance.max_consecutive_nights', 4);
        if ($maxNights <= 0) {
            return;
        }

        $streak = 0;
        $prevDate = null;

        foreach ($this->groupByDate($entries) as $date => $shifts) {
            $isNight = false;
            foreach (array_filter($shifts) as $shift) {
                if ($this->isNightShift($shift)) {
                    $isNight = true;
                    break;
                }
            }

            $contiguous = $prevDate !== null && Carbon::parse($prevDate)->addDay()->toDateString() === $date;
            $streak = $isNight ? ($contiguous ? $streak + 1 : 1) : 0;

            if ($isNight && $streak > $maxNights) {
                $violations[] = [
                    'date' => $date,
                    'rule' => 'max_consecutive_nights',
                    'message' => sprintf('%d consecutive night shifts scheduled (maximum %d).', $streak, $maxNights),
                    'severity' => 'warning',
                    'details' => ['streak' => $streak, 'max_consecutive_nights' => $maxNights],
                ];
            }

            $prevDate = $date;
        }
    }

    /**
     * Rule 4: consecutive calendar days rostered onto ANY shift must not
     * exceed max_consecutive_working_days.
     */
    private function checkConsecutiveWorkingDays(array $entries, array &$violations): void
    {
        $maxWorkingDays = (int) config('attendance.compliance.max_consecutive_working_days', 7);
        if ($maxWorkingDays <= 0) {
            return;
        }

        $streak = 0;
        $prevDate = null;

        foreach ($this->groupByDate($entries) as $date => $shifts) {
            $isWorking = count(array_filter($shifts)) > 0;
            $contiguous = $prevDate !== null && Carbon::parse($prevDate)->addDay()->toDateString() === $date;
            $streak = $isWorking ? ($contiguous ? $streak + 1 : 1) : 0;

            if ($isWorking && $streak > $maxWorkingDays) {
                $violations[] = [
                    'date' => $date,
                    'rule' => 'max_consecutive_working_days',
                    'message' => sprintf('%d consecutive working days scheduled (maximum %d).', $streak, $maxWorkingDays),
                    'severity' => 'warning',
                    'details' => ['streak' => $streak, 'max_consecutive_working_days' => $maxWorkingDays],
                ];
            }

            $prevDate = $date;
        }
    }

    /**
     * Rule 5: total scheduled hours within a single Monday-Sunday calendar
     * week must not exceed max_weekly_hours.
     */
    private function checkMaxWeeklyHours(array $entries, array &$violations): void
    {
        $maxWeeklyHours = (float) config('attendance.compliance.max_weekly_hours', 60);
        if ($maxWeeklyHours <= 0) {
            return;
        }

        $instances = $this->scheduledInstances($entries);
        $weeks = [];

        foreach ($instances as $instance) {
            $weekStart = Carbon::parse($instance['date'])->startOfWeek(Carbon::MONDAY)->toDateString();
            $durationHours = ($instance['end']->getTimestamp() - $instance['start']->getTimestamp()) / 3600;

            $weeks[$weekStart]['hours'] = ($weeks[$weekStart]['hours'] ?? 0) + $durationHours;
            $weeks[$weekStart]['last_date'] = max($weeks[$weekStart]['last_date'] ?? $instance['date'], $instance['date']);
        }

        foreach ($weeks as $weekStart => $info) {
            if ($info['hours'] > $maxWeeklyHours) {
                $violations[] = [
                    'date' => $info['last_date'],
                    'rule' => 'max_weekly_hours',
                    'message' => sprintf(
                        '%.1fh scheduled in the week of %s (maximum %.1fh).',
                        $info['hours'],
                        $weekStart,
                        $maxWeeklyHours,
                    ),
                    'severity' => 'warning',
                    'details' => [
                        'week_start' => $weekStart,
                        'total_hours' => round($info['hours'], 2),
                        'max_hours' => $maxWeeklyHours,
                    ],
                ];
            }
        }
    }
}
