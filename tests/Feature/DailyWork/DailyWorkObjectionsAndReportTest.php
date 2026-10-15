<?php

namespace Tests\Feature\DailyWork;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use Illuminate\Support\Facades\Schema;

class DailyWorkObjectionsAndReportTest extends DailyWorkTestCase
{
    private function objection(string $title, string $status, string $from, ?string $to = null): RfiObjection
    {
        $attrs = ['title' => $title, 'category' => 'other', 'type' => 'chainage_based', 'description' => 'd', 'reason' => 'Because '.$title, 'status' => $status, 'created_by' => $this->dwAdmin()->employee_id];
        if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
            $this->work('RFI-LEG'.uniqid(), 'K01+100', '2026-05-01', 'new', $this->employee()->employee_id, null, 'Pavement'); // the sqlite schema still carries the legacy column
            $attrs['daily_work_id'] = DailyWork::query()->latest('id')->value('id');
        }
        $o = (new RfiObjection)->forceFill($attrs);
        $o->save();
        $to === null ? $o->syncChainages([$from]) : $o->syncChainages([], $from, $to);

        return $o;
    }

    public function test_the_objections_register_filters_by_chainage_with_the_shared_parser(): void
    {
        $a = $this->objection('Culvert clash', 'submitted', 'K12+300');
        $this->objection('Embankment slope', 'draft', 'K30+000', 'K31+000');
        $admin = $this->dwAdmin();
        $ids = fn (array $q) => collect($this->actingAs($admin)->getJson(route('dailyWorks.objectionsRegister', $q))->assertOk()->json('data'))->pluck('title')->all();

        $this->assertEqualsCanonicalizing(['Culvert clash', 'Embankment slope'], $ids([]));
        $this->assertSame(['Culvert clash'], $ids(['chainage' => 'K12+300']));
        $this->assertSame(['Culvert clash'], $ids(['chainage' => 'K 12 + 300']), 'the spaced spelling reads the same');
        $this->assertSame(['Embankment slope'], $ids(['chainage' => 'K30+500']), 'a point inside a range matches');
        $this->assertSame([], $ids(['chainage' => 'K40+000']));
        $this->assertSame(['Culvert clash'], $ids(['status' => 'submitted']));
        $this->assertSame(['Culvert clash'], $ids(['q' => 'culvert']));
        $this->actingAs($admin)->getJson(route('dailyWorks.objectionsRegister', ['chainage' => 'nowhere']))->assertStatus(422);
        $this->assertSame(1, $this->actingAs($admin)->getJson(route('dailyWorks.objectionsRegister'))->json('counts.draft'));
        $this->assertNotNull($a->id);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_inspection_report_is_scoped_and_lists_the_day_with_totals(): void
    {
        $p = $this->phases();
        $a = $p['people'][0]->employee_id;
        $this->work('RFI-D1', 'K05+100', '2026-05-04', 'new', $a, null, 'Pavement');
        $this->work('RFI-D2', 'K06+000', '2026-05-04', 'completed', $a, null, 'Structure');
        $this->work('RFI-D3', 'K05+100', '2026-05-05', 'new', $a, null, 'Pavement');
        $admin = $this->dwAdmin();

        $this->actingAs($admin)->get(route('dailyWorks.report.inspection', ['date' => '2026-05-04', 'jurisdiction' => $p['one']->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Project/DailyWorks/InspectionReportPrint')
                ->where('total', 2)->where('truncated', 0)->has('works', 2)->where('status_totals.new', 1)->where('status_totals.completed', 1)
                ->where('jurisdiction.code', 'PH-1')->has('team')->where('title_block.revision', 'DIR-20260504-PH-1'));
        $this->actingAs($admin)->get(route('dailyWorks.report.inspection', ['date' => 'tomorrow']))->assertSessionHasErrors('date');
        $this->actingAs($this->employee())->get(route('dailyWorks.report.inspection', ['date' => '2026-05-04']))->assertForbidden();
        $this->actingAs($admin)->get(route('dailyWorks.report.inspection', ['date' => '2026-05-04']))
            ->assertInertia(fn ($page) => $page->where('total', 2)->where('jurisdiction', null));
    }
}
