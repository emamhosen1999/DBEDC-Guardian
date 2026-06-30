<?php

namespace App\Jobs;

use App\Exports\AttendanceAdminExport;
use App\Exports\AttendanceExport;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
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

    protected string $type;

    protected ?string $date;

    protected ?string $month;

    protected int $userId;

    protected string $filename;

    protected array $filters;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, ?string $date, ?string $month, int $userId, string $filename, array $filters = [])
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

            // Ensure exports directory exists
            if (! Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }

            $filePath = 'exports/'.$this->filename;

            if ($this->type === 'daily_excel') {
                Excel::store(new AttendanceExport($this->date), $filePath, 'public');
            } elseif ($this->type === 'daily_pdf') {
                $rows = (new AttendanceExport($this->date))->collection();
                $pdf = PDF::loadView('attendance_pdf', [
                    'title' => 'Daily Timesheet - '.date('F d, Y', strtotime($this->date)),
                    'generatedOn' => now()->format('F d, Y h:i A'),
                    'rows' => $rows,
                ])->setPaper('a4', 'landscape');

                Storage::disk('public')->put($filePath, $pdf->output());
            } elseif ($this->type === 'monthly_excel') {
                (new AttendanceAdminExport)->saveToDisk($this->month, $filePath, 'public');
            } elseif ($this->type === 'monthly_pdf') {
                $from = Carbon::parse($this->month.'-01');
                $to = $from->copy()->endOfMonth();
                $monthName = $from->format('F Y');

                // Shared loader applies the same approved-leave / non-rejected-punch filters as the grid.
                $users = $attendanceReportService->getEmployeeUsersWithAttendanceAndLeaves($from->year, $from->month);
                $leaveTypes = LeaveSetting::all();
                $holidays = $attendanceReportService->getHolidaysForMonth($from->year, $from->month);

                $attendanceData = [];
                foreach ($users as $user) {
                    $attendanceData[] = $attendanceReportService->getUserAttendanceData($user, $from->year, $from->month, $holidays, collect($leaveTypes));
                }

                $summary = $attendanceReportService->getPerEmployeeMonthlySummary($from->year, $from->month);

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
                (new \App\Exports\AttendanceRangeExport)->saveToDisk(
                    $this->filters['from'], $this->filters['to'], $this->filters, $filePath, 'public'
                );
            } elseif ($this->type === 'range_pdf') {
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
}
