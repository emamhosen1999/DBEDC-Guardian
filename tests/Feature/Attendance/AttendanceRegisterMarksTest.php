<?php

namespace Tests\Feature\Attendance;

use App\Exports\AttendanceRegisterExport;
use App\Exports\Register\RegisterFormat as F;
use App\Models\HRM\Attendance;
use App\Models\HRM\AttendancePolicy;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\RosterDay;
use App\Models\User;
use App\Services\Attendance\AttendanceRegisterService;
use App\Services\Attendance\Contracts\ReplacementOffSource;
use App\Support\Attendance\AttendanceSymbol as S;
use App\Support\Attendance\RegisterAvatar;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Overtime, leave and rest-day marks (owner Amendment 3) on a synthetic roster. Weekly offs come from each
 * employee's ROSTER (staggered, not Friday); OT is judged against the rostered shift and the policy threshold.
 */
class AttendanceRegisterMarksTest extends TestCase
{
    use RefreshDatabase;

    private string $avatarDir;

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        // No company weekend: only the roster decides who is off (Friday is NOT off for everyone).
        AttendanceSetting::create(['office_start_time' => '09:00:00', 'office_end_time' => '17:00:00', 'late_mark_after' => 15, 'weekend_days' => []]);
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 9101, 'effective_from' => '2026-01-01',
            'rule_overrides' => ['overtime' => ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5]],
        ]);
        $this->avatarDir = sys_get_temp_dir().'/register-marks-'.uniqid();
        $this->app->bind(RegisterAvatar::class, fn () => new RegisterAvatar($this->avatarDir));

        $dept = DB::table('departments')->insertGetId(['name' => 'Marks Dept', 'code' => 'MRK', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['p1', 'p2', 'p3', 'p4', 'p5', 'p6'] as $i => $key) {
            $u = User::factory()->create(['name' => 'Person '.($i + 1), 'department_id' => $dept, 'date_of_joining' => '2025-01-01']);
            $u->assignRole('Employee');
            $this->people[$key] = $u;
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->avatarDir);
        parent::tearDown();
    }

    private function work(string $key, string $date, string $in = '09:00', string $out = '17:00'): void
    {
        Attendance::create(['user_id' => $this->people[$key]->id, 'date' => $date, 'punchin' => "$date $in:00", 'punchout' => "$date $out:00"]);
    }

    private function restDay(string $key, string $date): void
    {
        RosterDay::create(['user_id' => $this->people[$key]->id, 'date' => $date, 'shift_id' => null, 'source' => 'manual', 'locked' => true]);
    }

    private function ot(string $key, string $date, string $status): void
    {
        OvertimeRequest::create(['user_id' => $this->people[$key]->id, 'date' => $date, 'requested_minutes' => 120, 'reason' => 'test', 'status' => $status]);
    }

    private function sheet(): Worksheet
    {
        return (new AttendanceRegisterExport(null, null, false))->build(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))->getSheetByName('MRK');
    }

    /** Column letter of a September date in a register that starts on the 1st (C = 1st). */
    private function col(string $date): string
    {
        return F::col(2 + (int) substr($date, 8, 2));
    }

    /** Row of a person in the register (Person 1 first: same designation, ordered by name). */
    private function row(string $key): int
    {
        return F::FIRST_BODY_ROW + (int) substr($key, 1) - 1;
    }

    private function style(Worksheet $ws, string $key, string $date): array
    {
        $c = $this->col($date).$this->row($key);
        $st = $ws->getStyle($c);

        return [
            'v' => $ws->getCell($c)->getValue(),
            'fill' => $st->getFill()->getFillType() === 'solid' ? $st->getFill()->getStartColor()->getARGB() : null,
            'font' => $st->getFont()->getColor()->getARGB(),
            'bold' => (bool) $st->getFont()->getBold(),
            'border' => $st->getBorders()->getLeft()->getBorderStyle(),
        ];
    }

    public function test_weekly_off_comes_from_the_roster_staggered_not_friday(): void
    {
        $this->restDay('p1', '2026-09-09'); // Wed
        $this->restDay('p2', '2026-09-10'); // Thu
        $ws = $this->sheet();

        $this->assertSame('/', $this->style($ws, 'p1', '2026-09-09')['v']);
        $this->assertSame('/', $this->style($ws, 'p2', '2026-09-10')['v']);
        // The others are NOT off on those days, and Friday is nobody's off here.
        $this->assertSame('▼', $this->style($ws, 'p2', '2026-09-09')['v']);
        $this->assertSame('▼', $this->style($ws, 'p1', '2026-09-10')['v']);
        $this->assertSame('▼', $this->style($ws, 'p1', '2026-09-11')['v']); // a Friday, unrostered: a working day
        $this->assertSame(0, $this->style($ws, 'p1', '2026-09-11')['bold'] ? 1 : 0);
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string, 3: bool}> rest-day work: OT status => fill, font, border */
    public function test_rest_day_work_is_bold_bordered_green_and_the_fill_follows_the_overtime_application(): void
    {
        $cases = [
            'p1' => ['2026-09-09', null,       F::COLOR_OT_UNAPPLIED_FILL], // no application: yellow
            'p2' => ['2026-09-10', 'approved', null],                       // approved: no background
            'p3' => ['2026-09-11', 'rejected', F::COLOR_OT_DECLINED_FILL], // declined: light red
            'p4' => ['2026-09-12', 'pending',  null],                       // pending: no extra mark
        ];
        foreach ($cases as $key => [$date, $status]) {
            $this->restDay($key, $date);
            $this->work($key, $date);
            if ($status) {
                $this->ot($key, $date, $status);
            }
        }
        $ws = $this->sheet();

        foreach ($cases as $key => [$date, $status, $fill]) {
            $s = $this->style($ws, $key, $date);
            $this->assertSame('√', $s['v'], "$key symbol");
            $this->assertSame('medium', $s['border'], "$key: bold border on a rest-day work");
            $this->assertSame(F::COLOR_OT_APPROVED_FONT, $s['font'], "$key: green √");
            $this->assertSame($fill, $s['fill'], "$key: fill");
            $this->assertFalse($s['bold']);
        }
    }

    public function test_extra_hours_on_a_working_day_are_not_overtime_and_get_no_mark(): void
    {
        // 09:00-19:00 = 600 min, far above the policy's 480-minute threshold; every claim state on top.
        foreach (['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17'] as $d) {
            $this->work('p5', $d, '09:00', '19:00');
        }
        $this->ot('p5', '2026-09-15', 'approved');
        $this->ot('p5', '2026-09-16', 'rejected');
        $this->ot('p5', '2026-09-17', 'pending');
        $ws = $this->sheet();

        foreach (['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17'] as $d) {
            $s = $this->style($ws, 'p5', $d);
            $this->assertSame('√', $s['v']);
            $this->assertNull($s['fill'], "$d: no overtime fill on a working day");
            $this->assertNotSame(F::COLOR_OT_APPROVED_FONT, $s['font'], "$d: no green overtime mark on a working day");
            $this->assertSame('thin', $s['border'], "$d: no bold border on a working day");
        }
        // And no Marks legend row for a register without marks.
        $this->assertStringStartsWith('Recorder :', $ws->getCell('A'.(F::FIRST_BODY_ROW + 6 + 1))->getValue());
    }

    public function test_holiday_work_follows_the_claim_rules_over_the_qcd_green(): void
    {
        Holiday::create(['title' => 'Public holiday', 'from_date' => '2026-09-22', 'to_date' => '2026-09-22', 'type' => 'public', 'is_active' => true]);
        foreach (['p1', 'p2', 'p3', 'p4'] as $key) {
            $this->work($key, '2026-09-22');
        }
        $this->ot('p2', '2026-09-22', 'approved');
        $this->ot('p3', '2026-09-22', 'rejected');
        $this->ot('p4', '2026-09-22', 'pending');
        $ws = $this->sheet();

        $d = '2026-09-22';
        $this->assertSame(F::COLOR_OT_UNAPPLIED_FILL, $this->style($ws, 'p1', $d)['fill'], 'no claim: yellow');
        $approved = $this->style($ws, 'p2', $d);
        $this->assertSame([F::COLOR_FESTIVAL_WORKED, F::COLOR_OT_APPROVED_FONT], [$approved['fill'], $approved['font']], 'approved: QCD green background, green √');
        $this->assertSame(F::COLOR_OT_DECLINED_FILL, $this->style($ws, 'p3', $d)['fill'], 'declined: light red');
        $pending = $this->style($ws, 'p4', $d);
        $this->assertSame(F::COLOR_FESTIVAL_WORKED, $pending['fill'], 'pending: no mark, the QCD green stays');
        $this->assertNotSame(F::COLOR_OT_APPROVED_FONT, $pending['font']);
        // Holiday work is not rest-day work: no bold border.
        $this->assertSame('thin', $approved['border']);
    }

    public function test_the_marks_legend_row_appears_only_when_a_mark_does(): void
    {
        $plain = $this->sheet();
        $keyRow = F::FIRST_BODY_ROW + 6;
        $this->assertSame(S::keyText(), $plain->getCell("A{$keyRow}")->getValue());
        $this->assertStringStartsWith('Recorder :', $plain->getCell('A'.($keyRow + 1))->getValue(), 'no marks: key row then signatures, as the reference');

        $this->restDay('p1', '2026-09-09');
        $this->work('p1', '2026-09-09');
        $marked = $this->sheet();
        $this->assertSame(S::marksLegendText(), $marked->getCell('A'.($keyRow + 1))->getValue());
        $this->assertStringContainsString('green √ = overtime approved', $marked->getCell('A'.($keyRow + 1))->getValue());
        $this->assertStringContainsString('yellow = worked on a rest day/holiday, not claimed', $marked->getCell('A'.($keyRow + 1))->getValue());
        $this->assertStringContainsString('light red = overtime declined', $marked->getCell('A'.($keyRow + 1))->getValue());
        $this->assertStringContainsString('bold border = worked on rest day', $marked->getCell('A'.($keyRow + 1))->getValue());
        $this->assertStringStartsWith('Recorder :', $marked->getCell('A'.($keyRow + 2))->getValue());
        // Every other cell of the key row is untouched.
        $this->assertSame(S::keyText(), $marked->getCell("A{$keyRow}")->getValue());
    }

    public function test_marks_are_in_the_api_data_the_calendar_reads(): void
    {
        $this->restDay('p1', '2026-09-09');
        $this->work('p1', '2026-09-09');
        $data = app(AttendanceRegisterService::class)->collect(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $day = collect($data['departments'][0]['employees'])->firstWhere('name', 'Person 1')['days']['2026-09-09'];
        $this->assertSame(['√', 'ot_unapplied', 'ot_green', true], [$day['symbol'], $day['marks']['fill'], $day['marks']['font'], $day['marks']['border']]);
    }

    public function test_every_combination_resolves_through_the_one_rule_set(): void
    {
        // [symbol, holiday, restDayWork, claim] => [fill, font, bold, border]
        $table = [
            // a normal working day: never an overtime mark, whatever the claim
            ['√', false, false, null,       [null, null, false, false]],
            ['√', false, false, 'approved', [null, null, false, false]],
            ['√', false, false, 'declined', [null, null, false, false]],
            ['√', false, false, 'pending',  [null, null, false, false]],
            // worked on a rostered rest day: bold border + green √, fill by claim
            ['√', false, true,  null,       ['ot_unapplied', 'ot_green', false, true]],
            ['√', false, true,  'approved', [null, 'ot_green', false, true]],
            ['√', false, true,  'declined', ['ot_declined', 'ot_green', false, true]],
            ['√', false, true,  'pending',  [null, 'ot_green', false, true]],
            // worked on a holiday: QCD green unless a claim fill applies; no bold border
            ['√', true,  false, null,       ['ot_unapplied', null, false, false]],
            ['√', true,  false, 'approved', ['festival_worked', 'ot_green', false, false]],
            ['√', true,  false, 'declined', ['ot_declined', null, false, false]],
            ['√', true,  false, 'pending',  ['festival_worked', null, false, false]],
            ['#', true,  false, null,       ['festival_taken', null, false, false]],
            // leave and replacement leave: bold symbol
            ['§', false, false, null,       [null, null, true, false]],
            ['△', false, false, null,       [null, null, true, false]],
            ['▼', false, false, null,       [null, null, false, false]],
            ['/', false, false, null,       [null, null, false, false]],
        ];
        foreach ($table as [$symbol, $holiday, $rest, $ot, $expected]) {
            $m = S::marks($symbol, $holiday, $rest, $ot);
            $this->assertSame($expected, [$m['fill'], $m['font'], $m['bold'], $m['border']], json_encode([$symbol, $holiday, $rest, $ot]));
        }
    }

    public function test_work_under_two_hours_is_not_claimable_so_it_gets_no_yellow(): void
    {
        $this->restDay('p1', '2026-09-09');
        $this->work('p1', '2026-09-09', '09:00', '10:30'); // 1.5 h on a rest day: nothing to claim
        $this->restDay('p2', '2026-09-09');
        $this->work('p2', '2026-09-09', '09:00', '11:00'); // 2 h: a half day is claimable
        $ws = $this->sheet();

        $short = $this->style($ws, 'p1', '2026-09-09');
        $this->assertNull($short['fill'], 'under 2 h: no yellow');
        $this->assertSame('medium', $short['border'], 'it is still rest-day work: the bold border stays');
        $this->assertSame(F::COLOR_OT_UNAPPLIED_FILL, $this->style($ws, 'p2', '2026-09-09')['fill'], '2 h: claimable, not claimed: yellow');
    }

    public function test_replacement_off_is_the_plain_weekly_off_slash_with_a_remark_note(): void
    {
        $this->restDay('p1', '2026-09-05'); // rest day worked
        $this->work('p1', '2026-09-05');
        $this->restDay('p1', '2026-09-12'); // the replacement off
        $this->restDay('p1', '2026-09-19'); // a second rest day worked and replaced
        $this->work('p1', '2026-09-13');
        $this->restDay('p1', '2026-09-13');
        $this->restDay('p1', '2026-09-20');
        $this->app->bind(ReplacementOffSource::class, fn () => new class implements ReplacementOffSource
        {
            public function between(array $employeeIds, CarbonInterface $from, CarbonInterface $to): array
            {
                return [$employeeIds[0] => [
                    ['off' => '2026-09-20', 'worked' => '2026-09-13'],
                    ['off' => '2026-09-12', 'worked' => '2026-09-05'],
                ]];
            }
        });
        $ws = $this->sheet();

        // The replaced date is exactly the weekly-off symbol: no new symbol, not bold.
        foreach (['2026-09-12', '2026-09-20'] as $d) {
            $s = $this->style($ws, 'p1', $d);
            $this->assertSame('/', $s['v'], "$d: the weekly-off symbol");
            $this->assertFalse($s['bold'], "$d: not bold");
            $this->assertNull($s['fill']);
        }
        // The Remark column (AO for this 30-day register) names each replacement, joined with "; ", oldest first.
        $this->assertSame(
            'Replacement off 12 Sep 2026 for rest day worked 05 Sep 2026; Replacement off 20 Sep 2026 for rest day worked 13 Sep 2026',
            $ws->getCell('AO'.$this->row('p1'))->getValue()
        );
        $this->assertNull($ws->getCell('AO'.$this->row('p2'))->getValue(), 'others have no note');
        $this->assertStringNotContainsString(' R ', S::marksLegendText());
    }
}
