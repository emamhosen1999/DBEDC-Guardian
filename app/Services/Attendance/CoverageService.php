<?php

namespace App\Services\Attendance;

use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\Leave;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-side coverage aggregation: for each (location, shift, date) that has an
 * active requirement, resolve the effective required headcount and count the
 * effective assignments minus APPROVED leave. Attendance engine untouched.
 */
class CoverageService
{
    public function __construct(private readonly RosterOverlayService $overlay) {}

    /**
     * @param  array<int, string>|null  $employeeIds  actor scope (DepartmentScope::visibleEmployeeIds):
     *                                                null = every employee; a list counts only those employees
     */
    public function forRange(string $from, string $to, ?array $locationIds = null, ?array $employeeIds = null): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        $requirements = CoverageRequirement::query()
            ->where('is_active', true)
            ->when($locationIds, fn ($q) => $q->whereIn('work_location_id', $locationIds))
            ->get();

        if ($requirements->isEmpty()) {
            return [];
        }

        // Distinct (location, shift) pairs that have any requirement.
        $pairs = $requirements->map(fn ($r) => ['loc' => $r->work_location_id, 'shift' => $r->shift_id])
            ->unique(fn ($p) => $p['loc'].'-'.$p['shift'])->values();

        $assigned = $this->assignedWeights($start, $end, $locationIds, $employeeIds); // [date][loc][shift]['total'|desigId] => float

        $todayStr = now()->toDateString();
        $actualPunches = [];
        if ($start->toDateString() <= $todayStr) {
            $actualEnd = $end->toDateString() <= $todayStr ? $end->toDateString() : $todayStr;
            $actualPunches = $this->actualPunchWeights($start, Carbon::parse($actualEnd), $locationIds, $employeeIds);
        }

        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dateStr = $d->toDateString();
            $weekday = $d->dayOfWeek; // 0=Sun..6=Sat

            foreach ($pairs as $pair) {
                $loc = $pair['loc'];
                $shift = $pair['shift'];

                // TOTAL (designation_id null)
                $totalReq = $this->resolve($requirements, $loc, $shift, null, $dateStr, $weekday);
                $totalAssigned = (float) ($assigned[$dateStr][$loc][$shift]['total'] ?? 0.0);
                $totalActual = isset($actualPunches[$dateStr][$loc][$shift]['total'])
                    ? (float) $actualPunches[$dateStr][$loc][$shift]['total']
                    : null;

                $roles = [];
                foreach ($requirements->where('work_location_id', $loc)->where('shift_id', $shift)->whereNotNull('designation_id')->pluck('designation_id')->unique() as $desigId) {
                    $roleReq = $this->resolve($requirements, $loc, $shift, $desigId, $dateStr, $weekday);
                    if ($roleReq === null) {
                        continue;
                    }
                    $roleAssigned = (float) ($assigned[$dateStr][$loc][$shift][$desigId] ?? 0.0);
                    $roleActual = isset($actualPunches[$dateStr][$loc][$shift][$desigId])
                        ? (float) $actualPunches[$dateStr][$loc][$shift][$desigId]
                        : null;

                    $roles[$desigId] = [
                        'required' => $roleReq,
                        'assigned' => $roleAssigned,
                        'actual' => $roleActual,
                        'status' => $this->status($roleAssigned, $roleReq),
                    ];
                }

                // Skip cells with neither a total requirement nor any role requirement.
                if ($totalReq === null && empty($roles)) {
                    continue;
                }

                $out[$dateStr][$loc][$shift] = [
                    'total' => [
                        'required' => $totalReq,
                        'assigned' => $totalAssigned,
                        'actual' => $totalActual,
                        'status' => $totalReq === null ? null : $this->status($totalAssigned, $totalReq),
                    ],
                    'roles' => $roles,
                ];
            }
        }

        return $out;
    }

    /**
     * Evaluate coverage warning when a user is leaving/changing a shift.
     */
    public function evaluateWarning(int|string $userId, string $date, ?int $oldShiftId, ?int $newShiftId, ?int $locationId = null): ?string
    {
        if (! $oldShiftId || $oldShiftId === $newShiftId) {
            return null;
        }

        $locId = $locationId;
        if (! $locId) {
            $user = User::where('employee_id', (string) $userId)->first();
            $locId = $user?->work_location_id;
        }
        if (! $locId) {
            return null;
        }

        $coverage = $this->forRange($date, $date, [(int) $locId]);
        $cell = $coverage[$date][$locId][$oldShiftId]['total'] ?? null;
        if ($cell && isset($cell['required']) && $cell['required'] > 0) {
            $newAssigned = $cell['assigned'] - 1.0;
            if ($newAssigned < $cell['required']) {
                $shiftName = Shift::find($oldShiftId)?->name ?? "Shift #{$oldShiftId}";

                return "Coverage warning: Removing this shift leaves {$shiftName} on {$date} understaffed ({$newAssigned} of {$cell['required']} required).";
            }
        }

        return null;
    }

    /**
     * Check if approving a leave causes understaffing on any leave date.
     */
    public function checkCoverageForLeave(Leave $leave): ?string
    {
        $employee = $leave->employee;
        if (! $employee || ! $employee->work_location_id) {
            return null;
        }

        $from = Carbon::parse($leave->from_date);
        $to = Carbon::parse($leave->to_date);

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dateStr = $d->toDateString();
            $rosterDay = RosterDay::where('user_id', $employee->employee_id)->whereDate('date', $dateStr)->first();
            if ($rosterDay && $rosterDay->shift_id) {
                $warning = $this->evaluateWarning($employee->employee_id, $dateStr, $rosterDay->shift_id, null, $employee->work_location_id);
                if ($warning) {
                    return $warning;
                }
            }
        }

        return null;
    }

    /**
     * Check understaffing alert slots for a given date.
     */
    public function checkUnderstaffingAlert(string $date): array
    {
        $coverage = $this->forRange($date, $date);
        $understaffed = [];

        foreach ($coverage[$date] ?? [] as $locId => $shifts) {
            foreach ($shifts as $shiftId => $info) {
                if (($info['total']['status'] ?? null) === 'understaffed') {
                    $understaffed[] = [
                        'date' => $date,
                        'location_id' => $locId,
                        'shift_id' => $shiftId,
                        'required' => $info['total']['required'],
                        'assigned' => $info['total']['assigned'],
                    ];
                }
            }
        }

        return $understaffed;
    }

    private function actualPunchWeights(CarbonInterface $start, CarbonInterface $end, ?array $locationIds, ?array $employeeIds = null): array
    {
        $rows = DB::table('attendances')
            ->join('users', 'users.employee_id', '=', 'attendances.user_id')
            ->whereBetween('attendances.date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('attendances.punchin')
            ->where('attendances.policy_status', '!=', 'rejected')
            ->whereNotNull('attendances.shift_id')
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('attendances.user_id', $employeeIds === [] ? ['__NONE__'] : $employeeIds))
            ->select([
                'attendances.date',
                'attendances.shift_id',
                'attendances.user_id',
                DB::raw('COALESCE(attendances.work_location_id, users.work_location_id) as loc_id'),
                'users.designation_id',
            ])
            ->get();

        $rows = $rows->filter(fn ($r) => $r->loc_id !== null
            && ($locationIds === null || in_array((int) $r->loc_id, $locationIds, true)));

        $weights = [];
        foreach ($rows as $r) {
            $date = Carbon::parse($r->date)->toDateString();
            $loc = (int) $r->loc_id;
            $shift = (int) $r->shift_id;
            $weights[$date][$loc][$shift]['total'] = ($weights[$date][$loc][$shift]['total'] ?? 0.0) + 1.0;
            if ($r->designation_id !== null) {
                $d = (int) $r->designation_id;
                $weights[$date][$loc][$shift][$d] = ($weights[$date][$loc][$shift][$d] ?? 0.0) + 1.0;
            }
        }

        return $weights;
    }

    /**
     * Effective required headcount for (loc, shift, role, date) by precedence:
     * exact date > matching weekday (date null) > all-days (weekday null & date null).
     */
    private function resolve($requirements, int $loc, int $shift, ?int $desigId, string $dateStr, int $weekday): ?int
    {
        $scoped = $requirements->filter(fn ($r) => $r->work_location_id === $loc
            && $r->shift_id === $shift
            && $r->designation_id === $desigId);

        $exact = $scoped->firstWhere(fn ($r) => $r->date?->toDateString() === $dateStr);
        if ($exact) {
            return $exact->required_headcount;
        }

        $byWeekday = $scoped->first(fn ($r) => $r->date === null && $r->weekday === $weekday);
        if ($byWeekday) {
            return $byWeekday->required_headcount;
        }

        $allDays = $scoped->first(fn ($r) => $r->date === null && $r->weekday === null);

        return $allDays?->required_headcount;
    }

    /**
     * Effective assigned weight per date/loc/shift, keyed 'total' and by designation_id,
     * reduced by approved leave (full -1.0, half -0.5). Pending/holiday do not reduce.
     */
    private function assignedWeights(CarbonInterface $start, CarbonInterface $end, ?array $locationIds, ?array $employeeIds = null): array
    {
        $rows = RosterDay::query()
            ->join('users', 'users.employee_id', '=', 'roster_days.user_id')
            ->whereBetween('roster_days.date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('roster_days.shift_id')
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('roster_days.user_id', $employeeIds === [] ? ['__NONE__'] : $employeeIds))
            ->select([
                'roster_days.date',
                'roster_days.shift_id',
                'roster_days.user_id',
                DB::raw('COALESCE(roster_days.work_location_id, users.work_location_id) as loc_id'),
                'users.designation_id',
            ])
            ->get();

        $rows = $rows->filter(fn ($r) => $r->loc_id !== null
            && ($locationIds === null || in_array((int) $r->loc_id, $locationIds, true)));

        $userIds = $rows->pluck('user_id')->unique()->values()->all();
        $leave = empty($userIds)
            ? []
            : ($this->overlay->forRange($userIds, $start->toDateString(), $end->toDateString())['leave'] ?? []);

        $weights = [];
        foreach ($rows as $r) {
            $date = Carbon::parse($r->date)->toDateString();
            $lv = $leave[$r->user_id][$date] ?? null;
            $weight = 1.0;
            if ($lv && ($lv['status'] ?? null) === 'approved') {
                $weight = 1.0 - (float) $lv['fraction']; // full -> 0, half -> 0.5
            }
            if ($weight <= 0) {
                continue;
            }

            $loc = (int) $r->loc_id;
            $shift = (int) $r->shift_id;
            $weights[$date][$loc][$shift]['total'] = ($weights[$date][$loc][$shift]['total'] ?? 0.0) + $weight;
            if ($r->designation_id !== null) {
                $d = (int) $r->designation_id;
                $weights[$date][$loc][$shift][$d] = ($weights[$date][$loc][$shift][$d] ?? 0.0) + $weight;
            }
        }

        return $weights;
    }

    private function status(float $assigned, int $required): string
    {
        return match (true) {
            $assigned < $required => 'understaffed',
            $assigned > $required => 'overstaffed',
            default => 'met',
        };
    }
}
