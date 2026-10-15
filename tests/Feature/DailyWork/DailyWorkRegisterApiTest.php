<?php

namespace Tests\Feature\DailyWork;

use App\Models\DailyWork;
use App\Models\DailyWorkRevision;
use App\Models\Jurisdiction;
use Illuminate\Support\Facades\DB;

/** The register API: scope, filters (status, type, in-charge, jurisdiction, chainage range, dates, search, data quality), sort, paging. */
class DailyWorkRegisterApiTest extends DailyWorkTestCase
{
    private array $p;

    private function seedWorks(): void
    {
        $this->p = $this->phases();
        [$a, $b, $c] = array_map(fn ($u) => $u->employee_id, $this->p['people']);
        $this->work('RFI-001', 'K05+100-K05+200', '2026-05-01', 'new', $a, null, 'Pavement');
        $this->work('RFI-002', 'K12+000', '2026-05-02', 'completed', $a, null, 'Structure');
        $this->work('RFI-003', 'K30+500', '2026-05-03', 'in-progress', $c, $a, 'Embankment');
        $this->work('RFI-004', 'K40+250', '2026-05-04', 'rejected', $b, null, 'Pavement');
        $this->work('RFI-005', 'Main gate', '2026-05-05', 'new', $a, null, 'Structure');
        DB::table('daily_works')->where('number', 'RFI-002')->update(['status' => 'complete']); // the legacy spelling
    }

    private function reg(array $q = [], $as = null)
    {
        return $this->actingAs($as ?? $this->dwAdmin())->getJson(route('dailyWorks.register', $q));
    }

    public function test_the_register_pages_sorts_and_reports_the_total(): void
    {
        $this->seedWorks();
        $res = $this->reg(['per_page' => 5, 'sort' => 'number', 'dir' => 'asc'])->assertOk();
        $this->assertSame(5, $res->json('meta.total'));
        $this->assertSame(['RFI-001', 'RFI-002', 'RFI-003', 'RFI-004', 'RFI-005'], collect($res->json('data'))->pluck('number')->all());
        $first = $this->reg(['per_page' => 5, 'sort' => 'date', 'dir' => 'desc'])->json('data.0');
        $this->assertSame('RFI-005', $first['number']);
        $this->assertSame(['PH-1', 'Phase One'], [$this->reg(['q' => 'RFI-001'])->json('data.0.jurisdiction.code'), $this->reg(['q' => 'RFI-001'])->json('data.0.jurisdiction.name')]);
        for ($i = 0; $i < 7; $i++) {
            $this->work('RFI-P'.$i, 'K01+100', '2026-04-01', 'new', $this->p['people'][0]->employee_id);
        }
        $this->reg(['per_page' => 5])->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 12);
        $this->assertSame(2, count($this->reg(['per_page' => 5, 'page' => 3])->json('data')));
    }

    public function test_every_filter_narrows_by_real_columns(): void
    {
        $this->seedWorks();
        $nums = fn (array $q) => collect($this->reg($q)->json('data'))->pluck('number')->sort()->values()->all();

        $this->assertSame(['RFI-001', 'RFI-005'], $nums(['status' => 'new']));
        $this->assertSame(['RFI-002'], $nums(['status' => 'completed']), 'completed includes the legacy "complete" spelling');
        $this->assertSame(['RFI-003', 'RFI-004'], $nums(['status' => 'in-progress,rejected']));
        $this->assertSame(['RFI-002', 'RFI-005'], $nums(['type' => 'Structure']));
        $this->assertSame(['RFI-001', 'RFI-002', 'RFI-003', 'RFI-005'], $nums(['assigned' => $this->p['people'][0]->employee_id]));
        $this->assertSame(['RFI-004'], $nums(['incharge' => $this->p['people'][1]->employee_id]));
        $this->assertSame(['RFI-003'], $nums(['jurisdiction' => $this->p['three']->id]), 'by the attributed jurisdiction, not by a string');
        $this->assertSame(['RFI-001', 'RFI-002'], $nums(['jurisdiction' => $this->p['one']->id]));
        $this->assertSame(['RFI-002', 'RFI-003'], $nums(['date_from' => '2026-05-02', 'date_to' => '2026-05-03']));
        $this->assertSame(['RFI-001'], $nums(['chain_from' => 'K5+150', 'chain_to' => 'K5+160']), 'a work whose span overlaps the range');
        $this->assertSame(['RFI-002'], $nums(['chain_from' => 'K11+999', 'chain_to' => 12000]));
        $this->assertSame(['RFI-005'], $nums(['quality' => 'unplaced']), 'the unreadable location is a data-quality flag, never guessed');
        $this->assertSame(['RFI-005'], $nums(['quality' => 'unattributed']));
    }

    public function test_multi_word_search_needs_every_word_somewhere(): void
    {
        $this->seedWorks();
        $nums = fn (string $q) => collect($this->reg(['q' => $q])->json('data'))->pluck('number')->sort()->values()->all();
        $this->assertSame(['RFI-004'], $nums('rfi-004 pavement'));
        $this->assertSame(['RFI-001', 'RFI-004'], $nums('pavement rfi'));
        $this->assertSame(['RFI-005'], $nums('main gate'));
        $this->assertSame([], $nums('pavement structure'));
    }

    public function test_a_bad_chainage_or_date_is_a_clear_422(): void
    {
        $this->seedWorks();
        $this->reg(['chain_from' => 'somewhere'])->assertStatus(422)->assertJsonValidationErrors('chain_from');
        $this->reg(['date_from' => '2026-05-09', 'date_to' => '2026-05-01'])->assertStatus(422)->assertJsonValidationErrors('date_to');
        $this->reg(['sort' => 'password'])->assertStatus(422);
    }

    public function test_an_empty_result_says_how_far_the_records_go(): void
    {
        $this->seedWorks();
        $res = $this->reg(['date_from' => '2026-09-01'])->assertOk();
        $this->assertSame(0, $res->json('meta.total'));
        $this->assertSame(['scoped_total' => 5, 'latest_work_date' => '2026-05-05'], $res->json('empty'));
        $this->assertNull($this->reg()->json('empty'));
    }

    public function test_the_register_shows_only_what_the_viewer_may_see(): void
    {
        $this->seedWorks();
        $mine = $this->viewer(['view']); // no role: own works (in-charge or assigned) only
        DB::table('daily_works')->where('number', 'RFI-004')->update(['incharge' => $mine->employee_id]);
        $res = $this->reg([], $mine)->assertOk();
        $this->assertSame(['RFI-004'], collect($res->json('data'))->pluck('number')->all());
        $this->actingAs($this->employee())->getJson(route('dailyWorks.register'))->assertForbidden();
    }

    public function test_rows_carry_the_transitions_and_abilities_the_viewer_has_for_that_row(): void
    {
        $this->seedWorks();
        $admin = $this->dwAdmin();
        $rows = collect($this->reg(['per_page' => 10], $admin)->json('data'))->keyBy('number');
        $this->assertContains('in-progress', $rows['RFI-001']['transitions']);
        $this->assertContains('in-progress', $rows['RFI-002']['transitions'] ?? [], 'the admin holds the re-open permission');
        $this->assertTrue($rows['RFI-001']['can']['update']);

        $viewOnly = $this->viewer(['view']);
        DB::table('daily_works')->update(['incharge' => $viewOnly->employee_id]);
        $row = collect($this->reg(['per_page' => 10], $viewOnly)->json('data'))->first();
        $this->assertSame([], $row['transitions'], 'no status.update: nothing is offered');
        $this->assertFalse($row['can']['update']);
    }

    public function test_deleted_works_are_listed_only_for_those_who_may_restore_and_can_be_restored(): void
    {
        $this->seedWorks();
        $w = DailyWork::where('number', 'RFI-001')->first();
        $w->delete();
        $admin = $this->dwAdmin();
        $this->assertSame(4, $this->reg([], $admin)->json('meta.total'));
        $this->assertSame(['RFI-001'], collect($this->reg(['trashed' => 1], $admin)->json('data'))->pluck('number')->all());

        $noRestore = $this->viewer(['view']);
        DB::table('daily_works')->update(['incharge' => $noRestore->employee_id]);
        $this->assertSame(4, $this->reg(['trashed' => 1], $noRestore)->json('meta.total'), 'the flag is ignored without daily-works.restore');
        $this->actingAs($noRestore)->postJson(route('dailyWorks.restore', $w->id))->assertForbidden();

        $this->actingAs($admin)->postJson(route('dailyWorks.restore', $w->id))->assertOk();
        $this->assertNotSoftDeleted('daily_works', ['id' => $w->id]);
        $this->assertSame(['created', 'deleted', 'restored'], DailyWorkRevision::where('daily_work_id', $w->id)->orderBy('revision_no')->pluck('event')->all());
    }

    public function test_register_runs_a_fixed_number_of_queries_regardless_of_page_size(): void
    {
        $this->seedWorks();
        $admin = $this->dwAdmin();
        $count = function (int $per) use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson(route('dailyWorks.register', ['per_page' => $per]))->assertOk();

            return count(DB::getQueryLog());
        };
        $count(5); // warm the permission and feature-flag caches: the budget is about rows, not cold starts
        $few = $count(5);
        for ($i = 0; $i < 12; $i++) {
            $this->work('RFI-X'.$i, 'K0'.($i % 9).'+100', '2026-05-06', 'new', $this->p['people'][0]->employee_id);
        }
        $this->assertSame($few, $count(25), 'no per-row queries: the budget does not grow with the rows');
        $this->assertLessThanOrEqual(24, $few, 'the register itself is five queries; the rest is the request pipeline');
    }
}
