<?php

namespace App\Exports;

use App\Exports\Register\ReportStyle;
use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The Attendance Log (one row per employee per day, with punch times) in the house report format.
 * The register is the department grid; this is the punch-level log behind it.
 */
class AttendanceRangeExport
{
    public const HEADINGS = ['Date', 'Employee', 'Employee ID', 'Department', 'Designation', 'Day', 'Status', 'Clock In', 'Clock Out', 'Work Hours', 'Remarks'];

    public const WIDTHS = [11, 24, 12, 16, 18, 5, 17, 10, 10, 10, 24];

    public const LEFT = [2, 4, 5, 11];

    /** @return list<list<scalar|null>> */
    public function rows(string $from, string $to, array $filters): array
    {
        $log = app(AttendanceReportService::class)->getRangedAttendanceLog(Carbon::parse($from), Carbon::parse($to), $filters);

        return array_map(fn ($r) => [
            Carbon::parse($r['date'])->format('M d, Y'),
            $r['employee_name'],
            $r['employee_id'] ?? '',
            $r['department'] ?? '',
            $r['designation'] ?? '',
            $r['symbol'],
            $r['status_label'],
            $r['clock_in'] ? Carbon::parse($r['clock_in'])->format('h:i A') : '—',
            $r['clock_out'] ? Carbon::parse($r['clock_out'])->format('h:i A') : '—',
            $r['work_hours'],
            $r['remarks'],
        ], $log);
    }

    private function note(array $filters): string
    {
        $parts = [];
        foreach (['status' => 'Status', 'department_id' => 'Dept', 'designation_id' => 'Designation', 'employee' => 'Search'] as $key => $label) {
            if (! empty($filters[$key])) {
                $parts[] = $label.': '.$filters[$key];
            }
        }

        return 'Filters: '.($parts ? implode(', ', $parts) : 'None');
    }

    public function build(string $from, string $to, array $filters): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Attendance Log');
        ReportStyle::tableReport($ws, 'Attendance Log', Carbon::parse($from), Carbon::parse($to), self::HEADINGS, self::WIDTHS, $this->rows($from, $to, $filters), [
            'note' => $this->note($filters), 'withKey' => true, 'leftAligned' => self::LEFT,
            'empty' => 'No records for the selected range and filters.',
        ]);

        return $spreadsheet;
    }

    public function saveToDisk(string $from, string $to, array $filters, string $filePath, string $disk = 'public'): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'attlog');
        (new Xlsx($this->build($from, $to, $filters)))->save($tmp);
        if (! Storage::disk($disk)->exists('exports')) {
            Storage::disk($disk)->makeDirectory('exports');
        }
        Storage::disk($disk)->put($filePath, file_get_contents($tmp));
        @unlink($tmp);
    }

    public function pdf(string $from, string $to, array $filters): string
    {
        return ReportStyle::tablePdf('Attendance Log', Carbon::parse($from), Carbon::parse($to), self::HEADINGS, self::WIDTHS, $this->rows($from, $to, $filters), [
            'note' => $this->note($filters), 'withKey' => true, 'leftAligned' => self::LEFT,
            'empty' => 'No records for the selected range and filters.',
        ]);
    }
}
