<?php

namespace App\Jobs;

use App\Exports\AttendanceExport;
use App\Exports\AttendanceRangeExport;
use App\Exports\AttendanceRegisterExport;
use App\Exports\AttendanceRegisterPdf;
use App\Models\HRM\BiometricDownloadSession;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Attendance\AttendanceRegisterService;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Biometric\BiometricProcessingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExportAttendanceReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Upper bound on how many download sessions the pre-export sync will drain in
     * one run, so a backlog can never turn an export into an unbounded import.
     */
    protected const PRE_EXPORT_SESSION_LIMIT = 25;

    protected string $type;

    protected ?string $date;

    protected ?string $month;

    protected string $userId;

    protected string $filename;

    protected array $filters;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, ?string $date, ?string $month, string $userId, string $filename, array $filters = [])
    {
        $this->type = $type;
        $this->date = $date;
        $this->month = $month;
        $this->userId = $userId;
        $this->filename = $filename;
        $this->filters = $filters;
    }

    /**
     * Get the job type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Execute the job.
     */
    public function handle(AttendanceReportService $attendanceReportService): void
    {
        try {
            Log::info("ExportAttendanceReport started: Type={$this->type}, File={$this->filename}");

            // A timesheet compiled while device punches are still sitting in
            // biometric_att_logs as `downloaded` is silently short. Drain them first.
            $this->syncPendingBiometricLogs();

            // Ensure exports directory exists
            if (! Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }

            $filePath = 'exports/'.$this->filename;

            $user = User::find($this->userId);
            $departmentId = null;

            // Scope comes from DepartmentScope (managed departments + reporting subtree +
            // self); it fails closed. An export never runs unscoped for a requester who
            // cannot be resolved, and a stored filter can never widen the actor's scope.
            if (! $user) {
                throw new \RuntimeException('Attendance export requester could not be resolved.');
            }
            $employeeIds = app(DepartmentScope::class)->visibleEmployeeIds($user);
            if ($employeeIds !== null) {
                $this->filters['team_member_ids'] = $employeeIds;
            } else {
                unset($this->filters['team_member_ids']);
            }

            // A department filter naming a department outside the requester's scope is dropped, never echoed.
            if (isset($this->filters['department_id'])) {
                $visibleDept = AttendanceRegisterService::visibleDepartment((int) $this->filters['department_id'], $employeeIds);
                $visibleDept === null ? $this->filters = array_diff_key($this->filters, ['department_id' => 1]) : $this->filters['department_id'] = $visibleDept;
            }

            if ($this->type === 'daily_excel') {
                (new AttendanceExport($this->date, $departmentId, $employeeIds))->saveToDisk($filePath, 'public');
            } elseif ($this->type === 'daily_pdf') {
                Storage::disk('public')->put($filePath, (new AttendanceExport($this->date, $departmentId, $employeeIds))->pdf());
            } elseif (in_array($this->type, ['register_excel', 'register_pdf', 'monthly_excel', 'monthly_pdf'], true)) {
                // One register export for any date range. The old monthly_* types stay as aliases:
                // a month key becomes that calendar month.
                [$from, $to] = $this->registerPeriod();
                $deptId = AttendanceRegisterService::visibleDepartment(isset($this->filters['department_id']) ? (int) $this->filters['department_id'] : null, $employeeIds);
                if (str_ends_with($this->type, '_pdf')) {
                    Storage::disk('public')->put($filePath, (new AttendanceRegisterPdf($employeeIds, $deptId))->output($from, $to));
                } else {
                    (new AttendanceRegisterExport($employeeIds, $deptId))->saveToDisk($from, $to, $filePath, 'public');
                }
            } elseif ($this->type === 'range_excel') {
                if (! isset($this->filters['from'], $this->filters['to'])) {
                    throw new \InvalidArgumentException('Range export requires filters.from and filters.to.');
                }
                (new AttendanceRangeExport)->saveToDisk(
                    $this->filters['from'], $this->filters['to'], $this->filters, $filePath, 'public'
                );
            } elseif ($this->type === 'range_pdf') {
                if (! isset($this->filters['from'], $this->filters['to'])) {
                    throw new \InvalidArgumentException('Range export requires filters.from and filters.to.');
                }
                Storage::disk('public')->put($filePath, (new AttendanceRangeExport)->pdf($this->filters['from'], $this->filters['to'], $this->filters));
            }

            Log::info("ExportAttendanceReport completed: File={$this->filename}");
        } catch (\Exception $e) {
            Log::error('ExportAttendanceReport failed: '.$e->getMessage(), [
                'type' => $this->type,
                'date' => $this->date,
                'month' => $this->month,
                'file' => $this->filename,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * The register period: explicit from/to, else the month key, else the payroll cycle.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function registerPeriod(): array
    {
        if (! empty($this->filters['from']) && ! empty($this->filters['to'])) {
            return [Carbon::parse($this->filters['from'])->startOfDay(), Carbon::parse($this->filters['to'])->startOfDay()];
        }
        if ($this->month) {
            $from = Carbon::parse($this->month.'-01')->startOfDay();

            return [$from, $from->copy()->endOfMonth()->startOfDay()];
        }

        return AttendanceRegisterService::payrollCycle();
    }

    /**
     * Most recent finished download sessions still holding un-imported rows.
     *
     * Deliberately ONE query, not "every completed session ever" — that would be
     * O(all sessions) imports inside a queued export and would grow forever. The
     * correlated EXISTS asks the only question that matters: does this session's
     * device still hold rows captured after the session was created? Rows can only
     * be captured while a session is active, so the session that owns a pending row
     * is always the NEWEST session matching that predicate — ordering newest-first
     * and capping the result can therefore never miss the owning session, while any
     * older session that also matches simply imports nothing (its own window
     * excludes those rows).
     *
     * In steady state — the every-15-minutes biometric:import-downloaded schedule
     * having already drained things — this is one query returning nothing.
     */
    protected function pendingBiometricSessions()
    {
        return BiometricDownloadSession::query()
            ->with('device')
            // Pending / in-progress sessions are excluded on purpose: the device may
            // still be pushing, and replaying a half-received batch mis-pairs in/out.
            ->whereIn('status', ['completed', 'partial'])
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('biometric_att_logs')
                    ->whereColumn('biometric_att_logs.biometric_device_id', 'biometric_download_sessions.biometric_device_id')
                    ->where('biometric_att_logs.punch_status', 'downloaded')
                    ->whereColumn('biometric_att_logs.created_at', '>=', 'biometric_download_sessions.created_at');
            })
            ->orderByDesc('id')
            ->limit(self::PRE_EXPORT_SESSION_LIMIT)
            ->get();
    }

    /**
     * Import any staged biometric rows so the exported timesheet is current.
     *
     * Never fatal: a biometric sync problem must not block a report the user asked
     * for. Matches how handle() logs — message plus context — but swallows instead
     * of rethrowing, because the export itself is still perfectly producible.
     */
    protected function syncPendingBiometricLogs(): void
    {
        try {
            $sessions = $this->pendingBiometricSessions();

            if ($sessions->isEmpty()) {
                return;
            }

            $service = app(BiometricProcessingService::class);
            $totals = ['imported' => 0, 'duplicates' => 0, 'failed' => 0, 'skipped_unknown' => 0];

            foreach ($sessions as $session) {
                $result = $service->importDownloadedLogs($session);

                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + ($result[$key] ?? 0);
                }
            }

            Log::info('ExportAttendanceReport pre-export biometric sync', [
                'file' => $this->filename,
                'sessions' => $sessions->count(),
            ] + $totals);
        } catch (\Throwable $e) {
            Log::error('ExportAttendanceReport pre-export biometric sync failed: '.$e->getMessage(), [
                'type' => $this->type,
                'file' => $this->filename,
                'exception' => $e,
            ]);
        }
    }
}
