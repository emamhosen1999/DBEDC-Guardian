<?php

namespace App\Exports;

use App\Exports\Register\RegisterFormat as F;
use App\Exports\Register\RegisterPdfGrid;
use App\Exports\Register\ReportStyle;
use Carbon\CarbonInterface;

/**
 * The attendance register as PDF: the same worksheet the Excel export builds, drawn as
 * HTML, one department per page (landscape Letter), page numbers in the footer.
 */
class AttendanceRegisterPdf
{
    public const PAGE_WIDTH_PT = 708.0; // Letter landscape 792pt minus 0.4in margins, with a little slack

    /** @param array<int, string>|null $employeeIds actor scope (DepartmentScope); null = unrestricted */
    public function __construct(protected ?array $employeeIds = null, protected ?int $departmentId = null) {}

    public function output(CarbonInterface $from, CarbonInterface $to): string
    {
        $spreadsheet = (new AttendanceRegisterExport($this->employeeIds, $this->departmentId, withSummary: false))->build($from, $to);
        $sheets = [];
        foreach ($spreadsheet->getAllSheets() as $ws) {
            $sheets[] = ['name' => $ws->getTitle(), 'grid' => RegisterPdfGrid::fromSheet($ws, self::PAGE_WIDTH_PT)];
        }

        // The per-employee Summary closes the PDF as a last page, as the Summary sheet closes the workbook.
        $summary = (new AttendanceRegisterExport($this->employeeIds, $this->departmentId))->summaryData($from, $to);
        $total = array_sum(AttendancePerEmployeeSummaryExport::WIDTHS);

        return ReportStyle::pdf('attendance_register_pdf', [
            'sheets' => $sheets,
            'avatarPx' => F::AVATAR_PX,
            'summaryReport' => [
                'title' => AttendancePerEmployeeSummaryExport::title($summary),
                'from' => $from,
                'to' => $to,
                'table' => [
                    'note' => AttendancePerEmployeeSummaryExport::CAVEAT,
                    'headings' => AttendancePerEmployeeSummaryExport::HEADINGS,
                    'colPct' => array_map(fn ($w) => round($w / $total * 100, 2), AttendancePerEmployeeSummaryExport::WIDTHS),
                    'rows' => AttendancePerEmployeeSummaryExport::rows($summary),
                    'leftAligned' => AttendancePerEmployeeSummaryExport::LEFT,
                    'empty' => 'No employees in scope for this period.',
                ],
            ],
        ]);
    }
}
