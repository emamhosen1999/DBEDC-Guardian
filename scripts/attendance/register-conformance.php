<?php

/**
 * Attendance register conformance: our export vs the owner's QCD.xlsx, property by property.
 *
 *   docker run --rm --network host -v "$PWD":/app -v ~/.local/share/dbedc-cyber-review:/review \
 *     -w /app dev-php:8.3 php scripts/attendance/register-conformance.php \
 *     --ref=/review/reference/QCD.xlsx --dept=11 --from=2026-08-26 --to=2026-09-25 --out=/review/diag/register-conformance.json
 *
 * FORMAT differences must be 0. Body symbol VALUES are compared separately as DATA differences.
 * The reference holds real names: nothing here prints or stores a name, only SL numbers.
 */

use App\Exports\AttendanceRegisterExport;
use App\Exports\Register\RegisterFormat as F;
use App\Services\Attendance\AttendanceRegisterService;
use App\Support\Attendance\AttendanceSymbol;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/RegisterProps.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$opt = getopt('', ['ref:', 'dept:', 'from:', 'to:', 'out:', 'xlsx:', 'sheet:']);
$refPath = $opt['ref'] ?? getenv('HOME').'/.local/share/dbedc-cyber-review/reference/QCD.xlsx';
$dept = (int) ($opt['dept'] ?? 11);
$from = Carbon::parse($opt['from'] ?? '2026-08-26');
$to = Carbon::parse($opt['to'] ?? '2026-09-25');
$outJson = $opt['out'] ?? null;
$xlsxOut = $opt['xlsx'] ?? sys_get_temp_dir().'/register-ours.xlsx';

// Generate OUR export from the local DB, save it, and read it back like the reference.
$ss = (new AttendanceRegisterExport(null, $dept))->build($from, $to);
(new Xlsx($ss))->save($xlsxOut);
$ours = register_props($xlsxOut);
$ref = register_props($refPath);
$refName = array_key_first($ref);
$oursName = $opt['sheet'] ?? $refName;

$format = [];   // format differences (must be 0)
$data = [];     // body symbol value differences
$approved = []; // approved additions (avatars, name indent)

$diff = function (string $where, string $prop, $a, $b) use (&$format) {
    $format[] = ['where' => $where, 'property' => $prop, 'reference' => $a, 'ours' => $b];
};

if (! isset($ours[$oursName])) {
    $diff('workbook', 'sheet name', $refName, array_keys($ours));
    finish($format, $data, $approved, $outJson, $xlsxOut);
}
$R = $ref[$refName];
$O = $ours[$oursName];
$sheetIdx = array_search($oursName, array_keys($ours), true);
if ($sheetIdx !== 0) {
    $diff('workbook', 'department register is the first sheet', 0, $sheetIdx);
}
$refSheetCount = 1;

// Row layout: the register has one body row per employee, so compare by ROLE. The key row ("Symbol Key :")
// closes the body; ours may carry ONE extra "Marks :" legend row under it (approved: only when a cell has a mark).
$keyRowOf = function (array $S): int {
    for ($r = 7; $r <= 400; $r++) {
        if (str_starts_with((string) ($S['cells']['A'.$r]['v'] ?? ''), 'Symbol Key')) {
            return $r;
        }
    }

    return 0;
};
$nRef = $keyRowOf($R) - 7;
$nOurs = $keyRowOf($O) - 7;
$extra = str_starts_with((string) ($O['cells']['A'.(7 + $nOurs + 1)]['v'] ?? ''), 'Marks :') ? 1 : 0;
if ($extra) {
    $approved['marks legend row under the key'] = 'present (a mark occurs in the data)';
}
$midCycle = 0;
$map = function (int $r) use ($nRef, $nOurs, $extra, &$midCycle): ?int {
    if ($r <= 6) {
        return $r;
    }
    $i = $r - 7;
    if ($i < $nRef) {
        if ($i === 0) {
            return 7;
        }
        if ($i === $nRef - 1) {
            return 7 + $nOurs - 1;
        }
        if ($nOurs < 3) {
            return null;
        }

        return 8 + (($midCycle++) % ($nOurs - 2)); // rotate over our middle rows so marked cells do not hide a column
    }

    return $i === $nRef ? 7 + $nOurs : 7 + $nOurs + 1 + $extra; // the key row, then the signature row (after our optional Marks row)
};
$bodyRef = fn (int $r) => $r >= 7 && $r < 7 + $nRef;

// Sheet-level properties (merges/print area/used range follow the row count: normalised).
$norm = fn (array $s, int $n) => str_replace((string) (7 + $n + 1), 'SIG', str_replace((string) (7 + $n), 'KEY', (string) json_encode($s)));
foreach (['orientation', 'paper_size', 'fit_to_page', 'fit_w', 'fit_h', 'scale', 'margins', 'h_centered', 'v_centered', 'header_odd', 'footer_odd', 'freeze', 'gridlines', 'print_titles', 'default_row_height', 'default_col_width'] as $k) {
    if ($R[$k] !== $O[$k]) {
        // Approved (owner, 2026-10-15): square day columns are wider, so print fits one page wide (any height).
        if (in_array($k, ['fit_to_page', 'fit_w', 'fit_h'], true) && ['fit_to_page' => true, 'fit_w' => 1, 'fit_h' => 0][$k] === $O[$k]) {
            $approved['print fits one page wide (square columns)'][$k] = $O[$k];
            continue;
        }
        $diff('sheet', $k, $R[$k], $O[$k]);
    }
}
$cols = fn ($area) => preg_replace('/\d+/', '', (string) $area);
if (preg_replace('/\d+/', '', $R['used_range']) !== preg_replace('/\d+/', '', $O['used_range'])) {
    $diff('sheet', 'used range columns', $R['used_range'], $O['used_range']);
}
if ($R['print_area'] !== null && str_replace((string) (7 + $nRef + 1), 'X', $R['print_area']) !== str_replace((string) (7 + $nOurs + 1 + $extra), 'X', (string) $O['print_area'])) {
    $diff('sheet', 'print area', $R['print_area'], $O['print_area']);
}
$mergeNorm = function (array $merges, int $n, int $extra) {
    if ($extra) { // the Marks legend row's own merge is an approved addition
        $merges = array_values(array_filter($merges, fn ($m) => ! preg_match('/^A'.(8 + $n).':[A-Z]+'.(8 + $n).'$/', $m)));
    }
    $out = array_map(fn ($m) => preg_replace_callback('/\d+/', fn ($x) => (int) $x[0] === 7 + $n ? 'K' : ((int) $x[0] === 8 + $n + $extra ? 'S' : $x[0]), $m), $merges);
    sort($out);

    return $out;
};
$mr = $mergeNorm($R['merges'], $nRef, 0);
$mo = $mergeNorm($O['merges'], $nOurs, $extra);
foreach (array_diff($mr, $mo) as $m) {
    $diff('merges', 'missing in ours', $m, null);
}
foreach (array_diff($mo, $mr) as $m) {
    $diff('merges', 'extra in ours', null, $m);
}
foreach ($R['cols'] as $c => $w) {
    if (($O['cols'][$c] ?? null) !== $w) {
        // Approved (owner, 2026-10-15): day columns are square, 3.0 (21 px) instead of the reference's 2.42578125 (17 px).
        if (abs((float) $w - 2.42578125) < 0.01 && abs((float) ($O['cols'][$c] ?? 0) - 3.0) < 0.01) { // widths are read rounded to 2 decimals
            $approved['square day columns (3.0 = 21 px)'][] = $c;
            continue;
        }
        $diff("column $c", 'width', $w, $O['cols'][$c] ?? null);
    }
}
foreach ($O['cols'] as $c => $w) {
    if (! array_key_exists($c, $R['cols'])) {
        $diff("column $c", 'extra column', null, $w);
    }
}
foreach ($R['rows'] as $r => $h) {
    $or = $map($r);
    if ($or === null) {
        continue;
    }
    if (($O['rows'][$or] ?? null) !== $h) {
        $diff("row $r", 'height', $h, $O['rows'][$or] ?? null);
    }
}

// Data-driven marks (overtime, rest-day work, leave): approved deviations ONLY where the data triggers them.
// They are looked up from the same service data the export reads (employee name -> date -> marks).
$marksByName = [];
$collected = app(AttendanceRegisterService::class)->collect($from, $to, null, $dept);
foreach ($collected['departments'] as $d) {
    foreach ($d['employees'] as $e) {
        foreach ($e['days'] as $date => $day) {
            $marksByName[mb_strtolower(trim($e['name']))][$date] = $day['marks'];
        }
    }
}

// Cells.
$day0 = 3;
$dayLast = 33; // C..AG for a 31-day register
$layout = F::layout($from, $to);
$dayLast = $layout['dayTo'];
$lastCol = $layout['lastCol'];
$styleKeys = ['font', 'fill', 'border', 'align', 'numfmt'];
for ($r = 1; $r <= 7 + $nRef + 1; $r++) {
    $or = $map($r);
    if ($or === null) {
        continue;
    }
    $isBody = $bodyRef($r);
    for ($c = 1; $c <= $lastCol; $c++) {
        $L = F::col($c);
        $rc = $R['cells'][$L.$r] ?? null;
        $oc = $O['cells'][$L.$or] ?? null;
        if ($rc === null || $oc === null) {
            $diff("$L$r", 'cell exists', $rc !== null, $oc !== null);

            continue;
        }
        $isDay = $isBody && $c >= $day0 && $c <= $dayLast;
        $isName = $isBody && $c === 2;
        $marked = false;
        if ($isDay) {
            $cellMarks = $marksByName[mb_strtolower(trim((string) ($O['cells']['B'.$or]['v'] ?? '')))][$layout['days'][$c - $day0]['date']] ?? null;
            $marked = $cellMarks !== null && AttendanceSymbol::hasVisibleMark($cellMarks);
        }
        foreach ($styleKeys as $k) {
            $a = $rc[$k];
            $b = $oc[$k];
            if ($marked && in_array($k, ['font', 'fill', 'border'], true)) {
                continue; // a data-triggered mark: verified separately below
            }
            if ($isDay && $k === 'fill') {
                continue; // a body cell's fill follows its day's data: checked per employee below (DATA) and by the marks pass
            }
            if ($isName && $k === 'align') {
                $bn = $b;
                $bn[4] = 0;
                if ($a !== $bn) {
                    $diff("$L$r", 'alignment', $a, $b);
                } elseif ($b[4] !== 0) {
                    $approved['name indent'][] = "$L$or";
                }

                continue;
            }
            if ($isName && $k === 'fill' && $r !== 7 + $nRef - 1 && false) {
                continue;
            }
            if ($a !== $b) {
                $diff("$L$r", $k, $a, $b);
            }
        }
        // Values: titles, headings, formulas and footer texts are format; body symbols are data.
        if ($isDay || $isName) {
            continue;
        }
        $rv = $rc['v'];
        $ov = $oc['v'];
        if ($isBody && $c === 1) {
            continue; // SL numbering follows the employee count
        }
        if ($isBody && is_string($rv) && str_starts_with($rv, '=')) {
            $rv = preg_replace('/(?<=[A-Z])'.$r.'\b/', '#', $rv);
            $ov = is_string($ov) ? preg_replace('/(?<=[A-Z])'.$or.'\b/', '#', $ov) : $ov;
        }
        if ($r === 3 || $r === 2 || $r === 1) {
            // Period and title are text the register fixes: compared exactly.
        }
        if ($rv !== $ov) {
            $diff("$L$r", 'value', $rv, $ov);
        }
    }
}

// Approved additions: the data-driven marks must be EXACTLY what the rules say, cell by cell, on OUR sheet.
$markCount = ['ot_unapplied (yellow fill)' => 0, 'ot_declined (light-red fill)' => 0, 'green √ font (OT approved / rest-day work)' => 0, 'bold border (rest-day work)' => 0, 'bold symbol (leave)' => 0];
$markSheet = $ours[$oursName];
for ($i = 0; $i < $nOurs; $i++) {
    $row = 7 + $i;
    $name = mb_strtolower(trim((string) ($markSheet['cells']['B'.$row]['v'] ?? '')));
    foreach ($layout['days'] as $d) {
        $cell = $markSheet['cells'][$d['letter'].$row] ?? null;
        $m = $marksByName[$name][$d['date']] ?? null;
        if ($cell === null || $m === null) {
            continue;
        }
        $expFill = F::markFill($m['fill']);
        $gotFill = $cell['fill'][0] === 'solid' ? $cell['fill'][1] : null;
        $expFont = $m['font'] === 'ot_green' ? F::COLOR_OT_APPROVED_FONT : 'FF000000';
        $allMedium = $cell['border']['l'][0] === 'medium' && $cell['border']['r'][0] === 'medium' && $cell['border']['t'][0] === 'medium' && $cell['border']['b'][0] === 'medium';
        $where = $d['letter'].$row;
        if ($gotFill !== $expFill) {
            $format[] = ['where' => $where, 'property' => 'mark fill follows the rules', 'reference' => $expFill, 'ours' => $gotFill];
        }
        if ($cell['font'][4] !== $expFont) {
            $format[] = ['where' => $where, 'property' => 'mark font colour follows the rules', 'reference' => $expFont, 'ours' => $cell['font'][4]];
        }
        if ($cell['font'][2] !== (bool) $m['bold']) {
            $format[] = ['where' => $where, 'property' => 'mark bold follows the rules', 'reference' => (bool) $m['bold'], 'ours' => $cell['font'][2]];
        }
        // A bold border is the only way a body cell has all four edges medium (edges are thin or medium on one axis only).
        if ($allMedium !== (bool) $m['border'] && ! ($allMedium && $i === $nOurs - 1 && in_array($d['col'], [$layout['dayFrom'], $layout['dayTo']], true))) {
            $format[] = ['where' => $where, 'property' => 'mark border follows the rules', 'reference' => (bool) $m['border'], 'ours' => $allMedium];
        }
        $markCount['ot_unapplied (yellow fill)'] += $m['fill'] === 'ot_unapplied';
        $markCount['ot_declined (light-red fill)'] += $m['fill'] === 'ot_declined';
        $markCount['green √ font (OT approved / rest-day work)'] += $m['font'] === 'ot_green';
        $markCount['bold border (rest-day work)'] += (bool) $m['border'];
        $markCount['bold symbol (leave)'] += (bool) $m['bold'];
    }
}
$approved['data-driven marks (cells, all follow the rules)'] = $markCount;

// Approved additions: avatar drawings per employee row.
// Read the drawings back from the SAVED file, not the in-memory build.
$saved = IOFactory::load($xlsxOut);
$ws = $saved->getSheetByName($oursName);
$drawings = $ws ? iterator_to_array($ws->getDrawingCollection()) : [];
$rowPx = (int) round(F::HEIGHT_BODY * 96 / 72);
$badDrawings = array_values(array_filter($drawings, fn ($d) => $d->getWidth() !== $rowPx || $d->getHeight() !== $rowPx || $d->getOffsetX() !== 0 || $d->getOffsetY() !== 0));
$approved['avatar drawings in name cells'] = [
    'count' => count($drawings), 'employees' => $nOurs,
    'size_px' => count($drawings) ? [$drawings[0]->getWidth(), $drawings[0]->getHeight()] : null,
    'row_height_px' => $rowPx, 'offsets' => count($drawings) ? [$drawings[0]->getOffsetX(), $drawings[0]->getOffsetY()] : null,
    'square_at_row_height_flush_left' => $badDrawings === [] && count($drawings) === $nOurs,
];
if ($badDrawings !== [] || count($drawings) !== $nOurs) {
    $format[] = ['where' => 'avatars', 'property' => 'square, row height, offset 0, one per row', 'reference' => 'n/a', 'ours' => count($badDrawings).' off-spec of '.count($drawings)];
}

// DATA: body symbols matched by employee NAME (names are never printed, only the reference SL).
$nameOf = function (array $S, int $row) {
    return trim((string) ($S['cells']['B'.$row]['v'] ?? ''));
};
$oursByName = [];
for ($i = 0; $i < $nOurs; $i++) {
    $oursByName[mb_strtolower($nameOf($O, 7 + $i))] = 7 + $i;
}
$refNames = [];
for ($i = 0; $i < $nRef; $i++) {
    $rr = 7 + $i;
    $key = mb_strtolower($nameOf($R, $rr));
    $refNames[$key] = true;
    $sl = $R['cells']['A'.$rr]['v'];
    if (! isset($oursByName[$key])) {
        $data[] = ['employee_sl' => $sl, 'issue' => 'in reference, not in our scope/export', 'cause' => 'no employee of that name in this department locally (not registered here, left, or spelled differently)'];

        continue;
    }
    $orow = $oursByName[$key];
    for ($c = $day0; $c <= $dayLast; $c++) {
        $L = F::col($c);
        $rv = $R['cells'][$L.$rr]['v'];
        $ov = $O['cells'][$L.$orow]['v'];
        if ((string) $rv !== (string) $ov) {
            $date = $layout['days'][$c - $day0]['date'];
            $ev = evidence($key, $date, $dept);
            $data[] = ['employee_sl' => $sl, 'date' => $date, 'reference' => $rv, 'ours' => $ov, 'evidence' => $ev['text'], 'cause' => dataCause((string) $rv, (string) $ov, $ev)];
        } elseif ($R['cells'][$L.$rr]['fill'] !== $O['cells'][$L.$orow]['fill'] && ! AttendanceSymbol::hasVisibleMark($marksByName[$key][$layout['days'][$c - $day0]['date']] ?? [])) {
            $data[] = ['employee_sl' => $sl, 'date' => $layout['days'][$c - $day0]['date'], 'reference' => $rv.' / '.json_encode($R['cells'][$L.$rr]['fill'][1]), 'ours' => $ov.' / '.json_encode($O['cells'][$L.$orow]['fill'][1]), 'cause' => 'colour differs from the festival rule with the same symbol: the reference colours this cell by hand'];
        }
    }
}
foreach ($oursByName as $key => $row) {
    if (! isset($refNames[$key])) {
        $data[] = ['employee_sl' => null, 'issue' => 'in our export, not in the reference', 'cause' => 'listed by the system but not in the reference (e.g. probationary employee with no joining date and no punches)'];
    }
}

/**
 * What the system actually holds for one employee-day (counts only, never a name): punches, roster, leave, overtime, holiday.
 *
 * @return array<string, mixed>
 */
function evidence(string $nameKey, string $date, int $dept): array
{
    static $users = [];
    $u = $users[$nameKey] ??= DB::table('users')->where('department_id', $dept)->whereRaw('LOWER(TRIM(name)) = ?', [$nameKey])->first(['employee_id']);
    if (! $u) {
        return ['text' => 'no such employee', 'punches' => 0, 'rejected' => 0, 'roster' => 'n/a', 'leave' => [], 'holiday' => false];
    }
    $db = fn (string $t) => DB::table($t);
    $att = $db('attendances')->where('user_id', $u->employee_id)->whereDate('date', $date)->get(['punchin', 'punchout', 'policy_status']);
    $rejected = $att->where('policy_status', 'rejected')->count();
    $usable = $att->filter(fn ($a) => $a->policy_status !== 'rejected' && $a->punchin)->count();
    $r = $db('roster_days')->where('user_id', $u->employee_id)->whereDate('date', $date)->first(['shift_id']);
    $roster = $r === null ? 'no roster row (company default applies)' : ($r->shift_id === null ? 'roster: rest day' : 'roster: shift');
    $leave = $db('leaves')->where('user_id', $u->employee_id)->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)->pluck('status')->map(fn ($x) => strtolower((string) $x))->all();
    $holiday = $db('holidays')->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)->whereNull('deleted_at')->exists();

    return [
        'text' => "{$att->count()} punch row(s), {$rejected} rejected, {$usable} usable; {$roster}; leave: ".($leave ? implode('/', $leave) : 'none').($holiday ? '; festival' : ''),
        'punches' => $att->count(), 'rejected' => $rejected, 'usable' => $usable, 'roster' => $roster, 'leave' => $leave, 'holiday' => $holiday,
    ];
}

/** @param array<string, mixed> $ev */
function dataCause(string $ref, string $ours, array $ev): string
{
    $leaveSymbols = ['§', '×', '□', '■', '◎', '△'];
    if ($ref === '√' && $ours === '▼') {
        if ($ev['punches'] === 0) {
            return 'reference shows Present; the system has NO punch for that day';
        }
        if ($ev['usable'] === 0 && $ev['rejected'] > 0) {
            return 'reference shows Present; every punch that day was REJECTED by the punch policy (rejected terminal punches), so the system shows Absent: the owner has a correction pending';
        }

        return 'reference shows Present; the system has punches but marks the day Absent';
    }
    if ($ref === '√' && $ours === '#') {
        return $ev['punches'] === 0
            ? 'reference marks Present on a festival date; the system has no punch that day, so it shows the festival holiday #'
            : 'reference marks Present on a festival date; the system shows the holiday although punches exist';
    }
    if (in_array($ref, $leaveSymbols, true) && $ours === '▼') {
        $states = $ev['leave'] ? 'a leave record exists but is '.implode('/', array_unique($ev['leave'])).' (not approved)' : 'no leave application exists';

        return "reference shows leave ({$ref}); the system shows Absent: {$states}";
    }
    if ($ref === '▼' && $ours === '/') {
        return 'reference marks Absent; the system shows a weekly off / rest day ('.$ev['roster'].')';
    }
    if ($ref === '/' && $ours === '√') {
        return "reference marks a weekly off; the system has {$ev['usable']} usable punch(es) that day ({$ev['roster']}), so it shows Present";
    }
    if ($ref === '√' && $ours === '/') {
        return 'reference shows Present on a day the system rosters off ('.$ev['roster'].') with no usable punch';
    }
    if ($ours === '·') {
        return 'date not yet recorded in the system at export time';
    }

    return 'values differ: reference and system disagree on this day ('.$ev['text'].')';
}

function finish(array $format, array $data, array $approved, ?string $out, string $xlsx): void
{
    $report = [
        'generated_at' => date('c'),
        'format_differences' => count($format),
        'format' => $format,
        'data_differences' => count($data),
        'data' => $data,
        'approved_additions' => array_map(fn ($v) => is_array($v) && isset($v[0]) ? count($v).' cells' : $v, $approved),
        'our_workbook' => $xlsx,
    ];
    foreach (array_slice($format, 0, 60) as $d) {
        echo 'FORMAT  '.$d['where'].'  '.$d['property'].'  reference='.json_encode($d['reference'], JSON_UNESCAPED_UNICODE).'  ours='.json_encode($d['ours'], JSON_UNESCAPED_UNICODE)."\n";
    }
    echo 'FORMAT DIFFERENCES: '.count($format)."\n";
    echo 'DATA DIFFERENCES: '.count($data)."\n";
    $byCause = [];
    foreach ($data as $d) {
        $byCause[$d['cause']] = ($byCause[$d['cause']] ?? 0) + 1;
    }
    foreach ($byCause as $cause => $n) {
        echo "  $n x $cause\n";
    }
    echo 'APPROVED ADDITIONS: '.json_encode($report['approved_additions'])."\n";
    if ($out) {
        @mkdir(dirname($out), 0777, true);
        file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "report: $out\n";
    }
    exit(count($format) === 0 ? 0 : 1);
}

finish($format, $data, $approved, $outJson, $xlsxOut);
