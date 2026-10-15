<?php

namespace App\Exports\Register;

use App\Exports\Register\RegisterFormat as F;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads a register worksheet into an HTML-table model for the PDF, so the PDF draws
 * exactly what the Excel sheet holds: same merges, symbols, colours, borders, key and
 * signature row. Excel column widths and row heights are scaled to fit the page.
 */
final class RegisterPdfGrid
{
    private const BORDER_ALLOWANCE_PT = 0.9;

    /** Excel width unit -> points at 100% (7 px per unit + 5 px padding, 0.75 pt per px). */
    private static function widthPt(float $w): float
    {
        return ($w * 7 + 5) * 0.75;
    }

    /**
     * @return array{cols: list<float>, rows: list<array{height: float, cells: list<array<string, mixed>>}>, scale: float, widthPt: float}
     */
    public static function fromSheet(Worksheet $ws, float $pageWidthPt): array
    {
        $highCol = Coordinate::columnIndexFromString($ws->getHighestColumn());
        $highRow = $ws->getHighestRow();

        $colPt = [];
        for ($c = 1; $c <= $highCol; $c++) {
            $w = $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->getWidth();
            $colPt[$c] = self::widthPt($w > 0 ? $w : F::DEFAULT_COL_WIDTH);
        }
        $total = array_sum($colPt);
        $scale = min(1.0, $pageWidthPt / $total);

        // Merges: master cell -> [colspan, rowspan]; covered cells are skipped.
        $master = [];
        $covered = [];
        foreach ($ws->getMergeCells() as $range) {
            [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);
            $master[Coordinate::stringFromColumnIndex($c1).$r1] = [$c2 - $c1 + 1, $r2 - $r1 + 1];
            for ($r = $r1; $r <= $r2; $r++) {
                for ($c = $c1; $c <= $c2; $c++) {
                    if ($r !== $r1 || $c !== $c1) {
                        $covered[Coordinate::stringFromColumnIndex($c).$r] = true;
                    }
                }
            }
        }

        $avatars = [];
        foreach ($ws->getDrawingCollection() as $d) {
            if ($d instanceof Drawing) {
                $avatars[$d->getCoordinates()] = $d->getPath();
            }
        }

        $rows = [];
        for ($r = 1; $r <= $highRow; $r++) {
            $h = $ws->getRowDimension($r)->getRowHeight();
            $cells = [];
            for ($c = 1; $c <= $highCol; $c++) {
                $coord = Coordinate::stringFromColumnIndex($c).$r;
                if (isset($covered[$coord])) {
                    continue;
                }
                $cell = $ws->getCell($coord);
                $style = $ws->getStyle($coord);
                $font = $style->getFont();
                $b = $style->getBorders();
                $al = $style->getAlignment();
                $fill = $style->getFill();
                $value = $cell->getValue();
                if (is_string($value) && str_starts_with($value, '=')) {
                    try {
                        $value = $cell->getCalculatedValue();
                    } catch (\Throwable) {
                        $value = '';
                    }
                }
                [$cs, $rs] = $master[$coord] ?? [1, 1];
                $widthPt = 0.0;
                for ($k = $c; $k < $c + $cs; $k++) {
                    // dompdf adds the collapsed border to a cell's width: take it off so the table keeps its width.
                    $widthPt += $colPt[$k] * $scale - self::BORDER_ALLOWANCE_PT;
                }
                $cells[] = [
                    'text' => $value === null ? '' : (string) $value,
                    'width' => round($widthPt, 2),
                    'colspan' => $cs,
                    'rowspan' => $rs,
                    'font' => $font->getName(),
                    'size' => round((float) $font->getSize() * $scale, 2),
                    'bold' => (bool) $font->getBold(),
                    'color' => '#'.substr((string) $font->getColor()->getARGB(), 2),
                    'fill' => $fill->getFillType() === 'solid' ? '#'.substr($fill->getStartColor()->getARGB(), 2) : null,
                    'align' => $al->getHorizontal() === 'general' ? 'left' : $al->getHorizontal(),
                    'valign' => $al->getVertical() === 'center' ? 'middle' : $al->getVertical(),
                    'indent' => (int) $al->getIndent(),
                    'wrap' => (bool) $al->getWrapText(),
                    'border' => [
                        'l' => self::edge($b->getLeft()->getBorderStyle()),
                        'r' => self::edge($b->getRight()->getBorderStyle()),
                        't' => self::edge($b->getTop()->getBorderStyle()),
                        'b' => self::edge($b->getBottom()->getBorderStyle()),
                    ],
                    'avatar' => $avatars[$coord] ?? null,
                    'rowPt' => round((float) ($h > 0 ? $h : F::DEFAULT_ROW_HEIGHT) * $scale, 2),
                ];
            }
            $rows[] = ['height' => round((float) ($h > 0 ? $h : F::DEFAULT_ROW_HEIGHT) * $scale, 2), 'cells' => $cells];
        }

        return [
            'cols' => array_map(fn ($w) => round($w * $scale - self::BORDER_ALLOWANCE_PT, 2), array_values($colPt)),
            'rows' => $rows,
            'scale' => $scale,
            'widthPt' => round($total * $scale, 2),
        ];
    }

    private static function edge(string $style): string
    {
        return match ($style) {
            Border::BORDER_MEDIUM => '1.5pt solid #000',
            Border::BORDER_NONE => '0 none transparent',
            default => '0.5pt solid #000',
        };
    }

    /** Wrap the symbols Liberation Sans Narrow lacks so they use DejaVu Sans (no tofu boxes). */
    public static function symbolize(string $text, bool $lineBreaks = true): string
    {
        $safe = preg_replace('/([√▼·§×□■◎△])/u', '<span class="s">$1</span>', e($text));

        return $lineBreaks ? nl2br($safe) : $safe;
    }
}
