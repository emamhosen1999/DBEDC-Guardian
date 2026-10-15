<?php

namespace Tests\Feature\Jurisdiction;

use App\Services\Corridor\ChainageResolver;
use App\Services\DailyWork\DailyWorkChainageBackfill;
use App\Services\Jurisdiction\JurisdictionPeriod;
use App\Services\Jurisdiction\JurisdictionStatsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Stats, coverage, backfill idempotency, the overview and print routes, and the legacy location validator. */
class JurisdictionReadsTest extends JurisdictionTestCase
{
    private function day(int $o): string
    {
        return date('Y-m-d', strtotime(ChainageResolver::today().' '.($o >= 0 ? '+' : '').$o.' day'));
    }

    public function test_stats_are_real_aggregates_per_jurisdiction_by_attribution_date(): void
    {
        $p = $this->phases();
        [$a, $b] = [$p['people'][0]->employee_id, $this->employee()->employee_id];
        $this->work('R1', 'K01+000', $this->day(-5), 'completed', $a, $b, 'Pavement');
        $this->work('R2', 'K02+000', $this->day(-4), 'new', $a, $b, 'Structure');
        $r3 = $this->work('R3', 'K03+000', $this->day(-3), 'completed', $a, $a, 'Pavement');
        DB::table('daily_works')->where('id', $r3->id)->update(['status' => 'complete']); // the register carries both spellings (68 rows)
        $this->work('R4', 'K30+000', $this->day(-3), 'new', $p['people'][2]->employee_id);
        $this->work('R-OLD', 'K04+000', $this->day(-100), 'new', $a);       // outside the 30 day period
        $this->work('R-FREE', 'Main gate', $this->day(-2), 'new', $a);       // unreadable: no jurisdiction

        $period = JurisdictionPeriod::make('30d');
        $out = app(JurisdictionStatsService::class)->forPeriod(collect([$p['one'], $p['three']]), $period);
        $one = $out['by_jurisdiction'][$p['one']->id];

        $this->assertSame(3, $one['works']);
        $this->assertSame(2, $one['completed'], 'completed and complete are the same state');
        $this->assertSame(66.7, $one['completion_rate']);
        $this->assertEquals(['completed' => 1, 'new' => 1, 'complete' => 1], $one['by_status']);
        $this->assertEquals(['Pavement' => 2, 'Structure' => 1], $one['by_type']);
        $this->assertSame($this->day(-3), $one['last_activity']);
        $this->assertSame(3, $one['works_by_employee'][$a], 'works recorded under the in-charge');
        $this->assertSame(2, $one['works_by_employee'][$b], 'works assigned to someone else');
        $this->assertSame(1, $out['by_jurisdiction'][$p['three']->id]['works']);
        $this->assertSame(1, $out['unplaced']);
    }

    public function test_coverage_reports_gaps_and_overlaps(): void
    {
        $p = $this->phases();
        $r = app(ChainageResolver::class);
        $full = $r->coverage();
        $this->assertSame(100.0, $full['coverage_pct']);
        $this->assertSame([], $full['gaps']);

        $bands = collect([
            ['id' => 1, 'jurisdiction_id' => 1, 'code' => 'A', 'name' => 'A', 'start_m' => 0, 'end_m' => 9999, 'valid_from' => null, 'valid_to' => null],
            ['id' => 2, 'jurisdiction_id' => 2, 'code' => 'B', 'name' => 'B', 'start_m' => 9000, 'end_m' => 19999, 'valid_from' => null, 'valid_to' => null],
            ['id' => 3, 'jurisdiction_id' => 3, 'code' => 'C', 'name' => 'C', 'start_m' => 30000, 'end_m' => 48999, 'valid_from' => null, 'valid_to' => null],
        ]);
        $c = $r->coverage(null, $bands);
        $this->assertSame([['start_m' => 20000, 'end_m' => 29999, 'length_m' => 10000]], $c['gaps']);
        $this->assertSame(1000, $c['overlaps'][0]['length_m']);
        $this->assertSame(9000, $c['overlaps'][0]['start_m']);
        $this->assertSame(79.6, $c['coverage_pct']);
    }

    public function test_the_backfill_is_idempotent_and_reports_unreadable_locations(): void
    {
        $p = $this->phases();
        $e = $p['people'][0]->employee_id;
        $this->work('B1', 'K05+900-K06+400', $this->day(-1), 'new', $e);
        $this->work('B2', 'K17-396', $this->day(-1), 'new', $e);
        DB::table('daily_works')->update(['start_m' => null, 'end_m' => null, 'jurisdiction_id' => null]);

        $first = app(DailyWorkChainageBackfill::class)->run();
        $this->assertSame(2, $first['scanned']);
        $this->assertSame(1, $first['filled']);
        $this->assertSame(['K17-396' => 1], $first['unparseable']);
        $this->assertSame(5900, (int) DB::table('daily_works')->where('number', 'B1')->value('start_m'));
        $this->assertSame($p['one']->id, (int) DB::table('daily_works')->where('number', 'B1')->value('jurisdiction_id'));
        $this->assertNull(DB::table('daily_works')->where('number', 'B2')->value('start_m'), 'not guessed');

        $second = app(DailyWorkChainageBackfill::class)->run();
        $this->assertSame(0, $second['filled']);
        $this->assertSame(0, $second['attributed']);
    }

    public function test_the_overview_is_as_of_a_date_and_carries_team_stats_map_and_abilities(): void
    {
        $p = $this->phases();
        $res = $this->actingAs($this->admin())->getJson('/jurisdictions/overview?as_of='.$this->day(0).'&period=30d')->assertOk();
        $res->assertJsonPath('summary.count', 3)->assertJsonPath('summary.coverage_pct', 100)->assertJsonPath('summary.gaps', 0)->assertJsonPath('abilities.team', true)
            ->assertJsonPath('jurisdictions.0.code', 'PH-1')->assertJsonPath('jurisdictions.0.team.0.role', 'in_charge');
        $this->assertNotEmpty($res->json('map.alignment') ?? []);

        // before the jurisdictions existed in any dated sense nothing changes (valid_from NULL = since records began)
        $this->actingAs($this->admin())->getJson('/jurisdictions/overview?as_of='.$this->day(-900))->assertOk()->assertJsonPath('summary.count', 3);
        $this->actingAs($this->admin())->getJson('/jurisdictions/overview?as_of=garbage')->assertStatus(422);
    }

    public function test_the_default_period_is_twelve_months_and_an_empty_period_says_when_the_latest_work_was(): void
    {
        // Production: nothing dated after 2026-06-05, so a 30-day default showed every jurisdiction at 0 works.
        $p = $this->phases();
        $this->work('Q1', 'K01+000', $this->day(-130), 'completed', $p['people'][0]->employee_id);

        $default = $this->actingAs($this->admin())->getJson('/jurisdictions/overview?as_of='.$this->day(0))->assertOk();
        $default->assertJsonPath('period.key', '365d')->assertJsonPath('summary.works', 1);

        $this->actingAs($this->admin())->getJson('/jurisdictions/overview?as_of='.$this->day(0).'&period=30d')->assertOk()
            ->assertJsonPath('summary.works', 0)->assertJsonPath('summary.latest_work_date', $this->day(-130));
    }

    public function test_the_print_route_needs_view_and_takes_a_paper_size(): void
    {
        $this->phases();
        $viewer = $this->employee(['jurisdiction.view']);
        $this->actingAs($viewer)->get('/jurisdictions/map/print')->assertOk()->assertInertia(fn ($page) => $page->component('Project/Jurisdictions/JurisdictionMapPrint')->where('paper', 'A3')
            ->has('title_block.generated_at')->where('title_block.data_as_of', $this->day(0))->where('title_block.prepared_by', $viewer->name)->has('title_block.revision')->has('data.map.alignment'));
        $this->actingAs($viewer)->get('/jurisdictions/map/print?paper=A4')->assertOk()->assertInertia(fn ($page) => $page->where('paper', 'A4'));
        $this->actingAs($viewer)->get('/jurisdictions/map/print?paper=A0')->assertSessionHasErrors('paper');
        $this->actingAs($this->employee())->get('/jurisdictions/map/print')->assertForbidden();
    }

    public function test_the_print_payload_carries_no_personal_contact_data(): void
    {
        $this->phases();
        $props = $this->actingAs($this->employee(['jurisdiction.view']))->get('/jurisdictions/map/print')->viewData('page')['props'] ?? [];
        // the page's own data (the shared auth/app props are the framework's, not this feature's)
        $json = json_encode(['data' => $props['data'] ?? null, 'title_block' => $props['title_block'] ?? null]);
        $this->assertNotEmpty($props['data'] ?? null);
        foreach (['"phone"', '"email"', '"address"', '"nid"', 'password'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $json);
        }
    }

    public function test_custom_location_accepts_and_rejects_as_before(): void
    {
        $this->phases();
        $check = fn (string $loc, ?string $date = null) => Validator::make(['location' => $loc] + ($date ? ['date' => $date] : []), ['location' => 'custom_location'])->passes();

        foreach (['K05+900', 'K05+900-K06+400', 'K27+999', 'K28+000', 'K48+999', 'K30', 'K13 TOLL STATION', 'SCK0+260', 'K27+900-K28+100'] as $ok) {
            $this->assertTrue($check($ok), "$ok must be accepted");
        }
        foreach (['somewhere', 'k05+900', 'K49+000', 'K99+100'] as $bad) {
            $this->assertFalse($check($bad), "$bad must be rejected");
        }
        $v = Validator::make(['location' => 'K99+100'], ['location' => 'custom_location']);
        $v->passes();
        $this->assertSame('The location must have a valid jurisdiction for the specified chainage: K99+100', $v->errors()->first('location'));
        $v = Validator::make(['location' => 'nowhere'], ['location' => 'custom_location']);
        $v->passes();
        $this->assertSame('DailyWork has an invalid location format: nowhere', $v->errors()->first('location'));
    }
}
