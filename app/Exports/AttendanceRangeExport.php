<?php

namespace App\Exports;

use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AttendanceRangeExport
{
    private array $headings = [
        'Date', 'Employee', 'Employee ID', 'Department', 'Designation',
        'Clock In', 'Clock Out', 'Work Hours', 'Status', 'Remarks',
    ];

    public function saveToDisk(string $from, string $to, array $filters, string $filePath, string $disk = 'public'): void
    {
        $spreadsheet = $this->build($from, $to, $filters);
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'attlog');
        $writer->save($tempFile);

        if (! Storage::disk($disk)->exists('exports')) {
            Storage::disk($disk)->makeDirectory('exports');
        }
        Storage::disk($disk)->put($filePath, file_get_contents($tempFile));
        @unlink($tempFile);
    }

    private function build(string $from, string $to, array $filters): Spreadsheet
    {
        $fromC = Carbon::parse($from);
        $toC = Carbon::parse($to);

        $rows = app(AttendanceReportService::class)->getRangedAttendanceLog($fromC, $toC, $filters);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance Log');

        // Metadata header
        $sheet->setCellValue('A1', 'Attendance Log');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Range: '.$fromC->format('M d, Y').' to '.$toC->format('M d, Y'));
        $sheet->mergeCells('A2:J2');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $filterParts = [];
        foreach (['status' => 'Status', 'department_id' => 'Dept', 'designation_id' => 'Designation', 'employee' => 'Search'] as $key => $label) {
            if (! empty($filters[$key])) {
                $filterParts[] = $label.': '.$filters[$key];
            }
        }
        $sheet->setCellValue('A3', 'Filters: '.($filterParts ? implode(', ', $filterParts) : 'None').'  |  Generated: '.now()->format('M d, Y h:i A'));
        $sheet->mergeCells('A3:J3');
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Column headings on row 5
        $headerRow = 5;
        $col = 'A';
        foreach ($this->headings as $heading) {
            $sheet->setCellValue($col.$headerRow, $heading);
            $col++;
        }
        $sheet->getStyle("A{$headerRow}:J{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:J{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE3F2FD');

        // Data rows
        $r = $headerRow + 1;
        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$r, Carbon::parse($row['date'])->format('M d, Y'));
            $sheet->setCellValue('B'.$r, $row['employee_name']);
            $sheet->setCellValue('C'.$r, $row['employee_id'] ?? '');
            $sheet->setCellValue('D'.$r, $row['department'] ?? '');
            $sheet->setCellValue('E'.$r, $row['designation'] ?? '');
            $sheet->setCellValue('F'.$r, $row['clock_in'] ? Carbon::parse($row['clock_in'])->format('h:i A') : '—');
            $sheet->setCellValue('G'.$r, $row['clock_out'] ? Carbon::parse($row['clock_out'])->format('h:i A') : '—');
            $sheet->setCellValue('H'.$r, $row['work_hours']);
            $sheet->setCellValue('I'.$r, $row['status']);
            $sheet->setCellValue('J'.$r, $row['remarks']);
            $r++;
        }

        $lastRow = max($headerRow, $r - 1);
        $sheet->getStyle("A{$headerRow}:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach (range('A', 'J') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
