<?php

namespace App\Exports;

use App\Exports\Register\ReportStyle;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The per-employee Summary: one row per employee with the period's totals, in the house report format.
 * It follows the department registers as the last sheet of the register workbook.
 */
class AttendancePerEmployeeSummaryExport
{
    public const HEADINGS = [
        'Employee', 'Emp ID', 'Department', 'Present', 'Absent', 'Leave', 'Paid Leave', 'LWP',
        'OT Hours', 'Late', 'Holidays Worked', 'Weekly-off Worked', 'Working Days', 'Attendance %',
    ];

    public const WIDTHS = [26, 12, 18, 8, 8, 8, 9, 8, 9, 8, 11, 11, 10, 11];

    public const LEFT = [1, 3];

    public const CAVEAT = 'Half-day leaves are split (0.5): a half-day counts as 0.5 leave + 0.5 present/absent. Paid Leave vs LWP per the leave type.';

    /** @return list<list<scalar|null>> the data rows then the TOTAL row */
    public static function rows(array $summary): array
    {
        $totals = ['present' => 0, 'absent' => 0, 'leave' => 0, 'paid_leave' => 0, 'lwp' => 0, 'ot_hours' => 0.0,
            'late' => 0, 'holidays_worked' => 0, 'weekly_off_worked' => 0, 'working_days' => 0];
        $rows = [];
        foreach ($summary['rows'] as $r) {
            $rows[] = [
                $r['employee_name'], $r['employee_id'], $r['department'],
                $r['present'], $r['absent'], $r['leave'], $r['paid_leave'], $r['lwp'],
                $r['ot_hours'], $r['late'],
                $r['holidays_worked'], $r['weekly_off_worked'], $r['working_days'], $r['attendance_percentage'],
            ];
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $r[$k];
            }
        }
        if ($rows !== []) {
            $rows[] = [
                'TOTAL', '', '',
                round($totals['present'], 1), round($totals['absent'], 1), round($totals['leave'], 1),
                round($totals['paid_leave'], 1), round($totals['lwp'], 1), round($totals['ot_hours'], 1),
                $totals['late'], $totals['holidays_worked'], $totals['weekly_off_worked'], $totals['working_days'], '',
            ];
        }

        return $rows;
    }

    public static function title(array $summary): string
    {
        $dept = $summary['meta']['departmentName'] ?? null;

        return 'Attendance Summary'.($dept ? ' — '.$dept : '');
    }

    /** Write the Summary layout onto the given worksheet and set its title to 'Summary'. */
    public function writeSheet(Worksheet $sheet, array $summary, ?CarbonInterface $from = null, ?CarbonInterface $to = null): void
    {
        $sheet->setTitle('Summary');
        $from ??= Carbon::parse($summary['meta']['from'] ?? now()->startOfMonth()->toDateString());
        $to ??= Carbon::parse($summary['meta']['to'] ?? now()->endOfMonth()->toDateString());
        ReportStyle::tableReport($sheet, self::title($summary), $from, $to, self::HEADINGS, self::WIDTHS, self::rows($summary), [
            'note' => self::CAVEAT, 'leftAligned' => self::LEFT, 'empty' => 'No employees in scope for this period.',
        ]);
    }

    /** The Summary as PDF pages (house look). */
    public function pdf(array $summary, CarbonInterface $from, CarbonInterface $to): string
    {
        return ReportStyle::tablePdf(self::title($summary), $from, $to, self::HEADINGS, self::WIDTHS, self::rows($summary), [
            'note' => self::CAVEAT, 'leftAligned' => self::LEFT, 'empty' => 'No employees in scope for this period.',
        ]);
    }
}
