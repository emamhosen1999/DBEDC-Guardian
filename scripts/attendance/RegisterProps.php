<?php

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Extracts every format property of a register workbook into a plain array so two
 * workbooks can be compared property by property. Used by register-conformance.php.
 */
function register_props(string $path): array
{
    $wb = IOFactory::load($path);
    $zip = new ZipArchive;
    $zip->open($path);
    $out = [];
    foreach ($wb->getAllSheets() as $i => $ws) {
        $out[$ws->getTitle()] = sheet_props($ws);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet'.($i + 1).'.xml');
        // Raw attributes the reader normalises: gridlines ("0" and "false" both mean hidden) and sheet defaults.
        $out[$ws->getTitle()]['gridlines'] = ! preg_match('/showGridLines="(0|false)"/', $xml);
        preg_match('/<sheetFormatPr[^>]*defaultRowHeight="([\d.]+)"/', $xml, $mh);
        preg_match('/<sheetFormatPr[^>]*defaultColWidth="([\d.]+)"/', $xml, $mw);
        $out[$ws->getTitle()]['default_row_height'] = isset($mh[1]) ? (float) $mh[1] : null;
        $out[$ws->getTitle()]['default_col_width'] = isset($mw[1]) ? (float) $mw[1] : null;
    }
    $zip->close();

    return $out;
}

function sheet_props(Worksheet $ws): array
{
    $ps = $ws->getPageSetup();
    $pm = $ws->getPageMargins();
    $hf = $ws->getHeaderFooter();
    $p = [
        'used_range' => 'A1:'.$ws->getHighestColumn().$ws->getHighestRow(),
        'merges' => array_values($ws->getMergeCells()),
        'orientation' => $ps->getOrientation(),
        'paper_size' => $ps->getPaperSize(),
        'print_area' => $ws->getPageSetup()->getPrintArea() ?: null,
        'fit_to_page' => $ps->getFitToPage(),
        'fit_w' => $ps->getFitToWidth(),
        'fit_h' => $ps->getFitToHeight(),
        'scale' => $ps->getScale(),
        'margins' => array_map(fn ($v) => round((float) $v, 9), [$pm->getLeft(), $pm->getRight(), $pm->getTop(), $pm->getBottom(), $pm->getHeader(), $pm->getFooter()]),
        'h_centered' => $ws->getPageSetup()->getHorizontalCentered(),
        'v_centered' => $ws->getPageSetup()->getVerticalCentered(),
        'header_odd' => $hf->getOddHeader(),
        'footer_odd' => $hf->getOddFooter(),
        'freeze' => $ws->getFreezePane(),
        'gridlines' => $ws->getShowGridlines(),
        'print_titles' => [$ps->getRowsToRepeatAtTop(), $ps->getColumnsToRepeatAtLeft()],
        'cols' => [],
        'rows' => [],
        'cells' => [],
    ];
    [$maxCol, $maxRow] = [Coordinate::columnIndexFromString($ws->getHighestColumn()), $ws->getHighestRow()];
    for ($c = 1; $c <= $maxCol; $c++) {
        $L = Coordinate::stringFromColumnIndex($c);
        $cd = $ws->getColumnDimension($L, false);
        $p['cols'][$L] = $cd ? round((float) $cd->getWidth(), 2) : null;
    }
    for ($r = 1; $r <= $maxRow; $r++) {
        $rd = $ws->getRowDimension($r, false);
        $p['rows'][$r] = $rd && $rd->getRowHeight() > 0 ? round((float) $rd->getRowHeight(), 2) : null;
    }
    for ($r = 1; $r <= $maxRow; $r++) {
        for ($c = 1; $c <= $maxCol; $c++) {
            $L = Coordinate::stringFromColumnIndex($c);
            $coord = $L.$r;
            $st = $ws->getStyle($coord);
            $f = $st->getFont();
            $b = $st->getBorders();
            $a = $st->getAlignment();
            $fill = $st->getFill();
            $cell = $ws->getCell($coord);
            $v = $cell->getValue();
            $p['cells'][$coord] = [
                'v' => $v,
                'font' => [$f->getName(), (float) $f->getSize(), (bool) $f->getBold(), (bool) $f->getItalic(), $f->getColor()->getARGB(), (bool) $f->getUnderline() !== false && $f->getUnderline() !== 'none' ? $f->getUnderline() : 'none'],
                'fill' => [$fill->getFillType(), $fill->getFillType() === 'none' ? null : $fill->getStartColor()->getARGB()],
                'border' => [
                    'l' => [$b->getLeft()->getBorderStyle(), $b->getLeft()->getBorderStyle() === 'none' ? null : $b->getLeft()->getColor()->getARGB()],
                    'r' => [$b->getRight()->getBorderStyle(), $b->getRight()->getBorderStyle() === 'none' ? null : $b->getRight()->getColor()->getARGB()],
                    't' => [$b->getTop()->getBorderStyle(), $b->getTop()->getBorderStyle() === 'none' ? null : $b->getTop()->getColor()->getARGB()],
                    'b' => [$b->getBottom()->getBorderStyle(), $b->getBottom()->getBorderStyle() === 'none' ? null : $b->getBottom()->getColor()->getARGB()],
                ],
                'align' => [$a->getHorizontal(), $a->getVertical(), (bool) $a->getWrapText(), $a->getTextRotation(), $a->getIndent(), (bool) $a->getShrinkToFit()],
                'numfmt' => $st->getNumberFormat()->getFormatCode(),
            ];
        }
    }

    return $p;
}
