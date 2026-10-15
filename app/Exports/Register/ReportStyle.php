<?php

namespace App\Exports\Register;

use App\Support\Attendance\AttendanceSymbol;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The house format of every printable attendance report, taken from the owner's register:
 * company title row, report title, "Period : <from> – <to>", Arial Narrow, medium outer frame
 * with thin inner lines, symbol key (where symbols appear) and the Recorder / Authorized by
 * signature row, Letter paper, gridlines off.
 *
 * The register, the daily timesheet, the attendance log and the Summary sheet all build from
 * these primitives, so the look cannot drift between them.
 */
final class ReportStyle
{
    public const M = 'medium';

    public const T = 'thin';

    public const N = 'none';

    /** Style array for PhpSpreadsheet from edges and an optional font / alignment. */
    public static function cell(string $left, string $right, string $top, string $bottom, ?array $font = null, ?array $align = null, ?string $fill = null): array
    {
        $style = ['borders' => [
            'left' => ['borderStyle' => $left],
            'right' => ['borderStyle' => $right],
            'top' => ['borderStyle' => $top],
            'bottom' => ['borderStyle' => $bottom],
        ]];
        foreach (['left', 'right', 'top', 'bottom'] as $side) {
            if ($style['borders'][$side]['borderStyle'] !== self::N) {
                $style['borders'][$side]['color'] = ['argb' => 'FF000000'];
            }
        }
        if ($font) {
            $style['font'] = ['name' => RegisterFormat::FONT, 'size' => $font[0], 'bold' => $font[1]];
        }
        if ($align) {
            $style['alignment'] = ['horizontal' => $align[0], 'vertical' => $align[1], 'wrapText' => $align[2]] + (isset($align[3]) ? ['indent' => $align[3]] : []);
        }
        if ($fill) {
            $style['fill'] = ['fillType' => 'solid', 'startColor' => ['argb' => $fill]];
        }

        return $style;
    }

    /** Rows 1-3: company, report title, period; merged across the sheet and framed. */
    public static function titleBlock(Worksheet $ws, int $lastCol, string $title, CarbonInterface $from, CarbonInterface $to): void
    {
        $L = Coordinate::stringFromColumnIndex($lastCol);
        foreach ([1 => RegisterFormat::HEIGHTS[1], 2 => RegisterFormat::HEIGHTS[2], 3 => RegisterFormat::HEIGHTS[3]] as $r => $h) {
            $ws->getRowDimension($r)->setRowHeight($h);
            for ($c = 1; $c <= $lastCol; $c++) {
                $ws->getStyle(Coordinate::stringFromColumnIndex($c).$r)->applyFromArray(self::cell(
                    $c === 1 ? self::M : self::N,
                    $c === 1 || $c === $lastCol ? self::M : self::N,
                    [1 => self::M, 2 => self::T, 3 => self::T][$r],
                    [1 => self::T, 2 => self::T, 3 => self::N][$r],
                    $c === 1 ? [[1 => 13, 2 => 10, 3 => 9][$r], $r !== 3] : null,
                    $c === 1 ? ['center', 'center', true] : null,
                ));
            }
            $ws->mergeCells("A{$r}:{$L}{$r}");
        }
        $ws->setCellValue('A1', RegisterFormat::COMPANY);
        $ws->setCellValue('A2', $title);
        $ws->setCellValue('A3', RegisterFormat::period($from, $to));
    }

    /**
     * Symbol key row (when $withKey) then the signature row, both framed like the register's.
     * Returns the last row used.
     */
    public static function footer(Worksheet $ws, int $keyRow, int $lastCol, bool $withKey = true, bool $withMarks = false): int
    {
        $L = Coordinate::stringFromColumnIndex($lastCol);
        $sigRow = $keyRow;
        if ($withKey) {
            $ws->getRowDimension($keyRow)->setRowHeight(RegisterFormat::HEIGHT_KEY);
            for ($c = 1; $c <= $lastCol; $c++) {
                $ws->getStyle(Coordinate::stringFromColumnIndex($c).$keyRow)->applyFromArray(self::cell(
                    $c === 1 ? self::M : self::N, $c === 1 || $c === $lastCol ? self::M : self::N, self::N, self::T,
                    $c === 1 ? [8, false] : null, $c === 1 ? ['left', 'center', true] : null,
                ));
            }
            $ws->setCellValue("A{$keyRow}", AttendanceSymbol::keyText())->mergeCells("A{$keyRow}:{$L}{$keyRow}");
            $sigRow = $keyRow + 1;
            if ($withMarks) {
                // Marks legend under the key: only where cells carry overtime / rest-day / leave marks.
                $ws->getRowDimension($sigRow)->setRowHeight(RegisterFormat::HEIGHT_KEY);
                for ($c = 1; $c <= $lastCol; $c++) {
                    $ws->getStyle(Coordinate::stringFromColumnIndex($c).$sigRow)->applyFromArray(self::cell(
                        $c === 1 ? self::M : self::N, $c === 1 || $c === $lastCol ? self::M : self::N, self::N, self::T,
                        $c === 1 ? [8, false] : null, $c === 1 ? ['left', 'center', true] : null,
                    ));
                }
                $ws->setCellValue("A{$sigRow}", AttendanceSymbol::marksLegendText())->mergeCells("A{$sigRow}:{$L}{$sigRow}");
                $sigRow++;
            }
        }
        $ws->getRowDimension($sigRow)->setRowHeight(RegisterFormat::HEIGHT_SIGNATURE);
        $split = self::signatureSplit($lastCol);
        for ($c = 1; $c <= $lastCol; $c++) {
            $left = $c === 1 ? self::M : ($c === $split + 1 ? self::T : self::N);
            $right = $c === 1 || $c === $split ? self::M : ($c === $split + 1 ? self::T : ($c === $lastCol ? self::T : self::N));
            $ws->getStyle(Coordinate::stringFromColumnIndex($c).$sigRow)->applyFromArray(self::cell(
                $left, $right, self::T, self::M,
                $c === 1 || $c === $split + 1 ? [9, false] : null, $c === 1 || $c === $split + 1 ? ['left', 'top', true] : null,
            ));
        }
        $sL = Coordinate::stringFromColumnIndex($split);
        $sR = Coordinate::stringFromColumnIndex($split + 1);
        $ws->setCellValue("A{$sigRow}", RegisterFormat::recorderText())->mergeCells("A{$sigRow}:{$sL}{$sigRow}");
        $ws->setCellValue("{$sR}{$sigRow}", RegisterFormat::authorizedText())->mergeCells("{$sR}{$sigRow}:{$L}{$sigRow}");

        return $sigRow;
    }

    /** Column where the Recorder block ends (U of 42 in the reference). */
    public static function signatureSplit(int $lastCol): int
    {
        return (int) round($lastCol * 21 / 42);
    }

    /**
     * A plain table in the house style: header row (bold, centred, wrapped, medium top/bottom),
     * thin inner lines, medium outer frame and a medium line under the last body row.
     *
     * @param  list<float>  $widths  column widths (Excel units)
     * @param  array<int, string>  $leftAligned  column indexes whose body text is left aligned
     */
    public static function table(Worksheet $ws, int $headerRow, int $lastBody, int $lastCol, array $widths, array $leftAligned = [], ?float $headerHeight = 21.75): void
    {
        foreach ($widths as $i => $w) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        }
        $ws->getRowDimension($headerRow)->setRowHeight($headerHeight);
        for ($r = $headerRow; $r <= $lastBody; $r++) {
            $isHeader = $r === $headerRow;
            for ($c = 1; $c <= $lastCol; $c++) {
                $ws->getStyle(Coordinate::stringFromColumnIndex($c).$r)->applyFromArray(self::cell(
                    $c === 1 ? self::M : self::T,
                    $c === $lastCol ? self::M : self::T,
                    $isHeader ? self::M : self::T,
                    $isHeader ? self::M : ($r === $lastBody ? self::M : self::T),
                    $isHeader ? [9, true] : [9, false],
                    $isHeader ? ['center', 'center', true] : [in_array($c, $leftAligned, true) ? 'left' : 'center', 'center', true],
                ));
            }
        }
    }

    /** Letter paper, the register's margins, gridlines off, print area and page numbers. */
    public static function page(Worksheet $ws, int $lastCol, int $lastRow, string $orientation = PageSetup::ORIENTATION_LANDSCAPE, ?int $repeatHeaderRow = null, bool $fitWidth = false): void
    {
        $ws->setShowGridlines(false);
        $ws->getDefaultColumnDimension()->setWidth(RegisterFormat::DEFAULT_COL_WIDTH);
        $ws->getDefaultRowDimension()->setRowHeight(RegisterFormat::DEFAULT_ROW_HEIGHT);
        $ps = $ws->getPageSetup();
        $ps->setOrientation($orientation)->setPaperSize(PageSetup::PAPERSIZE_LETTER);
        [$l, $r, $t, $b, $h, $f] = RegisterFormat::MARGINS;
        $ws->getPageMargins()->setLeft($l)->setRight($r)->setTop($t)->setBottom($b)->setHeader($h)->setFooter($f);
        $ps->setPrintArea('A1:'.Coordinate::stringFromColumnIndex($lastCol).$lastRow);
        if ($repeatHeaderRow) {
            $ps->setRowsToRepeatAtTopByStartAndEnd($repeatHeaderRow, $repeatHeaderRow);
        }
        if ($fitWidth) {
            $ps->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
        }
    }

    /** "Page 1 of 3" in the printed footer; for the multi-page reports (the register keeps the reference's empty footer). */
    public static function pageNumbers(Worksheet $ws): void
    {
        $ws->getHeaderFooter()->setOddFooter('&C&"Arial Narrow,Regular"&8Page &P of &N');
    }

    /** House file name: <CODE>_<Report>_<from>_<to>_<time>_<random>.<ext> (the random part keeps the URL unguessable). */
    public static function filename(string $code, string $report, CarbonInterface $from, ?CarbonInterface $to, string $ext): string
    {
        $safe = fn (string $s) => preg_replace('/[^A-Za-z0-9_-]/', '-', $s);
        $period = $to && ! $to->isSameDay($from) ? $from->toDateString().'_'.$to->toDateString() : $from->toDateString();

        return $safe($code).'_'.$safe($report).'_'.$period.'_'.time().'_'.Str::random(24).'.'.$ext;
    }

    /** Shared PDF wrapper: embeds the fonts, draws page numbers and returns the bytes. */
    public static function pdf(string $view, array $data, string $orientation = 'landscape'): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));
        $pdf = Pdf::loadView($view, $data + ['fontDir' => resource_path('fonts/register')])->setPaper('letter', $orientation);
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('RegNarrow', 'normal');
        $canvas->page_text($canvas->get_width() - 120, $canvas->get_height() - 22, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.01, 0.01, 0.01]); // not pure black: dompdf skips the colour operator when it equals its cached colour, and the page keeps its last fill

        return $pdf->output();
    }

    /**
     * A complete tabular report in the house format (title block, optional note row, table, footer, page setup)
     * on one worksheet. Used by the daily timesheet, the attendance log and the Summary sheet.
     *
     * @param  list<string>  $headings
     * @param  list<float>  $widths  Excel column widths, one per heading
     * @param  list<list<scalar|null>>  $rows
     * @param  array{note?: ?string, withKey?: bool, leftAligned?: list<int>, orientation?: string, empty?: string}  $opts
     * @return int the last row used
     */
    public static function tableReport(Worksheet $ws, string $title, CarbonInterface $from, CarbonInterface $to, array $headings, array $widths, array $rows, array $opts = []): int
    {
        $lastCol = count($headings);
        $L = Coordinate::stringFromColumnIndex($lastCol);
        self::titleBlock($ws, $lastCol, $title, $from, $to);

        $row = 4;
        if (! empty($opts['note'])) {
            for ($c = 1; $c <= $lastCol; $c++) {
                $ws->getStyle(Coordinate::stringFromColumnIndex($c).$row)->applyFromArray(self::cell(
                    $c === 1 ? self::M : self::N, $c === 1 || $c === $lastCol ? self::M : self::N, self::T, self::N,
                    $c === 1 ? [9, false] : null, $c === 1 ? ['center', 'center', true] : null,
                ));
            }
            $ws->setCellValue("A{$row}", $opts['note'])->mergeCells("A{$row}:{$L}{$row}");
            $ws->getRowDimension($row)->setRowHeight(RegisterFormat::HEIGHTS[3]);
            $row++;
        }
        $headerRow = $row;
        $count = max(count($rows), 1);
        $lastBody = $headerRow + $count;
        self::table($ws, $headerRow, $lastBody, $lastCol, $widths, $opts['leftAligned'] ?? []);
        foreach ($headings as $i => $h) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$headerRow, $h);
        }
        if ($rows === []) {
            $ws->setCellValue('A'.($headerRow + 1), $opts['empty'] ?? 'No records for the selected period.')->mergeCells('A'.($headerRow + 1).":{$L}".($headerRow + 1));
        }
        foreach ($rows as $i => $values) {
            foreach (array_values($values) as $c => $v) {
                $coord = Coordinate::stringFromColumnIndex($c + 1).($headerRow + 1 + $i);
                if (is_string($v)) {
                    $ws->setCellValueExplicit($coord, $v, DataType::TYPE_STRING);
                } else {
                    $ws->setCellValue($coord, $v);
                }
            }
        }

        $last = self::footer($ws, $lastBody + 1, $lastCol, ! empty($opts['withKey']));
        self::page($ws, $lastCol, $last, $opts['orientation'] ?? PageSetup::ORIENTATION_LANDSCAPE, $headerRow, true);
        self::pageNumbers($ws);

        return $last;
    }

    /**
     * The same tabular report as PDF (house look), one table.
     *
     * @param  list<string>  $headings
     * @param  list<float>  $widths
     * @param  list<list<scalar|null>>  $rows
     */
    public static function tablePdf(string $title, CarbonInterface $from, CarbonInterface $to, array $headings, array $widths, array $rows, array $opts = []): string
    {
        $total = array_sum($widths) ?: 1;

        return self::pdf('attendance_report_pdf', [
            'reportTitle' => $title,
            'from' => $from,
            'to' => $to,
            'headings' => $headings,
            'colPct' => array_map(fn ($w) => round($w / $total * 100, 2), $widths),
            'rows' => $rows,
            'note' => $opts['note'] ?? null,
            'withKey' => ! empty($opts['withKey']),
            'leftAligned' => $opts['leftAligned'] ?? [],
            'empty' => $opts['empty'] ?? 'No records for the selected period.',
        ], $opts['orientation'] ?? 'landscape');
    }
}
