<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE single source of truth for a day's attendance partition.
 *
 * Both the WEB daily timesheet (/attendance/day-partition) and the MOBILE
 * team-attendance screen (/api/v1/attendance/team-day) render from this one
 * definition, so the two can never drift into showing different numbers again.
 *
 * Every in-scope member lands in EXACTLY ONE bucket, so the four bucket counts
 * always sum to the total:
 *
 *   present   — has a punch-in (or a manual √) for the date.
 *   off_leave — no working shift that day (kind=off), OR on APPROVED leave
 *               (kind=leave, carrying the leave-type label).
 *   upcoming  — rostered a working shift whose start has NOT passed yet.
 *               Necessarily empty for past dates (their starts are all behind now).
 *   absent    — rostered a working shift whose start HAS passed, with no punch-in.
 *
 * Priority when a member could match several rules: present > leave > off >
 * (upcoming | absent). Leave outranks absent so someone on approved leave is
 * never counted absent.
 *
 * Timezone: everything is evaluated in config('app.timezone') (Asia/Dhaka).
 * Shift starts are date-anchored wall-clock moments in that zone, so a night
 * shift (e.g. start 23:00) is correctly "not yet passed" earlier the same day.
 */
class AttendanceDayPartitionService
{
    public function __construct(
        private readonly ScheduleResolver $schedules,
        private readonly RosterService $roster,
    ) {}

    /**
     * Partition a single day's attendance for every in-scope member.
     *
     * @param  string    $date          Y-m-d
     * @param  int|null  $departmentId  restrict to a department (null = all)
     * @param  int|null[] $memberIds    restrict to an explicit member set (e.g. a
     *                                  manager's team). null = no member filter.
     * @return array the frozen response shape
     */
    public function partition(string $date, ?int $departmentId = null, ?array $memberIds = null, ?int $designationId = null): array
    {
        $day = Carbon::parse($date, config('app.timezone'))->startOfDay();
        $dateStr = $day->toDateString();
        $now = Carbon::now(config('app.timezone'));

        $users = $this->inScopeUsers($departmentId, $memberIds, $designationId);
        $userIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();

        $attendanceByUser = $this->attendanceForDate($userIds, $dateStr);
        $leaveByUser = $this->approvedLeaveForDate($userIds, $dateStr);

        $present = collect();
        $absent = collect();
        $upcoming = collect();
        $offLeave = collect();

        foreach ($users as $user) {
            $uid = (int) $user->id;
            $records = $attendanceByUser->get($uid);
            $schedule = $this->schedules->resolve($uid, $day);

            // 1. Present — the member has a punch-in (or a manual √) for the date.
            if ($records && $records->isNotEmpty()) {
                $present->push($this->presentRow($user, $day, $schedule, $records));

                continue;
            }

            // 2. Approved leave — never counted absent.
            if (array_key_exists($uid, $leaveByUser)) {
                $offLeave->push([
                    'user' => $this->serializeUser($user),
                    'kind' => 'leave',
                    'leave_type' => $leaveByUser[$uid],
                ]);

                continue;
            }

            // 3. Off — no working shift rostered that day.
            if (! $schedule->isWorkingDay) {
                $offLeave->push([
                    'user' => $this->serializeUser($user),
                    'kind' => 'off',
                    'leave_type' => null,
                ]);

                continue;
            }

            // 4. Working shift, no punch: upcoming if its start is still ahead,
            //    otherwise absent. Evaluated in the app timezone, so a night
            //    shift and a past date both fall out correctly.
            $row = [
                'user' => $this->serializeUser($user),
                'shift' => $this->shiftPayload($user, $day, $schedule),
            ];

            if ($schedule->start->greaterThan($now)) {
                $upcoming->push($row);
            } else {
                $absent->push($row);
            }
        }

        $present = $this->sortByName($present);
        $absent = $this->sortByName($absent);
        $upcoming = $this->sortByName($upcoming);
        $offLeave = $this->sortByName($offLeave);

        return [
            'date' => $dateStr,
            'counts' => [
                'present' => $present->count(),
                'absent' => $absent->count(),
                'upcoming' => $upcoming->count(),
                'off_leave' => $offLeave->count(),
                'total' => $users->count(),
            ],
            'present' => $present->values()->all(),
            'absent' => $absent->values()->all(),
            'upcoming' => $upcoming->values()->all(),
            'off_leave' => $offLeave->values()->all(),
        ];
    }

    /**
     * Shift-based, idempotent, audited "mark present". Punch-in is set to the
     * member's rostered shift start (punch-out to its end) for the date. Shared
     * by the web mark-as-present flow and the mobile parity endpoint so the two
     * can never diverge. updateOrCreate keyed on (user_id, date) makes repeat
     * calls converge on one row rather than stacking duplicates.
     */
    public function markPresent(int $userId, string $date, ?Request $request = null): Attendance
    {
        $day = Carbon::parse($date, config('app.timezone'));
        $dateStr = $day->toDateString();
        $audit = app(AttendanceAuditService::class);
        $attendance = null;

        DB::transaction(function () use ($userId, $day, $dateStr, $audit, $request, &$attendance) {
            $schedule = $this->schedules->resolve($userId, $day);

            $attendance = Attendance::updateOrCreate(
                ['user_id' => $userId, 'date' => $dateStr],
                ['symbol' => '√', 'punchin' => $schedule->start, 'punchout' => $schedule->end],
            );

            $audit->record(
                'mark_present',
                $attendance->id,
                null,
                $attendance->only(['punchin', 'punchout', 'symbol', 'date', 'user_id']),
                $request?->input('reason'),
                $request,
            );
        });

        // Notify the live attendance dashboard that this date's presence changed.
        app(\App\Services\Realtime\RealtimeSignal::class)
            ->touch('attendance', $day->format('Y-m-d'), $request?->user()?->id, 'mark_present');

        return $attendance;
    }

    /**
     * Employees in scope: role Employee, optionally narrowed to a department
     * and/or an explicit member-id set. Media is eager-loaded so the
     * profile_image_url accessor never lazy-loads (and we never touch the raw
     * profile_image column, which does not exist in production).
     *
     * @return Collection<int, User>
     */
    private function inScopeUsers(?int $departmentId, ?array $memberIds, ?int $designationId = null): Collection
    {
        $query = User::query()
            ->whereHas('roles', fn ($role) => $role->where('name', 'Employee'))
            ->with('media')
            ->select(['id', 'name', 'employee_id', 'department_id', 'designation_id']);

        if ($memberIds !== null) {
            // Empty scope must yield no members, not "all members".
            $query->whereIn('id', $memberIds === [] ? [-1] : $memberIds);
        }

        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        if ($designationId !== null) {
            $query->where('designation_id', $designationId);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Qualifying attendance rows for the date, grouped by user. A row qualifies
     * as presence when it has a punch-in OR a manual √ symbol, and is not an
     * explicitly rejected record. The NULL-safe policy_status guard keeps ordinary
     * punches (whose policy_status is null) counted as present.
     *
     * @return Collection<int, Collection<int, Attendance>>
     */
    private function attendanceForDate(array $userIds, string $dateStr): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return Attendance::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('date', $dateStr)
            ->where(fn ($q) => $q->whereNotNull('punchin')->orWhere('symbol', '√'))
            ->where(fn ($q) => $q->whereNull('policy_status')->orWhere('policy_status', '!=', 'rejected'))
            ->orderBy('punchin')
            ->get(['id', 'user_id', 'punchin', 'punchout', 'symbol'])
            ->groupBy('user_id');
    }

    /**
     * Map of userId => leave-type label for members on APPROVED leave covering
     * the date. The label comes from leave_settings.type; it is null when the
     * settings row is missing.
     *
     * @return array<int, string|null>
     */
    private function approvedLeaveForDate(array $userIds, string $dateStr): array
    {
        if ($userIds === [] || ! Schema::hasTable('leaves')) {
            return [];
        }

        $column = $this->leavesUserColumn();
        if ($column === null) {
            return [];
        }

        $query = DB::table('leaves')
            ->whereIn("leaves.{$column}", $userIds)
            ->whereDate('leaves.from_date', '<=', $dateStr)
            ->whereDate('leaves.to_date', '>=', $dateStr)
            ->whereRaw('LOWER(leaves.status) = ?', ['approved']);

        if (Schema::hasTable('leave_settings')) {
            $rows = $query
                ->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
                ->get(["leaves.{$column} as uid", 'leave_settings.type as label']);
        } else {
            $rows = $query->get(["leaves.{$column} as uid"]);
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->uid] = $row->label ?? null;
        }

        return $map;
    }

    private function leavesUserColumn(): ?string
    {
        foreach (['user_id', 'user', 'employee_id'] as $candidate) {
            if (Schema::hasColumn('leaves', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Attendance>  $records
     */
    private function presentRow(User $user, CarbonInterface $day, ShiftSchedule $schedule, Collection $records): array
    {
        $punchInRow = $records->whereNotNull('punchin')->sortBy('punchin')->first();
        $punchOutRow = $records->whereNotNull('punchout')->sortBy('punchout')->last();

        return [
            'user' => $this->serializeUser($user),
            'shift' => $this->shiftPayload($user, $day, $schedule),
            'punch_in' => $punchInRow?->punchin ? Carbon::parse($punchInRow->punchin)->format('H:i') : null,
            'punch_out' => $punchOutRow?->punchout ? Carbon::parse($punchOutRow->punchout)->format('H:i') : null,
        ];
    }

    /**
     * {code, start, end} for a working shift, or null when the day is not a
     * working day. The roster's real Shift supplies the code; start/end come
     * from the resolved schedule (24h HH:MM), which already reflects the shift's
     * effective-dated version or the company default when no Shift row backs it.
     */
    private function shiftPayload(User $user, CarbonInterface $day, ShiftSchedule $schedule): ?array
    {
        if (! $schedule->isWorkingDay) {
            return null;
        }

        $shift = $this->roster->resolveShift((int) $user->id, $day);

        return [
            'code' => $shift?->code,
            'start' => $schedule->start->format('H:i'),
            'end' => $schedule->end->format('H:i'),
        ];
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'employee_id' => $user->employee_id,
            'profile_image_url' => $user->profile_image_url,
        ];
    }

    /**
     * @param  Collection<int, array>  $rows
     * @return Collection<int, array>
     */
    private function sortByName(Collection $rows): Collection
    {
        return $rows
            ->sortBy(fn (array $row) => (string) ($row['user']['name'] ?? ''))
            ->values();
    }
}
