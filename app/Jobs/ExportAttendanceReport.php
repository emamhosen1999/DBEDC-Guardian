<?php

namespace App\Jobs;

use App\Exports\AttendanceAdminExport;
use App\Exports\AttendanceExport;
use App\Exports\AttendanceRangeExport;
use App\Models\HRM\BiometricDownloadSession;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Biometric\BiometricProcessingService;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

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
            if ($user && ! $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']) && $user->department_id !== null) {
                $departmentId = $user->department_id;
            }

            if ($this->type === 'daily_excel') {
                Excel::store(new AttendanceExport($this->date, $departmentId), $filePath, 'public');
            } elseif ($this->type === 'daily_pdf') {
                $rows = (new AttendanceExport($this->date, $departmentId))->collection();
                $pdf = PDF::loadView('attendance_pdf', [
                    'title' => 'Daily Timesheet - '.date('F d, Y', strtotime($this->date)),
                    'generatedOn' => now()->format('F d, Y h:i A'),
                    'rows' => $rows,
                ])->setPaper('a4', 'landscape');

                Storage::disk('public')->put($filePath, $pdf->output());
            } elseif ($this->type === 'monthly_excel') {
                (new AttendanceAdminExport($departmentId))->saveToDisk($this->month, $filePath, 'public');
            } elseif ($this->type === 'monthly_pdf') {
                $from = Carbon::parse($this->month.'-01');
                $to = $from->copy()->endOfMonth();
                $monthName = $from->format('F Y');

                // Shared loader applies the same approved-leave / non-rejected-punch filters as the grid.
                $users = $attendanceReportService->getEmployeeUsersWithAttendanceAndLeaves($from->year, $from->month, $departmentId);
                $leaveTypes = LeaveSetting::all();
                $holidays = $attendanceReportService->getHolidaysForMonth($from->year, $from->month);

                $attendanceData = [];
                foreach ($users as $user) {
                    $attendanceData[] = $attendanceReportService->getUserAttendanceData($user, $from->year, $from->month, $holidays, collect($leaveTypes));
                }

                $summary = $attendanceReportService->getPerEmployeeMonthlySummary($from->year, $from->month, $departmentId);

                $pdf = PDF::loadView('attendance_admin_pdf', [
                    'monthName' => $monthName,
                    'from' => $from,
                    'to' => $to,
                    'users' => $users,
                    'attendanceData' => $attendanceData,
                    'leaveTypes' => $leaveTypes,
                    'summary' => $summary,
                ])->setPaper('a4', 'landscape');

                Storage::disk('public')->put($filePath, $pdf->output());
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
                $rows = app(AttendanceReportService::class)->getRangedAttendanceLog(
                    Carbon::parse($this->filters['from']),
                    Carbon::parse($this->filters['to']),
                    $this->filters
                );
                $pdf = PDF::loadView('attendance_range_pdf', [
                    'from' => Carbon::parse($this->filters['from']),
                    'to' => Carbon::parse($this->filters['to']),
                    'rows' => $rows,
                    'generatedOn' => now()->format('F d, Y h:i A'),
                ])->setPaper('a4', 'landscape');
                Storage::disk('public')->put($filePath, $pdf->output());
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
