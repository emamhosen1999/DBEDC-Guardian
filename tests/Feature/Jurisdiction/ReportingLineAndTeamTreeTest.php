<?php

namespace Tests\Feature\Jurisdiction;

use App\Models\EmployeeReportingLine;
use App\Models\JurisdictionMember;
use App\Models\User;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\JurisdictionTeamResolver;
use App\Services\Jurisdiction\JurisdictionTeamService;
use App\Services\Org\ReportingLineService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** The team is the whole reporting hierarchy under the in-charge, with a recorded history of reporting lines. */
class ReportingLineAndTeamTreeTest extends JurisdictionTestCase
{
    private function day(int $o): string
    {
        return date('Y-m-d', strtotime(ChainageResolver::today().' '.($o >= 0 ? '+' : '').$o.' day'));
    }

    private function under(User $boss, array $attrs = []): User
    {
        return $this->employee([], $attrs + ['report_to' => $boss->employee_id]);
    }

    private function flat(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $out[$n['employee_id']] = $n;
            $out += $this->flat($n['children']);
        }

        return $out;
    }

    private function overview(User $viewer, ?string $asOf = null)
    {
        return $this->actingAs($viewer)->getJson('/jurisdictions/overview?period=all&as_of='.($asOf ?? $this->day(0)))->assertOk();
    }

    public function test_a_change_closes_the_old_line_and_opens_a_new_one(): void
    {
        $a = $this->employee();
        $b = $this->employee();
        $c = $this->under($a);
        $this->assertSame([null], EmployeeReportingLine::where('employee_id', $c->employee_id)->pluck('valid_from')->map(fn ($d) => $d?->toDateString())->all(), 'the first line is "since records began": no date invented');

        // a later change: simulate time by opening the line in the past, then change it today
        EmployeeReportingLine::where('employee_id', $c->employee_id)->update(['valid_from' => $this->day(-10)]);
        $c->report_to = $b->employee_id;
        $c->save();

        $rows = EmployeeReportingLine::where('employee_id', $c->employee_id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($this->day(-1), $rows[0]->valid_to->toDateString());
        $this->assertSame($b->employee_id, $rows[1]->reports_to);
        $this->assertNull($rows[1]->valid_to);

        $svc = app(ReportingLineService::class);
        $this->assertSame($a->employee_id, $svc->mapAsOf($this->day(-5))[$c->employee_id], 'before the change');
        $this->assertSame($b->employee_id, $svc->mapAsOf($this->day(0))[$c->employee_id]);
        $this->assertSame($a->employee_id, $svc->mapAsOf($this->day(-1))[$c->employee_id], 'the day before: valid_to is inclusive');

        $c->report_to = null;
        $c->save();
        $this->assertArrayNotHasKey($c->employee_id, $svc->mapAsOf($this->day(1)), 'no manager: no open line');
    }

    public function test_a_second_change_on_the_same_day_corrects_the_row_instead_of_adding_one(): void
    {
        $a = $this->employee();
        $b = $this->employee();
        $c = $this->under($a);
        EmployeeReportingLine::where('employee_id', $c->employee_id)->update(['valid_from' => $this->day(0)]);
        $c->report_to = $b->employee_id;
        $c->save();
        $this->assertSame(1, EmployeeReportingLine::where('employee_id', $c->employee_id)->count());
        $this->assertSame($b->employee_id, EmployeeReportingLine::where('employee_id', $c->employee_id)->value('reports_to'));
    }

    public function test_a_bulk_write_that_bypasses_events_is_recorded_by_reconcile(): void
    {
        $a = $this->employee();
        $b = $this->employee();
        $c = $this->under($a);
        DB::table('users')->where('employee_id', $c->employee_id)->update(['report_to' => $b->employee_id]); // no model event
        $this->assertSame($a->employee_id, EmployeeReportingLine::where('employee_id', $c->employee_id)->whereNull('valid_to')->value('reports_to'));

        $this->assertSame(1, app(ReportingLineService::class)->reconcile());
        $this->assertSame($b->employee_id, EmployeeReportingLine::where('employee_id', $c->employee_id)->whereNull('valid_to')->value('reports_to'));
        $this->assertSame(0, app(ReportingLineService::class)->reconcile(), 'a second pass changes nothing');
    }

    public function test_the_seed_migration_is_idempotent_and_invents_no_dates(): void
    {
        $a = $this->employee();
        $c = $this->under($a);
        EmployeeReportingLine::query()->delete();
        $migrate = function (): void {
            $m = app('migrator');
            $r = new \ReflectionMethod($m, 'resolvePath');
            $r->setAccessible(true);
            $r->invoke($m, base_path('database/migrations/2026_10_14_000007_create_employee_reporting_lines_table.php'))->up();
        };
        $migrate();
        $migrate();
        $rows = EmployeeReportingLine::where('employee_id', $c->employee_id)->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->valid_from);
        $this->assertSame($a->employee_id, $rows[0]->reports_to);
    }

    public function test_the_team_is_the_in_charge_plus_everyone_reporting_to_them_recursively_active_staff_only(): void
    {
        $p = $this->phases();
        $boss = $p['people'][0];
        $direct = $this->under($boss, ['name' => 'Direct Report']);
        $nested = $this->under($direct, ['name' => 'Nested Report']);
        $left = $this->under($boss, ['name' => 'Inactive Report', 'is_active' => false]);
        $beneathLeft = $this->under($left, ['name' => 'Beneath Inactive']);
        $stranger = $this->employee([], ['name' => 'Elsewhere']);

        $res = $this->overview($this->admin());
        $team = $this->flat(collect($res->json('jurisdictions'))->firstWhere('id', $p['one']->id)['team']);

        $this->assertSame('in_charge', $team[$boss->employee_id]['position']);
        $this->assertSame('reporting', $team[$direct->employee_id]['position']);
        $this->assertArrayHasKey($nested->employee_id, $team, 'indirect reports are on the team');
        $this->assertArrayNotHasKey($left->employee_id, $team, 'inactive staff are not');
        $this->assertArrayNotHasKey($beneathLeft->employee_id, $team, 'and their reports hang off nobody here');
        $this->assertArrayNotHasKey($stranger->employee_id, $team);
        $kpis = collect($res->json('jurisdictions'))->firstWhere('id', $p['one']->id)['team_kpis'];
        $this->assertSame(3, $kpis['headcount']);
    }

    public function test_a_reporting_loop_shows_each_person_once_with_a_warning(): void
    {
        $p = $this->phases();
        $boss = $p['people'][0];
        $x = $this->under($boss);
        $y = $this->under($x);
        $x->report_to = $y->employee_id;  // x -> y -> x : a loop that no longer reaches the in-charge
        $x->save();
        $z = $this->under($boss);
        DB::table('users')->where('employee_id', $boss->employee_id)->update(['report_to' => $z->employee_id]); // boss -> z -> boss

        $res = $this->overview($this->admin());
        $row = collect($res->json('jurisdictions'))->firstWhere('id', $p['one']->id);
        $ids = array_keys($this->flat($row['team']));
        $this->assertSame(count($ids), count(array_unique($ids)), 'nobody twice');
        $this->assertGreaterThan(0, $row['team_warnings']);
        $this->assertNotEmpty($row['team'][0]['warnings']);
    }

    public function test_an_assigned_member_outside_the_line_is_marked_and_one_inside_it_is_not_counted_twice(): void
    {
        $p = $this->phases();
        $boss = $p['people'][0];
        $inLine = $this->under($boss);
        $outside = $this->employee([], ['name' => 'Surveyor on loan']);
        $team = app(JurisdictionTeamService::class);
        $team->addMember($p['one'], $inLine->employee_id, JurisdictionMember::DEPUTY);
        $team->addMember($p['one'], $outside->employee_id, JurisdictionMember::MEMBER);

        $res = $this->overview($this->admin());
        $row = collect($res->json('jurisdictions'))->firstWhere('id', $p['one']->id);
        $flat = $this->flat($row['team']);
        $this->assertSame('reporting', $flat[$inLine->employee_id]['position']);
        $this->assertSame('deputy_in_charge', $flat[$inLine->employee_id]['assigned_role'], 'in the line AND assigned: one node, marked');
        $this->assertSame('assigned', $flat[$outside->employee_id]['position']);
        $this->assertSame(3, $row['team_kpis']['headcount'], 'in-charge, the report and the loaned surveyor: three people');
        $this->assertSame(1, collect(array_keys($flat))->filter(fn ($id) => $id === $inLine->employee_id)->count());
    }

    public function test_attendance_status_comes_from_leave_punches_and_the_roster(): void
    {
        $p = $this->phases();
        $boss = $p['people'][0];
        $present = $this->under($boss);
        $onLeave = $this->under($boss);
        $absent = $this->under($boss);
        $off = $this->under($boss);
        $d = $this->day(0);
        DB::table('attendances')->insert(['user_id' => $present->employee_id, 'date' => $d, 'punchin' => $d.' 08:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('roster_days')->insert([['user_id' => $present->employee_id, 'date' => $d, 'shift_id' => 1, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()], ['user_id' => $absent->employee_id, 'date' => $d, 'shift_id' => 1, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('leaves')->insert(['user_id' => $onLeave->employee_id, 'leave_type' => 1, 'from_date' => $this->day(-1), 'to_date' => $this->day(1), 'no_of_days' => 3, 'reason' => 'x', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

        $row = collect($this->overview($this->admin())->json('jurisdictions'))->firstWhere('id', $p['one']->id);
        $flat = $this->flat($row['team']);
        $this->assertSame('present', $flat[$present->employee_id]['attendance']['status']);
        $this->assertSame('on_leave', $flat[$onLeave->employee_id]['attendance']['status']);
        $this->assertSame('absent', $flat[$absent->employee_id]['attendance']['status']);
        $this->assertSame('not_rostered', $flat[$off->employee_id]['attendance']['status']);
        $k = $row['team_kpis'];
        $this->assertSame([5, 1, 1, 1], [$k['headcount'], $k['present'], $k['on_leave'], $k['absent']]);
    }

    public function test_contact_details_follow_the_directory_permission_and_the_print_carries_none(): void
    {
        $p = $this->phases();
        $report = $this->under($p['people'][0], ['phone' => '+8801700000001', 'email' => 'report@example.test']);
        Permission::findOrCreate('employees.view', 'web');

        Role::findOrCreate('Super Administrator', 'web');
        $with = $this->employee(['jurisdiction.view', 'employees.view']);
        $with->assignRole('Super Administrator'); // a global actor sees every directory entry
        $without = $this->employee(['jurisdiction.view', 'employees.view']); // has the permission but not the scope: sees no contact of someone outside it
        $teamOf = fn ($u) => $this->flat(collect($this->overview($u)->json('jurisdictions'))->firstWhere('id', $p['one']->id)['team']);

        $this->assertSame('report@example.test', $teamOf($with)[$report->employee_id]['contact']['email'] ?? null);
        $this->assertNull($teamOf($without)[$report->employee_id]['contact'], 'never widened beyond the directory scope');

        $props = $this->actingAs($with)->get('/jurisdictions/map/print')->viewData('page')['props'];
        $json = json_encode(['data' => $props['data'], 'tb' => $props['title_block']]);
        $this->assertStringNotContainsString('report@example.test', $json);
        $this->assertStringNotContainsString('+8801700000001', $json);
    }

    public function test_as_of_a_past_date_uses_the_recorded_line_and_says_when_recording_began(): void
    {
        $p = $this->phases();
        $boss = $p['people'][0];
        $a = $this->under($boss);
        $b = $this->employee();
        // a manager change recorded 10 days ago: a reported to boss until then, then to b
        EmployeeReportingLine::where('employee_id', $a->employee_id)->update(['valid_from' => $this->day(-30), 'created_at' => now()->subDays(40)]);
        $a->report_to = $b->employee_id;
        $a->save();

        $now = $this->flat(collect($this->overview($this->admin())->json('jurisdictions'))->firstWhere('id', $p['one']->id)['team']);
        $this->assertArrayNotHasKey($a->employee_id, $now, 'today a reports to b, outside this team');
        $past = collect($this->overview($this->admin(), $this->day(-5))->json('jurisdictions'))->firstWhere('id', $p['one']->id);
        $this->assertArrayHasKey($a->employee_id, $this->flat($past['team']), 'five days ago a still reported to the in-charge');

        EmployeeReportingLine::query()->update(['created_at' => now()]);
        $old = collect($this->overview($this->admin(), $this->day(-400))->json('jurisdictions'))->firstWhere('id', $p['one']->id);
        $this->assertStringContainsString('Reporting lines are recorded from '.$this->day(0), $old['team_note']);
        $this->assertNull(collect($this->overview($this->admin())->json('jurisdictions'))->firstWhere('id', $p['one']->id)['team_note']);
    }

    public function test_the_tree_is_built_from_one_staff_query_not_one_per_person(): void
    {
        $p = $this->phases();
        $boss = $p['people'][0];
        $level = $boss;
        for ($i = 0; $i < 6; $i++) {
            $level = $this->under($level);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(JurisdictionTeamResolver::class)->resolve(collect([$p['one']]), [$p['one']->id => app(JurisdictionTeamService::class)->teamAsOf($p['one'])], [], ChainageResolver::today(), null);
        $staffQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "users"') && str_contains($q['query'], 'report_to'))->count();
        $this->assertSame(1, $staffQueries, 'one query of the active staff, assembled in memory');
    }
}
