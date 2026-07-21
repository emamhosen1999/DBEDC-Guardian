<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\RosterDay;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-access + resolution helpers for the proactive shift-lifecycle alerts
 * (shift-start reminder / overdue punch-in / absence escalation).
 *
 * Deliberately free of any timing/window policy — the console command owns the
 * offsets and dedupe. This class only answers: who is rostered to work today,
 * what is their resolved schedule, have they punched in, and who is their
 * manager.
 */
class ShiftLifecycleAlertService
{
    public function __construct(private readonly RosterService $roster) {}

    /**
     * Users rostered to WORK on $date — those with a materialized roster row
     * carrying a shift — paired with their authoritative resolved schedule.
     *
     * Excluded: company holidays (whole day → empty set), users whose effective
     * schedule for the day is off/swap-to-off, and users on approved leave.
     *
     * @return Collection<int, array{user: User, shift_id: int, shift_code: string, schedule: \App\Services\Attendance\DTO\ShiftSchedule, date: string}>
     */
    public function candidates(CarbonInterface $date): Collection
    {
        $dateString = $date->copy()->startOfDay()->toDateString();

        if ($this->isCompanyHoliday($dateString)) {
            return collect();
        }

        $userIds = RosterDay::query()
            ->whereDate('date', $dateString)
            ->whereNotNull('shift_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($userIds === []) {
            return collect();
        }

        $onLeave = $this->usersOnApprovedLeave($userIds, $dateString);

        $users = User::query()
            ->whereIn('id', $userIds)
            ->whereNull('deleted_at')
            ->with(['reportsTo', 'department'])
            ->get();

        $rows = collect();

        foreach ($users as $user) {
            if (in_array((int) $user->id, $onLeave, true)) {
                continue;
            }

            // resolveShift honours materialized-row precedence (manual > swap >
            // pattern) and returns null for an off/swap-to-off top row.
            $shift = $this->roster->resolveShift($user->id, $date);
            if ($shift === null) {
                continue;
            }

            $rows->push([
                'user' => $user,
                'shift_id' => (int) $shift->id,
                'shift_code' => (string) ($shift->code ?? $shift->name ?? 'shift'),
                'schedule' => $shift->toSchedule($date),
                'date' => $dateString,
            ]);
        }

        return $rows;
    }

    /**
     * Has the user recorded a punch-IN for the given business date? Overnight
     * shifts file their punch under the ROSTERED (prior) day, which is exactly
     * the date the roster row — and therefore $date here — is keyed on, so this
     * comparison is correct for day and night shifts alike.
     */
    public function hasPunchedIn(int $userId, string $date): bool
    {
        return Attendance::query()
            ->where('user_id', $userId)
            ->whereDate('date', $date)
            ->whereNotNull('punchin')
            ->exists();
    }

    /**
     * The manager to escalate a suspected absence to: the employee's direct
     * report_to, else the manager of their department. Never the employee
     * themselves; null when neither is resolvable.
     */
    public function resolveManager(User $employee): ?User
    {
        $manager = $employee->reportsTo; // belongsTo report_to
        if ($manager && (int) $manager->id !== (int) $employee->id && $manager->deleted_at === null) {
            return $manager;
        }

        $departmentManagerId = $employee->department?->manager_id;
        if ($departmentManagerId && (int) $departmentManagerId !== (int) $employee->id) {
            return User::query()
                ->whereKey($departmentManagerId)
                ->whereNull('deleted_at')
                ->first();
        }

        return null;
    }

    private function isCompanyHoliday(string $date): bool
    {
        if (! Schema::hasTable('holidays')) {
            return false;
        }

        $query = DB::table('holidays')
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date);

        if (Schema::hasColumn('holidays', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if (Schema::hasColumn('holidays', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->exists();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function usersOnApprovedLeave(array $userIds, string $date): array
    {
        $column = $this->leavesUserColumn();
        if (! $column || ! Schema::hasTable('leaves')) {
            return [];
        }

        return DB::table('leaves')
            ->whereIn($column, $userIds)
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->pluck($column)
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function leavesUserColumn(): ?string
    {
        if (Schema::hasColumn('leaves', 'user_id')) {
            return 'user_id';
        }

        if (Schema::hasColumn('leaves', 'user')) {
            return 'user';
        }

        return null;
    }
}
