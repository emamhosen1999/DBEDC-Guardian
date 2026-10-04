<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOffboardingLwd;
use App\Models\HRM\AbsenceCase;
use App\Models\HRM\Attendance;
use App\Models\HRM\Offboarding;
use App\Models\HRM\RosterDay;
use App\Models\User;
use App\Notifications\Attendance\AbsenceStreakEscalationNotification;
use App\Services\Attendance\ShiftLifecycleAlertService;
use App\Services\HR\OffboardingInitiationNotifier;
use App\Services\Notification\NotificationRecipients;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Daily job: compute each rostered employee's consecutive unauthorized-absence
 * streak. Weekly offs, holidays, and approved leave are NEVER counted.
 *
 * Uses roster_days (materialized roster) + attendance records to detect working
 * days with no punch. Escalation thresholds from config('attendance.absence_escalation').
 */
class ProcessAbsenceStreak extends Command
{
    protected $signature = 'attendance:absence-streak
                            {--dry-run : Compute streaks but do not persist or notify}
                            {--date= : Override "today" for back-fills (Y-m-d)}';

    protected $description = 'Compute consecutive unauthorized-absence streaks and escalate per configured thresholds.';

    public function handle(ShiftLifecycleAlertService $alertService): int
    {
        $today = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->startOfDay();
        $dryRun = (bool) $this->option('dry-run');

        $config = config('attendance.absence_escalation', []);
        $managerDays = $config['manager_notify_after_days'] ?? 1;
        $hrDays = $config['hr_notify_after_days'] ?? 2;
        $showCauseDays = $config['show_cause_after_days'] ?? 10;
        $deemedGrace = $config['deemed_resignation_grace_days'] ?? 7;
        $autoOffboard = $config['auto_create_offboarding'] ?? false;
        $deemedDays = $showCauseDays + $deemedGrace;

        // Get all users who were rostered to work today but have no attendance
        $rosteredToday = RosterDay::where('date', $today->toDateString())
            ->whereNotNull('shift_id')
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($rosteredToday->isEmpty()) {
            $this->info('No rostered employees today — nothing to check.');

            return self::SUCCESS;
        }

        // Exclude users on approved leave today
        $onLeave = $this->usersOnApprovedLeave($rosteredToday->all(), $today->toDateString());
        $candidates = $rosteredToday->diff($onLeave)->values();

        // Exclude users who already have a completed/in-progress offboarding
        $offboarded = Offboarding::whereIn('employee_id', function ($q) use ($candidates) {
            $q->select('id')->from('users')->whereIn('employee_id', $candidates);
        })
            ->whereNotIn('status', [Offboarding::STATUS_CANCELLED])
            ->whereNotNull('last_working_date')
            ->where('last_working_date', '<', now()->toDateString())
            ->pluck('employee_id');

        $offboardedEmployeeIds = User::whereIn('id', $offboarded)->pluck('employee_id');
        $candidates = $candidates->diff($offboardedEmployeeIds)->values();

        // Find who punched in today
        $punched = Attendance::whereIn('user_id', $candidates)
            ->whereDate('date', $today->toDateString())
            ->whereNotNull('punchin')
            ->pluck('user_id')
            ->unique();

        // Absent today = rostered + not on leave + not punched
        $absentToday = $candidates->diff($punched)->values();

        $this->info("Evaluating {$candidates->count()} candidates, {$absentToday->count()} absent today.");

        $processed = 0;
        $escalated = 0;

        foreach ($absentToday as $userId) {
            $streak = $this->computeStreak($userId, $today);

            if ($streak < 1) {
                continue;
            }

            $processed++;

            if ($dryRun) {
                $this->line("  [DRY RUN] {$userId}: {$streak} day(s) absent");

                continue;
            }

            // Upsert the absence case
            $case = AbsenceCase::firstOrCreate(
                ['user_id' => $userId, 'first_absent_date' => $this->findFirstAbsentDate($userId, $today, $streak)],
                ['stage' => AbsenceCase::STAGE_MONITORING, 'streak_days' => $streak]
            );

            // Always update streak to latest count
            $case->streak_days = $streak;

            // Determine escalation stage
            $newStage = $this->resolveStage($streak, $showCauseDays, $deemedDays, $case);

            if ($newStage !== $case->stage) {
                $oldStage = $case->stage;
                $case->stage = $newStage;
                $case->addTimelineEntry("Escalated from {$oldStage} to {$newStage}", "Streak: {$streak} days");

                // Send escalation notifications
                $this->escalate($userId, $streak, $newStage, $case, $alertService, $managerDays, $hrDays);
                $escalated++;

                // Auto-create offboarding if configured and threshold met
                if ($newStage === AbsenceCase::STAGE_ABSCONDED && $autoOffboard && ! $case->offboarding_id) {
                    $this->autoCreateOffboarding($case, $userId);
                }
            }

            $case->save();
        }

        // Close cases for people who came back
        $returned = $candidates->intersect($punched)->values();
        $closed = $this->closeReturnedCases($returned);

        $this->info(sprintf(
            'Absence streak — %d streaks evaluated, %d escalated, %d returned cases closed.',
            $processed,
            $escalated,
            $closed,
        ));

        return self::SUCCESS;
    }

    /**
     * Count consecutive working days (backwards from $today) the user was absent.
     * Skips weekly offs, holidays, approved leave — only counts rostered working days.
     */
    private function computeStreak(string $userId, Carbon $today): int
    {
        $streak = 0;
        $d = $today->copy();

        // Look back up to 60 days maximum
        for ($i = 0; $i < 60; $i++) {
            $dateStr = $d->toDateString();

            // Was this a rostered working day?
            $rostered = RosterDay::where('user_id', $userId)
                ->where('date', $dateStr)
                ->whereNotNull('shift_id')
                ->exists();

            if (! $rostered) {
                // Not a working day (weekly off or no roster) — skip, don't break
                $d->subDay();

                continue;
            }

            // Was on approved leave?
            if ($this->isOnApprovedLeave($userId, $dateStr)) {
                $d->subDay();

                continue; // Leave day — skip
            }

            // Was holiday?
            if ($this->isHoliday($dateStr)) {
                $d->subDay();

                continue;
            }

            // Rostered working day — did they punch?
            $punched = Attendance::where('user_id', $userId)
                ->whereDate('date', $dateStr)
                ->whereNotNull('punchin')
                ->exists();

            if ($punched) {
                break; // Streak ends — they showed up
            }

            $streak++;
            $d->subDay();
        }

        return $streak;
    }

    private function findFirstAbsentDate(string $userId, Carbon $today, int $streak): string
    {
        // Walk backward to find the first absent date
        $d = $today->copy();
        $count = 0;
        $firstAbsent = $today;

        for ($i = 0; $i < 60 && $count < $streak; $i++) {
            $dateStr = $d->toDateString();
            $rostered = RosterDay::where('user_id', $userId)
                ->where('date', $dateStr)
                ->whereNotNull('shift_id')
                ->exists();

            if ($rostered && ! $this->isOnApprovedLeave($userId, $dateStr) && ! $this->isHoliday($dateStr)) {
                $punched = Attendance::where('user_id', $userId)
                    ->whereDate('date', $dateStr)
                    ->whereNotNull('punchin')
                    ->exists();

                if (! $punched) {
                    $firstAbsent = $d->copy();
                    $count++;
                }
            }

            $d->subDay();
        }

        return $firstAbsent->toDateString();
    }

    private function resolveStage(int $streak, int $showCauseDays, int $deemedDays, AbsenceCase $case): string
    {
        if ($streak >= $deemedDays && $case->stage === AbsenceCase::STAGE_SHOW_CAUSE) {
            return AbsenceCase::STAGE_ABSCONDED;
        }

        if ($streak >= $showCauseDays && in_array($case->stage, [AbsenceCase::STAGE_MONITORING, AbsenceCase::STAGE_NOTICE_SENT], true)) {
            return AbsenceCase::STAGE_SHOW_CAUSE;
        }

        if ($streak >= (config('attendance.absence_escalation.hr_notify_after_days', 2)) && $case->stage === AbsenceCase::STAGE_MONITORING) {
            return AbsenceCase::STAGE_NOTICE_SENT;
        }

        return $case->stage;
    }

    private function escalate(
        string $userId,
        int $streak,
        string $stage,
        AbsenceCase $case,
        ShiftLifecycleAlertService $alertService,
        int $managerDays,
        int $hrDays,
    ): void {
        $employee = User::where('employee_id', $userId)->first();
        if (! $employee) {
            return;
        }

        $notification = new AbsenceStreakEscalationNotification(
            $employee->name,
            $streak,
            $stage,
            $case->first_absent_date->toDateString(),
        );

        $directory = app(NotificationRecipients::class);
        $managerIds = [];

        // Manager always gets notified
        $manager = $alertService->resolveManager($employee);
        if ($manager) {
            $managerIds[] = $manager->employee_id;
        }

        $recipients = $directory->forEmployees($managerIds, null, $employee);

        // HR + dept head for higher stages
        if (in_array($stage, [AbsenceCase::STAGE_NOTICE_SENT, AbsenceCase::STAGE_SHOW_CAUSE, AbsenceCase::STAGE_ABSCONDED], true)) {
            // HR by permission, and only those whose DepartmentScope reaches this employee (no company-wide ping).
            $recipients = $recipients->merge($directory->forPermission('hr.offboarding.view', $employee, $employee));

            // Department head
            if ($employee->department && $employee->department->manager_id) {
                $recipients = $recipients->merge($directory->forEmployees([$employee->department->manager_id], null, $employee));
            }
        }

        $recipients = $recipients->unique('employee_id');

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify($notification);
            } catch (\Throwable $e) {
                Log::error("Absence streak notification failed for user {$recipient->employee_id}", [
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $case->notices_sent = ($case->notices_sent ?? 0) + 1;
    }

    private function autoCreateOffboarding(AbsenceCase $case, string $userId): void
    {
        $employee = User::where('employee_id', $userId)->first();
        if (! $employee) {
            return;
        }

        $existing = Offboarding::where('employee_id', $employee->employee_id)
            ->whereNotIn('status', [Offboarding::STATUS_COMPLETED, Offboarding::STATUS_CANCELLED])
            ->exists();

        if ($existing) {
            return;
        }

        // offboardings.created_by is NOT NULL (and FK-constrained to users), and this runs
        // from the scheduler with no authenticated user. Attribute the record to a system
        // actor — the first Super Administrator — rather than loosening the audit column.
        $systemActorId = $this->systemActorId();
        if ($systemActorId === null) {
            Log::error('Absence streak: cannot auto-create offboarding — no Super Administrator to attribute it to', [
                'absence_case_id' => $case->id,
                'employee_id' => $userId,
            ]);

            return;
        }

        $offboarding = new Offboarding([
            'employee_id' => $employee->employee_id,
            'initiation_date' => now()->toDateString(),
            'last_working_date' => $case->first_absent_date->subDay()->toDateString(),
            'reason' => Offboarding::REASON_ABSCONDED,
            'status' => Offboarding::STATUS_PENDING,
            'notes' => "Auto-created from absence case. {$case->streak_days} consecutive unauthorized absences starting {$case->first_absent_date->toDateString()}.",
        ]);
        $offboarding->created_by = $systemActorId; // not mass-assignable by design
        $offboarding->save();

        $case->offboarding_id = $offboarding->id;
        $case->addTimelineEntry('Offboarding auto-created', "Offboarding #{$offboarding->id}");

        // Dispatch LWD processing
        ProcessOffboardingLwd::dispatchFor($offboarding);

        app(OffboardingInitiationNotifier::class)->send($offboarding, $employee);

        Log::info('Absence streak: auto-created offboarding', [
            'absence_case_id' => $case->id,
            'offboarding_id' => $offboarding->id,
            'employee_id' => $userId,
        ]);
    }

    /** employee_id of the first (lowest id) active Super Administrator, or null. */
    private function systemActorId(): ?string
    {
        $id = User::role('Super Administrator')->whereNull('users.deleted_at')->orderBy('users.employee_id')->value('users.employee_id');

        return $id === null ? null : (string) $id;
    }

    private function closeReturnedCases($returnedUserIds): int
    {
        return AbsenceCase::whereIn('user_id', $returnedUserIds)
            ->whereNotIn('stage', [AbsenceCase::STAGE_RETURNED, AbsenceCase::STAGE_ABSCONDED])
            ->update([
                'stage' => AbsenceCase::STAGE_RETURNED,
                'outcome' => AbsenceCase::OUTCOME_RETURNED,
            ]);
    }

    private function usersOnApprovedLeave(array $userIds, string $date): array
    {
        if (! Schema::hasTable('leaves')) {
            return [];
        }

        $column = Schema::hasColumn('leaves', 'user_id') ? 'user_id' : (Schema::hasColumn('leaves', 'user') ? 'user' : null);
        if (! $column) {
            return [];
        }

        return DB::table('leaves')
            ->whereIn($column, $userIds)
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->pluck($column)
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    private function isOnApprovedLeave(string $userId, string $date): bool
    {
        if (! Schema::hasTable('leaves')) {
            return false;
        }

        $column = Schema::hasColumn('leaves', 'user_id') ? 'user_id' : (Schema::hasColumn('leaves', 'user') ? 'user' : null);
        if (! $column) {
            return false;
        }

        return DB::table('leaves')
            ->where($column, $userId)
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->exists();
    }

    private function isHoliday(string $date): bool
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
}
