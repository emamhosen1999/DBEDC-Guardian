<?php

namespace App\Services\Attendance;

use App\Models\HRM\LeaveSetting;
use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Services\Attendance\Contracts\ReplacementOffSource;
use App\Support\Attendance\AttendanceSymbol;
use App\Support\Attendance\RegisterAvatar;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Collects the data of the attendance register for ANY date range, grouped by
 * department (one register per department). The scope (which employees) is decided
 * by the caller from DepartmentScope; this service never widens it.
 */
class AttendanceRegisterService
{
    public const MAX_DAYS_EXCEL = 366;

    public const MAX_DAYS_PDF = 93;

    public function __construct(
        private readonly AttendanceReportService $reports,
        private readonly ?RegisterAvatar $avatars = null,
        private readonly ?ReplacementOffSource $replacements = null,
    ) {}

    /**
     * A department filter is honoured only when the requester can see someone in it. A department outside the
     * requester's scope is dropped (null), so its code and name never reach a filename, a sheet, a page or a title.
     *
     * @param  array<int, string>|null  $employeeIds  actor scope (DepartmentScope::visibleEmployeeIds); null = unrestricted
     */
    public static function visibleDepartment(?int $departmentId, ?array $employeeIds): ?int
    {
        if ($departmentId === null || $employeeIds === null) {
            return $departmentId;
        }

        return $employeeIds !== [] && User::query()->whereIn('employee_id', $employeeIds)->where('department_id', $departmentId)->exists() ? $departmentId : null;
    }

    /** The payroll cycle containing $on: the 26th of the previous month to the 25th. */
    public static function payrollCycle(?CarbonInterface $on = null): array
    {
        $on = ($on ? Carbon::parse($on->toDateString()) : Carbon::today());
        $start = $on->day >= 26
            ? $on->copy()->startOfMonth()->addDays(25)
            : $on->copy()->subMonthNoOverflow()->startOfMonth()->addDays(25);

        return [$start, $start->copy()->addMonthNoOverflow()->startOfMonth()->addDays(24)];
    }

    /**
     * @param  array<int, string>|null  $employeeIds  actor scope; null = unrestricted
     * @return array{
     *   from: Carbon, to: Carbon, festivals: list<string>,
     *   departments: list<array{id: ?int, code: string, name: string, employees: list<array{id: string, name: string, avatar: ?string, replacements: list<array{off: string, worked: string}>, days: array<string, array{symbol: string, festival: bool, marks: array<string, mixed>}>}>}>
     * }
     */
    public function collect(CarbonInterface $from, CarbonInterface $to, ?array $employeeIds = null, ?int $departmentId = null): array
    {
        $from = Carbon::parse($from->toDateString())->startOfDay();
        $to = Carbon::parse($to->toDateString())->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $leaveTypes = LeaveSetting::all();
        $festivals = [];
        $byDept = [];

        for ($cursor = $from->copy()->startOfMonth(); $cursor->lte($to); $cursor->addMonthNoOverflow()->startOfMonth()) {
            $year = $cursor->year;
            $month = $cursor->month;
            $holidays = $this->reports->getHolidaysForMonth($year, $month);
            $users = $this->reports->getEmployeeUsersWithAttendanceAndLeaves($year, $month, $departmentId, null, null, null, $employeeIds);

            // One query for the month's overtime applications of everyone listed (not one per employee).
            $overtime = OvertimeRequest::query()
                ->whereIn('user_id', $users->pluck('id')->all())
                ->whereBetween('date', [$cursor->copy()->startOfMonth()->toDateString(), $cursor->copy()->endOfMonth()->toDateString()])
                ->get()->groupBy('user_id');

            foreach ($users as $user) {
                $data = $this->reports->getUserAttendanceData($user, $year, $month, $holidays, $leaveTypes, $overtime->get($user->id, collect()));
                $deptKey = $user->department_id ?: 0;
                $byDept[$deptKey]['id'] = $user->department_id ?: null;
                $byDept[$deptKey]['code'] = $user->department?->code ?: ($user->department?->name ?: 'UNASSIGNED');
                $byDept[$deptKey]['name'] = $user->department?->name ?: 'No Department';
                $emp = &$byDept[$deptKey]['employees'][$user->id];
                $emp ??= ['id' => (string) $user->id, 'name' => (string) $user->name, 'avatar' => ($this->avatars ?? new RegisterAvatar)->pathFor($user), 'days' => []];

                foreach ($data as $date => $day) {
                    if (! is_array($day) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                        continue;
                    }
                    if ($date < $from->toDateString() || $date > $to->toDateString()) {
                        continue;
                    }
                    $festival = (bool) ($day['is_festival'] ?? false);
                    if ($festival) {
                        $festivals[$date] = true;
                    }
                    $emp['days'][$date] = [
                        'symbol' => (string) ($day['symbol'] ?? AttendanceSymbol::NOT_YET),
                        'festival' => $festival,
                        'marks' => $day['marks'] ?? AttendanceSymbol::marks((string) ($day['symbol'] ?? ''), $festival, false, null),
                    ];
                }
                unset($emp);
            }
        }

        // Replacement offs (a day off taken in lieu of a rest day worked): the Remark column's note. Until the
        // replacement ledger exists the bound source reports none.
        $ids = [];
        foreach ($byDept as $d) {
            foreach ($d['employees'] as $e) {
                $ids[] = $e['id'];
            }
        }
        $offs = ($this->replacements ?? app(ReplacementOffSource::class))->between($ids, $from, $to);
        foreach ($byDept as &$d) {
            foreach ($d['employees'] as &$e) {
                $e['replacements'] = $offs[$e['id']] ?? [];
            }
            unset($e);
        }
        unset($d);

        // Department order: code A-Z, "no department" last. Employees keep the loader's order.
        uasort($byDept, fn ($a, $b) => [$a['id'] === null, $a['code']] <=> [$b['id'] === null, $b['code']]);
        $departments = array_values(array_map(function ($d) {
            $d['employees'] = array_values($d['employees']);

            return $d;
        }, $byDept));

        $festivalList = array_keys($festivals);
        sort($festivalList);

        return ['from' => $from, 'to' => $to, 'festivals' => $festivalList, 'departments' => $departments];
    }
}
