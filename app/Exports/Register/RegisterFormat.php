<?php

namespace App\Exports\Register;

use App\Support\Attendance\AttendanceSymbol;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * The register format: the single source of truth for the owner's QCD attendance
 * register (fonts, sizes, heights, widths, borders, colours, texts, print setup).
 *
 * The Excel export, the PDF view, the conformance script and the tests all read it,
 * so the format cannot drift between them.
 */
final class RegisterFormat
{
    public const COMPANY = 'Dhaka Bypass Expressway Development Company Ltd.';

    public const FONT = 'Arial Narrow';

    /** PDF twin of Arial Narrow: metric-compatible, SIL OFL. */
    public const PDF_FONT = 'Liberation Sans Narrow';

    public const PDF_SYMBOL_FONT = 'DejaVu Sans';

    public const FIRST_DAY_COL = 3; // C

    public const FIRST_BODY_ROW = 7;

    public const COLOR_FESTIVAL_HEADER = 'FFFF0000';

    public const COLOR_FESTIVAL_TAKEN = 'FFFF7C80';

    public const COLOR_FESTIVAL_WORKED = 'FF92D050';

    public const COLOR_WHITE = 'FFFFFFFF';

    /** Data-driven marks (approved deviations from the reference, only where the data triggers them). */
    public const COLOR_OT_APPROVED_FONT = 'FF00B050';

    public const COLOR_OT_UNAPPLIED_FILL = 'FFFFFF00';

    public const COLOR_OT_DECLINED_FILL = 'FFFF7C80';

    /**
     * Avatar in the name cell (an approved addition to the reference): a SQUARE exactly as tall as the body row
     * (15.75 pt = 21 px), flush left with no offset, so it changes neither row height nor column width.
     * The name text is indented just enough to clear it.
     */
    public const AVATAR_PX = 21;

    public const AVATAR_OFFSET_X = 0;

    public const AVATAR_OFFSET_Y = 0;

    public const NAME_INDENT = 3;

    public const WIDTH_SL = 3.85546875;

    public const WIDTH_NAME = 26.0;

    /** Day columns are SQUARE (owner, 2026-10-15): 3.0 = 21 px (Calibri 11 default font), equal to the 15.75 pt (21 px) body row.
     *  The owner's reference used 2.42578125 (17 px); the conformance check reports this as an approved deviation. */
    public const WIDTH_DAY = 3.0;

    public const WIDTH_DAY_REFERENCE = 2.42578125;

    public const WIDTH_TOTAL = 4.42578125;

    public const WIDTH_REMARK = 11.0;

    public const HEIGHTS = [1 => 27.75, 2 => 21.75, 3 => 15.75, 4 => 21.75, 5 => 18.0, 6 => 15.0];

    public const DEFAULT_COL_WIDTH = 8.7109375;

    public const DEFAULT_ROW_HEIGHT = 15.0;

    public const HEIGHT_BODY = 15.75;

    public const HEIGHT_KEY = 21.75;

    public const HEIGHT_SIGNATURE = 55.5;

    /** Left, right, top, bottom, header, footer (inches). */
    public const MARGINS = [0.75, 0.75, 1.0, 1.0, 0.511811023622047, 0.511811023622047];

    public static function title(string $code, CarbonInterface $from, CarbonInterface $to): string
    {
        return $code.' — '.($from->diffInDays($to) + 1 <= 31 ? 'Monthly Attendance Register' : 'Attendance Register');
    }

    public static function period(CarbonInterface $from, CarbonInterface $to): string
    {
        return 'Period :   '.$from->format('F j, Y').'  –  '.$to->format('F j, Y');
    }

    public static function recorderText(): string
    {
        return "Recorder :\n\n\n___________________________";
    }

    public static function authorizedText(): string
    {
        return "Authorized by :\n\n\n___________________________";
    }

    public static function col(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    /**
     * Column geometry of one register for a date range.
     *
     * @return array{
     *   days: list<array{date: string, col: int, letter: string, first: bool, last: bool}>,
     *   months: list<array{label: string, from: int, to: int}>,
     *   dayFrom: int, dayTo: int, totalFrom: int, totalTo: int, remark: int, lastCol: int, lastLetter: string
     * }
     */
    public static function layout(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = [];
        $months = [];
        $col = self::FIRST_DAY_COL;
        $cursor = Carbon::parse($from->toDateString());
        $end = Carbon::parse($to->toDateString());
        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m');
            $days[] = ['date' => $cursor->toDateString(), 'col' => $col, 'letter' => self::col($col), 'first' => false, 'last' => false, 'month' => $key];
            if (! $months || end($months)['key'] !== $key) {
                $months[] = ['key' => $key, 'label' => strtoupper($cursor->format('F')).'  '.$cursor->format('Y'), 'from' => $col, 'to' => $col];
            } else {
                $months[count($months) - 1]['to'] = $col;
            }
            $cursor->addDay();
            $col++;
        }
        foreach ($months as $m) {
            foreach ($days as &$d) {
                if ($d['col'] === $m['from']) {
                    $d['first'] = true;
                }
                if ($d['col'] === $m['to']) {
                    $d['last'] = true;
                }
            }
            unset($d);
        }
        $totalFrom = $col;
        $totalTo = $col + count(AttendanceSymbol::TOTALS) - 1;
        $remark = $totalTo + 1;

        return [
            'days' => $days,
            'months' => array_map(fn ($m) => ['label' => $m['label'], 'from' => $m['from'], 'to' => $m['to']], $months),
            'dayFrom' => self::FIRST_DAY_COL,
            'dayTo' => $totalFrom - 1,
            'totalFrom' => $totalFrom,
            'totalTo' => $totalTo,
            'remark' => $remark,
            'lastCol' => $remark,
            'lastLetter' => self::col($remark),
        ];
    }

    /** Two-letter weekday heading ("We"). */
    public static function weekday(string $date): string
    {
        return substr(Carbon::parse($date)->format('D'), 0, 2);
    }

    /**
     * ARGB fill for a mark's fill name (festival taken/worked, overtime unapplied/declined), or null.
     */
    public static function markFill(?string $fill): ?string
    {
        return match ($fill) {
            'festival_taken' => self::COLOR_FESTIVAL_TAKEN,
            'festival_worked' => self::COLOR_FESTIVAL_WORKED,
            'ot_unapplied' => self::COLOR_OT_UNAPPLIED_FILL,
            'ot_declined' => self::COLOR_OT_DECLINED_FILL,
            default => null,
        };
    }

    /**
     * Left/right edge of a column in any header or body row: medium on the SL block,
     * the name column, every month section, the totals block and the remark column.
     *
     * @param  array<string, mixed>  $layout
     * @return array{0: string, 1: string} [left, right] as 'medium'|'thin'|'none'
     */
    public static function sideEdges(int $col, array $layout): array
    {
        if ($col === 1) {
            return ['medium', 'thin'];
        }
        if ($col === 2) {
            return ['thin', 'medium'];
        }
        if ($col >= $layout['totalFrom'] && $col <= $layout['totalTo']) {
            return [$col === $layout['totalFrom'] ? 'medium' : 'thin', $col === $layout['totalTo'] ? 'medium' : 'thin'];
        }
        if ($col === $layout['remark']) {
            return ['medium', 'medium'];
        }
        foreach ($layout['days'] as $d) {
            if ($d['col'] === $col) {
                return [$d['first'] ? 'medium' : 'thin', $d['last'] ? 'medium' : 'thin'];
            }
        }

        return ['none', 'none'];
    }

    /** Row number of the key row for a register with $employees body rows. */
    public static function keyRow(int $employees): int
    {
        return self::FIRST_BODY_ROW + max($employees, 1);
    }

    /** The Remark-column note for replacement offs: "Replacement off <date> for rest day worked <date>", joined with "; ". */
    public static function replacementNote(array $replacements): string
    {
        usort($replacements, fn ($a, $b) => strcmp($a['off'], $b['off']));

        return implode('; ', array_map(
            fn ($r) => 'Replacement off '.Carbon::parse($r['off'])->format('d M Y').' for rest day worked '.Carbon::parse($r['worked'])->format('d M Y'),
            $replacements,
        ));
    }

    /** Sheet name: the department's register code, safe for a worksheet title. */
    public static function sheetName(string $code): string
    {
        $name = trim(preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $code));

        return mb_substr($name === '' ? 'Register' : $name, 0, 31);
    }
}
