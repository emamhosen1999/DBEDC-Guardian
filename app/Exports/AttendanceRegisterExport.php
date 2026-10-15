<?php

namespace App\Exports;

use App\Exports\Register\RegisterFormat as F;
use App\Exports\Register\ReportStyle;
use App\Services\Attendance\AttendanceRegisterService;
use App\Services\Attendance\AttendanceReportService;
use App\Support\Attendance\AttendanceSymbol;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The attendance register (Excel): one sheet per department, any date range split
 * into month sections, in the owner's QCD register format (see RegisterFormat).
 * The per-employee Summary sheet follows the department registers.
 */
class AttendanceRegisterExport
{
    /** @param array<int, string>|null $employeeIds actor scope (DepartmentScope); null = unrestricted */
    public function __construct(
        protected ?array $employeeIds = null,
        protected ?int $departmentId = null,
        protected bool $withSummary = true,
    ) {}

    public function saveToDisk(CarbonInterface $from, CarbonInterface $to, string $filePath, string $disk = 'public'): void
    {
        $spreadsheet = $this->build($from, $to);
        $tmp = tempnam(sys_get_temp_dir(), 'register');
        (new Xlsx($spreadsheet))->save($tmp);
        if (! Storage::disk($disk)->exists('exports')) {
            Storage::disk($disk)->makeDirectory('exports');
        }
        Storage::disk($disk)->put($filePath, file_get_contents($tmp));
        @unlink($tmp);
    }

    public function build(CarbonInterface $from, CarbonInterface $to): Spreadsheet
    {
        $data = app(AttendanceRegisterService::class)->collect($from, $to, $this->employeeIds, $this->departmentId);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $departments = array_values(array_filter($data['departments'], fn ($d) => $d['employees'] !== []));
        if ($departments === []) {
            $departments = [['id' => null, 'code' => 'Register', 'name' => 'Register', 'employees' => []]];
        }

        $used = [];
        foreach ($departments as $dept) {
            $name = F::sheetName($dept['code']);
            for ($n = 2; in_array(mb_strtolower($name), $used, true); $n++) {
                $name = mb_substr(F::sheetName($dept['code']), 0, 28).' '.$n;
            }
            $used[] = mb_strtolower($name);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($name);
            $this->writeRegister($sheet, $dept, $data['from'], $data['to'], $data['festivals']);
        }

        if ($this->withSummary) {
            $summarySheet = $spreadsheet->createSheet();
            $this->writeSummary($summarySheet, $data['from'], $data['to']);
        }
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array{code: string, name: string, employees: list<array<string, mixed>>}  $dept
     * @param  list<string>  $festivals
     */
    public function writeRegister(Worksheet $ws, array $dept, CarbonInterface $from, CarbonInterface $to, array $festivals): void
    {
        $layout = F::layout($from, $to);
        $n = count($dept['employees']);
        $last = F::FIRST_BODY_ROW + $n - 1;
        $keyRow = F::FIRST_BODY_ROW + max($n, 1);
        // A Marks legend row is added under the key only when some cell actually carries a mark.
        $hasMarks = false;
        foreach ($dept['employees'] as $emp) {
            foreach ($emp['days'] as $d) {
                $hasMarks = $hasMarks || AttendanceSymbol::hasVisibleMark($d['marks'] ?? []);
            }
        }
        $sigRow = $keyRow + 1 + ($hasMarks ? 1 : 0);
        $lastCol = $layout['lastCol'];
        $L = $layout['lastLetter'];
        $festival = array_flip($festivals);

        // Sheet setup.
        $ws->setShowGridlines(false);
        $ws->getDefaultColumnDimension()->setWidth(F::DEFAULT_COL_WIDTH);
        $ws->getDefaultRowDimension()->setRowHeight(F::DEFAULT_ROW_HEIGHT);
        $ps = $ws->getPageSetup();
        $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_LETTER);
        $m = $ws->getPageMargins();
        [$m1, $m2, $m3, $m4, $m5, $m6] = F::MARGINS;
        $m->setLeft($m1)->setRight($m2)->setTop($m3)->setBottom($m4)->setHeader($m5)->setFooter($m6);
        $ps->setPrintArea("A1:{$L}{$sigRow}");

        // Columns.
        $ws->getColumnDimension('A')->setWidth(F::WIDTH_SL);
        $ws->getColumnDimension('B')->setWidth(F::WIDTH_NAME);
        foreach ($layout['days'] as $d) {
            $ws->getColumnDimension($d['letter'])->setWidth(F::WIDTH_DAY);
        }
        for ($c = $layout['totalFrom']; $c <= $layout['totalTo']; $c++) {
            $ws->getColumnDimension(F::col($c))->setWidth(F::WIDTH_TOTAL);
        }
        $ws->getColumnDimension($L)->setWidth(F::WIDTH_REMARK);

        // Rows.
        foreach ([4, 5, 6] as $r) {
            $ws->getRowDimension($r)->setRowHeight(F::HEIGHTS[$r]);
        }
        for ($r = F::FIRST_BODY_ROW; $r < $keyRow; $r++) {
            $ws->getRowDimension($r)->setRowHeight(F::HEIGHT_BODY);
        }

        // Cell styles: every cell of the frame is styled explicitly, edge by edge.
        for ($r = 4; $r <= $last; $r++) {
            for ($c = 1; $c <= $lastCol; $c++) {
                $ws->getStyle(F::col($c).$r)->applyFromArray($this->cellStyle($r, $c, $layout, $last));
            }
        }

        // Titles (shared house format).
        ReportStyle::titleBlock($ws, $lastCol, F::title($dept['code'], $from, $to), $from, $to);

        // Header.
        $ws->setCellValue('A4', 'SL')->mergeCells('A4:A6');
        $ws->setCellValue('B4', 'Employee Name')->mergeCells('B4:B6');
        foreach ($layout['months'] as $mo) {
            $a = F::col($mo['from']);
            $b = F::col($mo['to']);
            $ws->setCellValue("{$a}4", $mo['label']);
            if ($a !== $b) {
                $ws->mergeCells("{$a}4:{$b}4");
            }
        }
        foreach ($layout['days'] as $d) {
            $ws->setCellValue("{$d['letter']}5", (int) substr($d['date'], 8, 2));
            $ws->setCellValue("{$d['letter']}6", F::weekday($d['date']));
            if (isset($festival[$d['date']])) {
                $ws->getStyle("{$d['letter']}6")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(F::COLOR_FESTIVAL_HEADER);
            }
        }
        $t0 = F::col($layout['totalFrom']);
        $t1 = F::col($layout['totalTo']);
        $ws->setCellValue("{$t0}4", 'Reason for Leave (Days)')->mergeCells("{$t0}4:{$t1}4");
        foreach (AttendanceSymbol::TOTALS as $i => [$heading, $symbol]) {
            $letter = F::col($layout['totalFrom'] + $i);
            $ws->setCellValue("{$letter}5", $heading."\n(".$symbol.')')->mergeCells("{$letter}5:{$letter}6");
        }
        $ws->setCellValue("{$L}4", 'Remark')->mergeCells("{$L}4:{$L}6");

        // Body.
        $firstDay = F::col($layout['dayFrom']);
        $lastDay = F::col($layout['dayTo']);
        foreach ($dept['employees'] as $i => $emp) {
            $r = F::FIRST_BODY_ROW + $i;
            $ws->setCellValue("A{$r}", $i + 1);
            $ws->setCellValue("B{$r}", $emp['name']);
            $this->placeAvatar($ws, $emp, $r);
            if (! empty($emp['replacements'])) {
                // Remark column: the automatic replacement-off note (the full text also rides as a cell comment, the column is narrow).
                $note = F::replacementNote($emp['replacements']);
                $ws->setCellValueExplicit(F::col($layout['remark']).$r, $note, DataType::TYPE_STRING);
                $ws->getComment(F::col($layout['remark']).$r)->getText()->createTextRun($note);
            }
            foreach ($layout['days'] as $d) {
                $day = $emp['days'][$d['date']] ?? ['symbol' => AttendanceSymbol::NOT_YET, 'festival' => false];
                $ws->setCellValueExplicit("{$d['letter']}{$r}", $day['symbol'], DataType::TYPE_STRING);
                $this->applyMarks($ws, "{$d['letter']}{$r}", $day['marks'] ?? AttendanceSymbol::marks($day['symbol'], isset($festival[$d['date']]) && $day['symbol'] !== AttendanceSymbol::NOT_YET, false, null));
            }
            foreach (AttendanceSymbol::TOTALS as $k => [, $symbol]) {
                $letter = F::col($layout['totalFrom'] + $k);
                $ws->setCellValue("{$letter}{$r}", '=COUNTIF('.$firstDay.$r.':'.$lastDay.$r.',"'.$symbol.'")');
            }
        }
        if ($n > 0) {
            $ws->getStyle("B{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(F::COLOR_WHITE);
        }

        // Footer: symbol key and signatures (shared house format).
        ReportStyle::footer($ws, $keyRow, $lastCol, true, $hasMarks);
    }

    /** Font colour, bold, bold border and fill of one body cell from its marks (data-driven; neutral cells are untouched). */
    private function applyMarks(Worksheet $ws, string $coord, array $marks): void
    {
        $style = $ws->getStyle($coord);
        if ($fill = F::markFill($marks['fill'] ?? null)) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fill);
        }
        if (($marks['font'] ?? null) === 'ot_green') {
            $style->getFont()->getColor()->setARGB(F::COLOR_OT_APPROVED_FONT);
        }
        if (! empty($marks['bold'])) {
            $style->getFont()->setBold(true);
        }
        if (! empty($marks['border'])) {
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FF000000');
        }
    }

    /** Avatar inside the name cell, left of the text; the text is indented to clear it. */
    private function placeAvatar(Worksheet $ws, array $emp, int $row): void
    {
        if (empty($emp['avatar']) || ! is_file($emp['avatar'])) {
            return;
        }
        $ws->getStyle("B{$row}")->getAlignment()->setIndent(F::NAME_INDENT);
        $d = new Drawing;
        $d->setName('avatar-'.$emp['id']);
        $d->setPath($emp['avatar'], false);
        $d->setResizeProportional(false)->setWidth(F::AVATAR_PX)->setHeight(F::AVATAR_PX);
        $d->setCoordinates("B{$row}")->setOffsetX(F::AVATAR_OFFSET_X)->setOffsetY(F::AVATAR_OFFSET_Y);
        $d->setWorksheet($ws);
    }

    /**
     * Style of one cell in the header (rows 4-6) and body: edges edge by edge, as the reference draws them.
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    private function cellStyle(int $r, int $c, array $layout, int $lastBody): array
    {
        $M = ReportStyle::M;
        $T = ReportStyle::T;
        $N = ReportStyle::N;
        [$left, $right] = F::sideEdges($c, $layout);
        $isTotal = $c >= $layout['totalFrom'] && $c <= $layout['totalTo'];
        $isDay = $c >= $layout['dayFrom'] && $c <= $layout['dayTo'];
        $isRemark = $c === $layout['lastCol'];
        $isMonthStart = in_array($c, array_column($layout['months'], 'from'), true);
        $font = null; // null = leave the workbook default (Calibri 11)
        $align = null;

        if ($r === 4) {
            [$top, $bottom] = [$M, $T];
            if ($c <= 2 || $isRemark || $isMonthStart || $c === $layout['totalFrom']) {
                $font = [9, true];
                $align = ['center', 'center', true];
            }
        } elseif ($r === 5 || $r === 6) {
            [$top, $bottom] = $r === 5 ? [$T, $T] : [$T, $M];
            if (($isDay && $r === 6) || (($isDay || $isTotal) && $r === 5)) {
                $font = [8, true];
                $align = ['center', 'center', true];
            }
        } else {
            $top = $r === F::FIRST_BODY_ROW ? ($isTotal ? $M : $N) : $T;
            $bottom = $r === $lastBody ? $M : $T;
            if ($isRemark) {
                $left = $N;
            }
            $font = [9, false];
            $align = [$c === 2 || $isRemark ? 'left' : 'center', 'center', false];
        }

        return ReportStyle::cell($left, $right, $top, $bottom, $font, $align);
    }

    /** The per-employee Summary for the whole range (months added up per employee). */
    public function summaryData(CarbonInterface $from, CarbonInterface $to): array
    {
        $summary = null;
        $sums = ['present', 'absent', 'leave', 'paid_leave', 'lwp', 'ot_hours', 'late', 'holidays_worked', 'weekly_off_worked', 'working_days'];
        for ($c = $from->copy()->startOfMonth(); $c->lte($to); $c->addMonthNoOverflow()->startOfMonth()) {
            $month = app(AttendanceReportService::class)->getPerEmployeeMonthlySummary($c->year, $c->month, $this->departmentId, $this->employeeIds, $from, $to);
            if ($summary === null) {
                $summary = $month;
                $summary['rows'] = [];
            }
            foreach ($month['rows'] as $row) {
                $key = $row['employee_id'];
                if (! isset($summary['rows'][$key])) {
                    $summary['rows'][$key] = $row;

                    continue;
                }
                foreach ($sums as $k) {
                    $summary['rows'][$key][$k] += $row[$k];
                }
            }
        }
        $summary['rows'] = array_values($summary['rows'] ?? []);
        foreach ($summary['rows'] as &$row) {
            $denom = $row['present'] + $row['absent'];
            $row['attendance_percentage'] = $denom > 0 ? round($row['present'] / $denom * 100, 1) : 0.0;
        }
        unset($row);
        $summary['meta']['month'] = $from->format('F j, Y').' – '.$to->format('F j, Y');
        $summary['meta']['from'] = $from->toDateString();
        $summary['meta']['to'] = $to->toDateString();

        return $summary;
    }

    /** The per-employee Summary sheet, after the department registers. */
    private function writeSummary(Worksheet $sheet, CarbonInterface $from, CarbonInterface $to): void
    {
        (new AttendancePerEmployeeSummaryExport)->writeSheet($sheet, $this->summaryData($from, $to), $from, $to);
    }
}
