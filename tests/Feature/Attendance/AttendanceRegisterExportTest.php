<?php

namespace Tests\Feature\Attendance;

use App\Exports\AttendanceExport;
use App\Exports\AttendancePerEmployeeSummaryExport;
use App\Exports\AttendanceRangeExport;
use App\Exports\AttendanceRegisterExport;
use App\Exports\AttendanceRegisterPdf;
use App\Exports\Register\RegisterFormat as F;
use App\Exports\Register\RegisterPdfGrid;
use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceRegisterService;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Dashboard\TodaySummary;
use App\Support\Attendance\AttendanceSymbol;
use App\Support\Attendance\RegisterAvatar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The register export on a SYNTHETIC dataset (no real people): structure, formulas, key,
 * signatures, festival colours, one sheet per department, symbol mapping, avatars, PDF and the
 * cross-surface totals.
 */
class AttendanceRegisterExportTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-08-26';

    private const TO = '2026-09-25';

    private string $avatarDir;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        AttendanceSetting::create(['office_start_time' => '09:00:00', 'late_mark_after' => 15, 'weekend_days' => ['friday']]);
        $this->avatarDir = sys_get_temp_dir().'/register-avatars-'.uniqid();
        $this->app->bind(RegisterAvatar::class, fn () => new RegisterAvatar($this->avatarDir));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->avatarDir);
        parent::tearDown();
    }

    private function department(string $name, string $code): int
    {
        return DB::table('departments')->insertGetId(['name' => $name, 'code' => $code, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function employee(string $name, int $dept): User
    {
        $u = User::factory()->create(['name' => $name, 'department_id' => $dept, 'date_of_joining' => '2025-01-01']);
        $u->assignRole('Employee');

        return $u;
    }

    /** @return array{0: User, 1: User, 2: User} alpha present on the festival, alpha on leave, beta */
    private function world(): array
    {
        $alpha = $this->department('Alpha Works', 'ALP');
        $beta = $this->department('Beta Works', 'BET');
        $a1 = $this->employee('Alice Alpha', $alpha);
        $a2 = $this->employee('Aaron Alpha', $alpha);
        $b1 = $this->employee('Bella Beta', $beta);

        Holiday::create(['title' => 'Festival', 'from_date' => '2026-08-26', 'to_date' => '2026-08-26', 'type' => 'public', 'is_active' => true]);
        // Alice works the festival day (green); Aaron takes it (pink).
        Attendance::create(['user_id' => $a1->id, 'date' => '2026-08-26', 'punchin' => '2026-08-26 09:00:00', 'punchout' => '2026-08-26 17:00:00']);
        // A pending claim for the festival work is not a mark: the QCD style (green) stays. (Unclaimed work shows yellow: see the marks test.)
        OvertimeRequest::create(['user_id' => $a1->id, 'date' => '2026-08-26', 'requested_minutes' => 120, 'reason' => 'test', 'status' => 'pending']);
        Attendance::create(['user_id' => $a1->id, 'date' => '2026-08-27', 'punchin' => '2026-08-27 09:00:00', 'punchout' => '2026-08-27 17:00:00']);
        $casual = LeaveSetting::where('type', 'Casual')->first() ?? LeaveSetting::create(['type' => 'Casual', 'symbol' => AttendanceSymbol::PERSONAL, 'days' => 10]);
        $casual->update(['symbol' => AttendanceSymbol::PERSONAL]);
        Leave::create(['user_id' => $a2->id, 'leave_type' => $casual->id, 'from_date' => '2026-08-30', 'to_date' => '2026-08-31', 'no_of_days' => 2, 'status' => 'approved', 'reason' => 'test']);

        return [$a1, $a2, $b1];
    }

    private function build(?int $dept = null): Spreadsheet
    {
        return (new AttendanceRegisterExport(null, $dept))->build(Carbon::parse(self::FROM), Carbon::parse(self::TO));
    }

    private function cell(Worksheet $ws, string $coord): mixed
    {
        return $ws->getCell($coord)->getValue();
    }

    public function test_one_sheet_per_department_then_the_summary_sheet(): void
    {
        $this->world();
        $ss = $this->build();

        $this->assertSame(['ALP', 'BET', 'Summary'], $ss->getSheetNames());
        $this->assertSame('ALP — Monthly Attendance Register', $this->cell($ss->getSheet(0), 'A2'));
        $this->assertSame('BET — Monthly Attendance Register', $this->cell($ss->getSheet(1), 'A2'));
        // Only the department's own employees appear on its register.
        $this->assertSame(['Aaron Alpha', 'Alice Alpha'], [$this->cell($ss->getSheet(0), 'B7'), $this->cell($ss->getSheet(0), 'B8')]);
        $this->assertSame('Bella Beta', $this->cell($ss->getSheet(1), 'B7'));
    }

    public function test_structure_month_sections_formulas_widths_key_and_signatures(): void
    {
        $this->world();
        $ws = $this->build()->getSheetByName('ALP');

        $merges = array_values($ws->getMergeCells());
        foreach (['A1:AP1', 'A2:AP2', 'A3:AP3', 'A4:A6', 'B4:B6', 'C4:H4', 'I4:AG4', 'AH4:AO4', 'AP4:AP6', 'AH5:AH6', 'AO5:AO6', 'A9:AP9', 'A10:AP10', 'A11:U11', 'V11:AP11'] as $m) {
            $this->assertContains($m, $merges, "merge $m");
        }
        $this->assertSame('Dhaka Bypass Expressway Development Company Ltd.', $this->cell($ws, 'A1'));
        $this->assertSame('Period :   August 26, 2026  –  September 25, 2026', $this->cell($ws, 'A3'));
        $this->assertSame('AUGUST  2026', $this->cell($ws, 'C4'));
        $this->assertSame('SEPTEMBER  2026', $this->cell($ws, 'I4'));
        $this->assertSame([26, 'We', 1, 'Tu'], [$this->cell($ws, 'C5'), $this->cell($ws, 'C6'), $this->cell($ws, 'I5'), $this->cell($ws, 'I6')]);
        $this->assertSame('Reason for Leave (Days)', $this->cell($ws, 'AH4'));
        $this->assertSame("Abs\n(▼)", $this->cell($ws, 'AH5'));
        $this->assertSame("Fest\n(#)", $this->cell($ws, 'AO5'));
        $this->assertSame('=COUNTIF(C7:AG7,"▼")', $this->cell($ws, 'AH7'));
        $this->assertSame('=COUNTIF(C8:AG8,"#")', $this->cell($ws, 'AO8'));

        $this->assertEqualsWithDelta(3.855, $ws->getColumnDimension('A')->getWidth(), 0.01);
        $this->assertSame(26.0, (float) $ws->getColumnDimension('B')->getWidth());
        // Square day columns (owner, 2026-10-15): 3.0 = 21 px, the body row height; print fits one page wide.
        $this->assertEqualsWithDelta(3.0, $ws->getColumnDimension('C')->getWidth(), 0.001);
        $this->assertTrue($ws->getPageSetup()->getFitToPage());
        $this->assertSame(1, $ws->getPageSetup()->getFitToWidth());
        $this->assertSame(0, $ws->getPageSetup()->getFitToHeight());
        $this->assertEqualsWithDelta(4.426, $ws->getColumnDimension('AH')->getWidth(), 0.01);
        $this->assertSame(11.0, (float) $ws->getColumnDimension('AP')->getWidth());
        $this->assertSame(27.75, (float) $ws->getRowDimension(1)->getRowHeight());
        $this->assertSame(55.5, (float) $ws->getRowDimension(11)->getRowHeight());

        $this->assertSame(AttendanceSymbol::keyText(), $this->cell($ws, 'A9'));
        $this->assertStringStartsWith('Symbol Key :    √ = Present     ▼ = Absent     / = Weekly Off / Rest Day     · = Not Yet Recorded', $this->cell($ws, 'A9'));
        $this->assertStringEndsWith('△ = Annual Holiday     # = Festival Holiday', $this->cell($ws, 'A9'));
        // The synthetic world has a leave day (a mark), so a Marks legend row sits under the key.
        $this->assertSame(AttendanceSymbol::marksLegendText(), $this->cell($ws, 'A10'));
        $this->assertSame("Recorder :\n\n\n___________________________", $this->cell($ws, 'A11'));
        $this->assertSame("Authorized by :\n\n\n___________________________", $this->cell($ws, 'V11'));
        $this->assertSame('Arial Narrow', $ws->getStyle('C7')->getFont()->getName());
        $this->assertSame('landscape', $ws->getPageSetup()->getOrientation());
        $this->assertSame('A1:AP11', $ws->getPageSetup()->getPrintArea());
        $this->assertSame([0.75, 0.75, 1.0, 1.0], [$ws->getPageMargins()->getLeft(), $ws->getPageMargins()->getRight(), $ws->getPageMargins()->getTop(), $ws->getPageMargins()->getBottom()]);
    }

    public function test_any_range_gets_its_own_month_sections_and_title(): void
    {
        $this->world();
        $ss = (new AttendanceRegisterExport(null, null, false))->build(Carbon::parse('2026-08-20'), Carbon::parse('2026-10-05'));
        $ws = $ss->getSheetByName('ALP');
        $this->assertSame('ALP — Attendance Register', $this->cell($ws, 'A2')); // over 31 days
        $labels = [];
        foreach ($ws->getMergeCells() as $m) {
            if (preg_match('/^([A-Z]+)4:([A-Z]+)4$/', $m)) {
                $labels[] = $this->cell($ws, explode(':', $m)[0]);
            }
        }
        $this->assertContains('AUGUST  2026', $labels);
        $this->assertContains('SEPTEMBER  2026', $labels);
        $this->assertContains('OCTOBER  2026', $labels);
        // 47 days: day columns C..AW, totals follow the range length.
        $this->assertSame('=COUNTIF(C7:AW7,"▼")', $this->cell($ws, 'AX7'));
    }

    public function test_festival_colouring_and_symbol_mapping(): void
    {
        [$a1] = $this->world();
        $ws = $this->build()->getSheetByName('ALP');

        $this->assertSame(F::COLOR_FESTIVAL_HEADER, $ws->getStyle('C6')->getFill()->getStartColor()->getARGB()); // festival weekday header is red
        // Row 7 = Aaron (took the festival: pink '#'), row 8 = Alice (worked it: green '√').
        $this->assertSame('#', $this->cell($ws, 'C7'));
        $this->assertSame(F::COLOR_FESTIVAL_TAKEN, $ws->getStyle('C7')->getFill()->getStartColor()->getARGB());
        $this->assertSame('√', $this->cell($ws, 'C8'));
        $this->assertSame(F::COLOR_FESTIVAL_WORKED, $ws->getStyle('C8')->getFill()->getStartColor()->getARGB());
        // Aaron's casual leave is §, weekly off (Friday) is '/', never the old ▽.
        $this->assertSame('§', $this->cell($ws, 'H7'));  // Aug 31
        $this->assertSame('§', $this->cell($ws, 'G7'));  // Aug 30
        $this->assertSame('/', $this->cell($ws, 'E7'));  // Aug 28, a Friday
        // Totals are live formulas whose cached values match the grid.
        $this->assertSame(2, (int) $ws->getCell('AI7')->getCalculatedValue());
        $this->assertSame(1, (int) $ws->getCell('AO7')->getCalculatedValue());
        $this->assertNotContains('▽', array_map(fn ($c) => $c->getValue(), iterator_to_array($ws->getRowIterator(7, 8)->current()->getCellIterator())));
        $this->assertNotNull($a1);
    }

    public function test_leave_without_a_symbol_is_never_the_day_off_slash(): void
    {
        $this->assertSame('L', AttendanceSymbol::forLeaveSymbol(''));
        $this->assertSame('L', AttendanceSymbol::forLeaveSymbol(null));
        $this->assertSame('L', AttendanceSymbol::forLeaveSymbol('/'));
        $this->assertSame('§', AttendanceSymbol::forLeaveSymbol('§'));
        $this->assertCount(11, AttendanceSymbol::KEY);
        $this->assertSame('/', AttendanceSymbol::DAY_OFF);
    }

    public function test_the_leave_type_migration_maps_symbols_and_adds_funeral_and_maternity_reversibly(): void
    {
        // An installation's existing types, as production has them (Earned wrongly shares § with Casual, Marital has none).
        foreach ([['Casual', '§', 10], ['Sick', '×', 14], ['Earned', '§', 12], ['Marital', '', 10]] as [$type, $symbol, $days]) {
            LeaveSetting::create(['type' => $type, 'symbol' => $symbol, 'days' => $days, 'accrual_method' => 'annual_upfront']);
        }
        $migration = require database_path('migrations/2026_10_15_100001_map_leave_symbols_add_funeral_maternity.php');

        $migration->up();
        $symbols = LeaveSetting::pluck('symbol', 'type')->all();
        $this->assertSame(['Casual' => '§', 'Sick' => '×', 'Earned' => '△', 'Marital' => '□', 'Funeral' => '■', 'Maternity' => '◎'], $symbols);
        $this->assertSame(112, (int) LeaveSetting::where('type', 'Maternity')->value('days'));
        $this->assertSame(3, (int) LeaveSetting::where('type', 'Funeral')->value('days'));
        $this->assertSame('female', LeaveSetting::where('type', 'Maternity')->value('eligible_gender'));

        $migration->up(); // idempotent
        $this->assertSame(6, LeaveSetting::count());

        $migration->down();
        $this->assertSame(['Casual' => '§', 'Sick' => '×', 'Earned' => '§', 'Marital' => ''], LeaveSetting::pluck('symbol', 'type')->all());
    }

    public function test_quality_control_takes_the_register_code_reversibly(): void
    {
        $id = DB::table('departments')->insertGetId(['name' => 'Quality Control', 'code' => 'QC', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_10_15_100002_rename_quality_control_register_code.php');
        $migration->up();
        $this->assertSame('QCD', DB::table('departments')->where('id', $id)->value('code'));
        $migration->down();
        $this->assertSame('QC', DB::table('departments')->where('id', $id)->value('code'));
    }

    public function test_every_employee_row_has_an_avatar_and_the_name_is_indented(): void
    {
        $this->world();
        $ss = $this->build();
        $ws = $ss->getSheetByName('ALP');
        $this->assertCount(2, $ws->getDrawingCollection());
        foreach ($ws->getDrawingCollection() as $d) {
            // Square, exactly the body row's height (15.75 pt = 21 px), flush left in the name cell.
            $this->assertSame(F::AVATAR_PX, $d->getWidth());
            $this->assertSame(F::AVATAR_PX, $d->getHeight());
            $this->assertSame((int) round(F::HEIGHT_BODY * 96 / 72), $d->getHeight());
            $this->assertSame([0, 0], [$d->getOffsetX(), $d->getOffsetY()]);
            $this->assertMatchesRegularExpression('/^B[78]$/', $d->getCoordinates());
            $this->assertFileExists($d->getPath());
        }
        $this->assertSame(F::NAME_INDENT, $ws->getStyle('B7')->getAlignment()->getIndent());
        $this->assertCount(1, $ss->getSheetByName('BET')->getDrawingCollection());
        $this->assertSame(0, $ss->getSheetByName('Summary')->getDrawingCollection()->count());
    }

    public function test_initials_fallback_and_cache_reuse(): void
    {
        $dept = $this->department('Gamma Works', 'GAM');
        $u = $this->employee('Zed Zulu', $dept);
        $avatars = new RegisterAvatar($this->avatarDir);

        $this->assertSame('ZZ', RegisterAvatar::initials('Zed Zulu'));
        $this->assertSame('?', RegisterAvatar::initials('  '));

        $first = $avatars->pathFor($u);
        $this->assertFileExists($first);
        $this->assertSame(1, $avatars->rendered);
        $img = getimagesize($first);
        $this->assertSame([RegisterAvatar::SIZE, RegisterAvatar::SIZE, IMAGETYPE_PNG], [$img[0], $img[1], $img[2]]);
        $this->assertSame(F::AVATAR_PX * 2, $img[0], 'rendered at 2x the 21 px it is shown at');
        $corner = imagecolorat(imagecreatefrompng($first), 0, 0);
        $this->assertSame(0, ($corner >> 24) & 0x7F, 'square: the corner is opaque, no circular crop');

        // A second export reuses the cached PNG and renders nothing new.
        $second = $avatars->pathFor($u);
        $this->assertSame($first, $second);
        $this->assertSame(1, $avatars->rendered);
        $again = new RegisterAvatar($this->avatarDir);
        $again->pathFor($u);
        $this->assertSame(0, $again->rendered);
    }

    public function test_pdf_has_one_page_per_department_and_embeds_the_avatars(): void
    {
        $this->world();
        $pdf = (new AttendanceRegisterPdf)->output(Carbon::parse(self::FROM), Carbon::parse(self::TO));
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(3, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), 'two departments, one page each, then the Summary page');
        // The PDF draws the same worksheet: every employee row carries its avatar as an inline image.
        $ss = (new AttendanceRegisterExport(null, null, false))->build(Carbon::parse(self::FROM), Carbon::parse(self::TO));
        $html = view('attendance_register_pdf', [
            'sheets' => array_map(fn ($ws) => ['name' => $ws->getTitle(), 'grid' => RegisterPdfGrid::fromSheet($ws, 708.0)], $ss->getAllSheets()),
            'fontDir' => resource_path('fonts/register'),
            'avatarPx' => F::AVATAR_PX,
        ])->render();
        $this->assertSame(3, substr_count($html, 'background-image:url'));
        $this->assertStringContainsString('background-position:0 0', $html);
        $this->assertStringContainsString('class="s"', $html, 'symbols use the symbol font');
        $this->assertStringContainsString('LiberationSansNarrow', $pdf);
    }

    public function test_totals_agree_across_register_summary_calendar_daily_timesheet_and_dashboard(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        [$a1, $a2, $b1] = $this->world();
        $from = Carbon::parse(self::FROM);
        $to = Carbon::parse(self::TO);
        $svc = app(AttendanceReportService::class);
        $register = app(AttendanceRegisterService::class)->collect($from, $to);
        $byName = collect($register['departments'])->flatMap(fn ($d) => $d['employees'])->keyBy('name');
        $summary = (new AttendanceRegisterExport)->summaryData($from, $to);
        $summaryRows = collect($summary['rows'])->keyBy('employee_name');
        $leaveSymbols = ['§', '×', '□', '■', '◎', '△', 'L'];

        foreach ([$a1, $a2, $b1] as $u) {
            $days = $byName[$u->name]['days'];
            $count = fn (array $symbols) => count(array_filter($days, fn ($d) => in_array($d['symbol'], $symbols, true)));

            // 1. Register days == the Monthly Calendar's data (the same service, month by month) over the range.
            $calendar = [];
            foreach ([[2026, 8], [2026, 9]] as [$y, $m]) {
                $users = $svc->getEmployeeUsersWithAttendanceAndLeaves($y, $m, null, (string) $u->employee_id);
                foreach ($svc->getUserAttendanceData($users->first(), $y, $m, $svc->getHolidaysForMonth($y, $m), LeaveSetting::all()) as $date => $day) {
                    if (is_array($day) && $date >= self::FROM && $date <= self::TO) {
                        $calendar[$date] = $day['symbol'];
                    }
                }
            }
            $this->assertSame(array_map(fn ($d) => $d['symbol'], $days), $calendar, "{$u->name}: register days == calendar days");

            // 2. The Summary row for the SAME range agrees with the register's symbol totals.
            $row = $summaryRows[$u->name];
            $this->assertEqualsWithDelta($count(['▼']), $row['absent'], 0.001, "{$u->name}: absent");
            $this->assertEqualsWithDelta($count($leaveSymbols), $row['leave'], 0.001, "{$u->name}: leave");
        }

        // 3. One day: register, Daily Timesheet and the dashboard's present count agree.
        foreach (['2026-08-26', '2026-08-27'] as $date) {
            $registerPresent = collect($byName)->filter(fn ($e) => ($e['days'][$date]['symbol'] ?? null) === '√')->count();
            $daily = (new AttendanceExport($date))->collection();
            $this->assertSame($registerPresent, count(array_filter($daily, fn ($r) => $r['Day'] === '√')), "$date: daily timesheet present");
            $this->assertSame($registerPresent, count(array_filter($daily, fn ($r) => $r['Status'] === 'Present')));
            $dash = app(TodaySummary::class)->summarize(User::query()->role('Employee'), Carbon::parse($date.' 18:00:00'));
            $this->assertSame($registerPresent, $dash['present'], "$date: dashboard present");
        }
        // The festival date reads Festival Holiday for the person who took it, in every surface.
        $daily = collect((new AttendanceExport('2026-08-26'))->collection())->keyBy('Employee Name');
        $this->assertSame('Festival Holiday', $daily['Aaron Alpha']['Status']);
        $this->assertSame('#', $daily['Aaron Alpha']['Day']);
        Carbon::setTestNow();
    }

    public function test_the_other_exports_use_the_house_format(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->world();
        $daily = (new AttendanceExport('2026-08-27'))->build();
        $log = (new AttendanceRangeExport)->build(self::FROM, '2026-08-31', []);
        $summary = new Spreadsheet;
        (new AttendancePerEmployeeSummaryExport)->writeSheet($summary->getActiveSheet(), (new AttendanceRegisterExport)->summaryData(Carbon::parse(self::FROM), Carbon::parse(self::TO)));

        foreach ([[$daily->getActiveSheet(), 'Daily Timesheet', true], [$log->getActiveSheet(), 'Attendance Log', true], [$summary->getActiveSheet(), 'Attendance Summary', false]] as [$ws, $title, $key]) {
            $this->assertSame('Dhaka Bypass Expressway Development Company Ltd.', $this->cell($ws, 'A1'), $title);
            $this->assertSame($title, $this->cell($ws, 'A2'));
            $this->assertStringStartsWith('Period :   ', $this->cell($ws, 'A3'));
            $this->assertSame('Arial Narrow', $ws->getStyle('A1')->getFont()->getName());
            $this->assertSame('landscape', $ws->getPageSetup()->getOrientation());
            $this->assertFalse($ws->getShowGridlines());
            $last = $ws->getHighestRow();
            $this->assertStringContainsString('Recorder :', (string) $this->cell($ws, 'A'.$last));
            $this->assertSame($key, str_starts_with((string) $this->cell($ws, 'A'.($last - 1)), 'Symbol Key :'), "$title: symbol key row only where symbols appear");
            $this->assertStringContainsString('Page &P of &N', $ws->getHeaderFooter()->getOddFooter());
        }
        $this->assertSame('Day', $this->cell($daily->getActiveSheet(), 'H5'));
        $this->assertSame('Day', $this->cell($log->getActiveSheet(), 'F5'));
        Carbon::setTestNow();
    }

    public function test_symbol_key_matches_the_javascript_source(): void
    {
        $js = file_get_contents(resource_path('js/utils/attendanceSymbols.js'));
        foreach (AttendanceSymbol::KEY as $symbol => $label) {
            $this->assertStringContainsString('[SYMBOL.', $js);
            $this->assertStringContainsString("'{$label}'", $js, "label {$label}");
            $this->assertStringContainsString("'{$symbol}'", $js, "symbol {$symbol}");
        }
        foreach (AttendanceSymbol::TOTALS as [$heading, $symbol]) {
            $this->assertMatchesRegularExpression("/\['{$heading}', SYMBOL\./", $js);
        }
        foreach (AttendanceSymbol::MARK_LEGEND as $text) {
            $this->assertStringContainsString("'{$text}'", $js, "mark legend: {$text}");
        }
        $this->assertStringContainsString("UNKNOWN_LEAVE: '".AttendanceSymbol::UNKNOWN_LEAVE."'", $js);
        $this->assertStringContainsString("`Symbol Key :    \${SYMBOL_KEY.map(([s, l]) => `\${s} = \${l}`).join('     ')}`", $js);
    }
}
