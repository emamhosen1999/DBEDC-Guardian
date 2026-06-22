<?php

namespace App\Exports;

use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AttendancePerEmployeeSummaryExport
{
    private const HEADERS = [
        'Employee', 'Emp ID', 'Department', 'Present', 'Absent', 'Leave',
        'OT Hours', 'Late', 'Holidays Worked', 'Weekly-off Worked', 'Working Days', 'Attendance %',
    ];

    public function export(int $year, int $month, ?int $departmentId = null)
    {
        $summary = app(AttendanceReportService::class)
            ->getPerEmployeeMonthlySummary($year, $month, $departmentId);

        $monthName = Carbon::create($year, $month, 1)->format('F_Y');
        $deptSuffix = $departmentId && ($summary['meta']['departmentName'] ?? null)
            ? '_'.str_replace(' ', '_', $summary['meta']['departmentName'])
            : '';
        $fileName = "DBEDC_Attendance_Summary_{$monthName}{$deptSuffix}.xlsx";

        $spreadsheet = $this->buildSpreadsheet($summary);
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'att_summary');
        $writer->save($tempFile);

        return response()->download($tempFile, $fileName)->deleteFileAfterSend(true);
    }

    private function buildSpreadsheet(array $summary): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $lastColLetter = 'L'; // 12 columns

        // Title + month + caveat.
        $sheet->setCellValue('A1', 'Dhaka Bypass Expressway Development Company Ltd. — Monthly Attendance Summary');
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', $summary['meta']['month']
            .($summary['meta']['departmentName'] ? ' — '.$summary['meta']['departmentName'] : ''));
        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A3', 'Whole-day leave/present model — half-days are not yet split (a half-day may count as a full day).');
        $sheet->mergeCells("A3:{$lastColLetter}3");
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Header row (row 5).
        $headerRow = 5;
        $col = 'A';
        foreach (self::HEADERS as $h) {
            $sheet->setCellValue("{$col}{$headerRow}", $h);
            $col++;
        }

        // Data rows.
        $row = $headerRow + 1;
        $totals = ['present' => 0, 'absent' => 0, 'leave' => 0, 'ot_hours' => 0.0,
            'late' => 0, 'holidays_worked' => 0, 'weekly_off_worked' => 0, 'working_days' => 0];

        foreach ($summary['rows'] as $r) {
            $sheet->fromArray([
                $r['employee_name'], $r['employee_id'], $r['department'],
                $r['present'], $r['absent'], $r['leave'], $r['ot_hours'], $r['late'],
                $r['holidays_worked'], $r['weekly_off_worked'], $r['working_days'], $r['attendance_percentage'],
            ], null, "A{$row}");

            foreach (array_keys($totals) as $k) {
                $totals[$k] += $r[$k];
            }
            $row++;
        }

        // Totals footer.
        $sheet->setCellValue("A{$row}", 'TOTAL');
        $sheet->fromArray([
            $totals['present'], $totals['absent'], $totals['leave'], round($totals['ot_hours'], 1),
            $totals['late'], $totals['holidays_worked'], $totals['weekly_off_worked'], $totals['working_days'], '',
        ], null, "D{$row}");
        $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->getFont()->setBold(true);

        // Borders + header shading.
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->getFont()->setBold(true);

        foreach (range('A', $lastColLetter) as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
