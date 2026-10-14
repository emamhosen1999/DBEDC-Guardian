<?php

namespace Tests\Feature\Jurisdiction;

use App\Enums\JurisdictionKind;
use App\Models\Jurisdiction;
use App\Models\JurisdictionMember;
use App\Models\JurisdictionVersion;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\JurisdictionTeamService;
use App\Services\Jurisdiction\JurisdictionVersionService;
use App\Support\Corridor\GeoPolygon;
use Illuminate\Validation\ValidationException;

/** Any number and any kind of jurisdiction, and the whole lifecycle: add, split, merge, retire, delete, restore. */
class JurisdictionLifecycleTest extends JurisdictionTestCase
{
    private function day(int $o): string
    {
        return date('Y-m-d', strtotime(ChainageResolver::today().' '.($o >= 0 ? '+' : '').$o.' day'));
    }

    private function svc(): JurisdictionVersionService
    {
        return app(JurisdictionVersionService::class);
    }

    private function ring(float $lng, float $lat, float $size = 0.02): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat + $size], [$lng, $lat + $size], [$lng, $lat]]]];
    }

    private function facility(array $over = []): array
    {
        return $over + ['kind' => 'facility', 'subtype' => 'toll_plaza', 'code' => 'TP-1', 'name' => 'Toll Plaza One', 'start_m' => 12000, 'end_m' => 12100];
    }

    public function test_a_facility_inside_a_section_is_allowed_but_two_facilities_may_not_overlap(): void
    {
        $this->phases();
        $a = $this->admin();
        $this->svc()->create($this->facility(), $this->employee()->employee_id, $a);          // inside Phase One: a different kind
        try {
            $this->svc()->create($this->facility(['code' => 'TP-2', 'name' => 'Other', 'start_m' => 12050, 'end_m' => 12200]), $this->employee()->employee_id, $a);
            $this->fail('two facilities may not overlap');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Toll Plaza One', $e->errors()['start_m'][0]);
        }
        $this->svc()->create($this->facility(['code' => 'DP-1', 'name' => 'Depot', 'subtype' => 'depot', 'start_m' => 12101, 'end_m' => 12300]), $this->employee()->employee_id, $a);
        $this->assertSame(2, Jurisdiction::where('kind', 'facility')->count());
    }

    public function test_sections_on_different_carriageways_may_share_road_but_not_with_one_serving_both(): void
    {
        $a = $this->admin();
        $in = $this->employee()->employee_id;
        $this->svc()->create(['kind' => 'section', 'carriageway' => 'northbound', 'code' => 'NB-1', 'name' => 'NB service', 'start_m' => 100, 'end_m' => 900], $in, $a);
        $this->svc()->create(['kind' => 'section', 'carriageway' => 'southbound', 'code' => 'SB-1', 'name' => 'SB service', 'start_m' => 200, 'end_m' => 800], $in, $a);
        $this->assertSame(2, Jurisdiction::sections()->count());

        $this->expectException(ValidationException::class);   // a section serving both carriageways conflicts with either
        $this->svc()->create(['kind' => 'section', 'carriageway' => 'both', 'code' => 'BO-1', 'name' => 'Both', 'start_m' => 300, 'end_m' => 400], $in, $a);
    }

    public function test_a_facility_needs_a_subtype_and_a_valid_range(): void
    {
        $this->phases();
        $res = $this->actingAs($this->admin())->postJson('/jurisdictions/add', ['kind' => 'facility', 'code' => 'F-1', 'name' => 'F', 'start_m' => 100, 'end_m' => 50, 'incharge' => $this->employee()->employee_id]);
        $res->assertStatus(422)->assertJsonValidationErrors(['subtype', 'end_m']);
    }

    public function test_an_area_takes_validated_geojson_and_refuses_overlap_with_another_area(): void
    {
        $this->phases();
        $a = $this->admin();
        $in = $this->employee()->employee_id;
        $ok = $this->actingAs($a)->postJson('/jurisdictions/add', ['kind' => 'area', 'code' => 'AR-1', 'name' => 'Yard area', 'incharge' => $in, 'geojson' => json_encode(['type' => 'Feature', 'geometry' => $this->ring(90.40, 23.80)])]);
        $ok->assertCreated();
        $this->assertSame('area', Jurisdiction::where('code', 'AR-1')->first()->kind->value);
        $this->assertSame(['type' => 'Polygon', 'coordinates' => $this->ring(90.40, 23.80)['coordinates']], Jurisdiction::where('code', 'AR-1')->first()->geometry);

        // overlapping area: refused; a disjoint area: fine; an area over a section's road: fine (different kind)
        $this->actingAs($a)->postJson('/jurisdictions/add', ['kind' => 'area', 'code' => 'AR-2', 'name' => 'Overlap', 'incharge' => $in, 'geojson' => json_encode($this->ring(90.41, 23.81))])->assertJsonValidationErrors('geometry');
        $this->actingAs($a)->postJson('/jurisdictions/add', ['kind' => 'area', 'code' => 'AR-3', 'name' => 'Elsewhere', 'incharge' => $in, 'geojson' => json_encode($this->ring(90.50, 23.70))])->assertCreated();
    }

    public function test_geojson_must_be_a_closed_ring_inside_bangladesh(): void
    {
        $in = $this->employee()->employee_id;
        $a = $this->admin();
        $open = ['type' => 'Polygon', 'coordinates' => [[[90.4, 23.8], [90.5, 23.8], [90.5, 23.9], [90.4, 23.9]]]];
        $this->actingAs($a)->postJson('/jurisdictions/add', ['kind' => 'area', 'code' => 'AR-1', 'name' => 'Open', 'incharge' => $in, 'geojson' => json_encode($open)])->assertJsonValidationErrors('geometry');
        $this->actingAs($a)->postJson('/jurisdictions/add', ['kind' => 'area', 'code' => 'AR-2', 'name' => 'Abroad', 'incharge' => $in, 'geojson' => json_encode($this->ring(2.0, 48.0))])->assertJsonValidationErrors('geometry');
        $this->actingAs($a)->postJson('/jurisdictions/add', ['kind' => 'area', 'code' => 'AR-3', 'name' => 'Junk', 'incharge' => $in, 'geojson' => '{nope'])->assertJsonValidationErrors('geometry');
    }

    public function test_geojson_is_simplified_to_a_sane_vertex_count(): void
    {
        $ring = [];
        for ($i = 0; $i < 600; $i++) {
            $t = 2 * M_PI * $i / 600;
            $ring[] = [90.45 + 0.02 * cos($t), 23.8 + 0.02 * sin($t)];
        }
        $ring[] = $ring[0];
        $out = GeoPolygon::normalize(['type' => 'Polygon', 'coordinates' => [$ring]]);
        $this->assertLessThanOrEqual(GeoPolygon::MAX_VERTICES, count($out['coordinates'][0]));
        $this->assertSame($out['coordinates'][0][0], $out['coordinates'][0][count($out['coordinates'][0]) - 1], 'still closed');
    }

    public function test_split_retires_the_old_section_and_attributes_works_before_and_after_the_date(): void
    {
        $p = $this->phases();
        $corrector = $this->employee(self::PERMISSIONS);
        $e = $p['people'][0]->employee_id;
        $deputy = $this->employee();
        app(JurisdictionTeamService::class)->addMember($p['one'], $deputy->employee_id, JurisdictionMember::DEPUTY);
        $before = $this->work('RFI-B', 'K20+500', $this->day(-5), 'completed', $e);
        $after = $this->work('RFI-A', 'K20+500', $this->day(5), 'new', $e);
        $this->assertSame($p['one']->id, $before->refresh()->jurisdiction_id);

        $r = $this->svc()->split($p['one'], 15000, ['code' => 'PH-1A', 'name' => 'Phase One A'], ['code' => 'PH-1B', 'name' => 'Phase One B', 'incharge' => $this->employee()->employee_id], $corrector, $this->day(1), 'Contract change');

        $resolver = app(ChainageResolver::class);
        $this->assertSame($p['one']->id, $resolver->idAt(20500, $this->day(0)), 'before the date the old section still holds the road');
        $this->assertSame($r['second']->id, $resolver->idAt(20500, $this->day(1)));
        $this->assertSame($r['first']->id, $resolver->idAt(14999, $this->day(1)));
        $this->assertSame($r['second']->id, $resolver->idAt(15000, $this->day(2)));
        $this->assertSame($p['one']->id, $before->refresh()->jurisdiction_id, 'history is not rewritten');
        $this->assertSame($r['second']->id, $after->refresh()->jurisdiction_id, 'works from the date on belong to the new section');
        $this->assertTrue($r['first']->start_m === 0 && $r['first']->end_m === 14999 && $r['second']->start_m === 15000 && $r['second']->end_m === 27999);
        // the team is copied to both, and the old one is retired (not deleted)
        foreach ([$r['first'], $r['second']] as $n) {
            $this->assertTrue(JurisdictionMember::where('jurisdiction_id', $n->id)->where('employee_id', $deputy->employee_id)->whereNull('effective_to')->exists());
        }
        $this->assertNotSoftDeleted('jurisdictions', ['id' => $p['one']->id]);
        $this->assertSame($this->day(0), JurisdictionVersion::where('jurisdiction_id', $p['one']->id)->value('valid_to')->toDateString());
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'jurisdiction.split', 'subject_id' => (string) $p['one']->id]);
    }

    public function test_merge_joins_adjacent_sections_and_refuses_the_rest(): void
    {
        $p = $this->phases();
        $a = $this->employee(self::PERMISSIONS);
        $in = $p['people'][0]->employee_id;

        try {
            $this->svc()->merge($p['one'], $p['two'], ['code' => 'X', 'name' => 'X', 'incharge' => $in], $a, $this->day(1));
            $this->fail('Phase One and Phase Two are not adjacent');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('with', $e->errors());
        }
        $w = $this->work('RFI-M', 'K30+000', $this->day(4), 'new', $in);

        $r = $this->svc()->merge($p['one'], $p['three'], ['code' => 'PH-13', 'name' => 'Phase One and Three', 'incharge' => $in], $a, $this->day(2));
        $resolver = app(ChainageResolver::class);
        $this->assertSame($p['three']->id, $resolver->idAt(30000, $this->day(1)));
        $this->assertSame($r['merged']->id, $resolver->idAt(30000, $this->day(2)));
        $this->assertSame($r['merged']->id, $resolver->idAt(5000, $this->day(2)));
        $this->assertSame(0, $r['merged']->start_m);
        $this->assertSame(37999, $r['merged']->end_m);
        $this->assertSame($r['merged']->id, $w->refresh()->jurisdiction_id);
    }

    public function test_retire_hides_it_now_but_it_stays_visible_as_of_past_dates(): void
    {
        $p = $this->phases();
        $actor = $this->employee(self::PERMISSIONS);
        $old = $this->work('RFI-R', 'K05+000', $this->day(-3), 'completed', $p['people'][0]->employee_id);
        $this->svc()->retire($p['one'], $actor, $this->day(1));

        $res = $this->actingAs($actor)->getJson('/jurisdictions/overview?as_of='.$this->day(2))->assertOk();
        $this->assertNotContains($p['one']->id, collect($res->json('jurisdictions'))->pluck('id')->all());
        $this->assertContains($p['one']->id, collect($res->json('archived'))->pluck('id')->all());
        $this->assertSame('retired', collect($res->json('archived'))->firstWhere('id', $p['one']->id)['state']);
        $this->assertGreaterThan(0, $res->json('summary.gaps'), 'a gap in section coverage is reported');

        $past = $this->actingAs($actor)->getJson('/jurisdictions/overview?as_of='.$this->day(0))->assertOk();
        $this->assertContains($p['one']->id, collect($past->json('jurisdictions'))->pluck('id')->all());
        $this->assertSame($p['one']->id, $old->refresh()->jurisdiction_id, 'its works and history stay');
        $this->assertNotSoftDeleted('jurisdictions', ['id' => $p['one']->id]);
    }

    public function test_retire_asks_for_confirmation_of_a_standing_team_and_open_works(): void
    {
        $p = $this->phases();
        $actor = $this->employee(self::PERMISSIONS);
        $this->work('RFI-O', 'K05+000', $this->day(-1), 'new', $p['people'][0]->employee_id);
        app(JurisdictionTeamService::class)->addMember($p['one'], $this->employee()->employee_id, JurisdictionMember::MEMBER);

        try {
            $this->svc()->retire($p['one'], $actor, $this->day(1));
            $this->fail('needs confirmation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirm_team', $e->errors());
        }
        try {
            $this->svc()->retire($p['one'], $actor, $this->day(1), null, true);
            $this->fail('open works need confirmation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirm_open_works', $e->errors());
        }
        $r = $this->svc()->retire($p['one'], $actor, $this->day(1), null, true, true);
        $this->assertSame(1, $r['open_works']);
        $this->assertSame([], JurisdictionMember::where('jurisdiction_id', $p['one']->id)->whereNull('effective_to')->pluck('id')->all(), 'the team rows end, they are not deleted');
        $this->assertGreaterThan(0, JurisdictionMember::where('jurisdiction_id', $p['one']->id)->count());
    }

    public function test_restore_brings_back_a_retired_and_a_deleted_jurisdiction(): void
    {
        $p = $this->phases();
        $actor = $this->employee(self::PERMISSIONS);
        $this->svc()->retire($p['one'], $actor, $this->day(1));
        $restored = $this->svc()->restore($p['one']->id, $actor, $this->day(5), null, $p['people'][0]->employee_id);

        $r = app(ChainageResolver::class);
        $this->assertNull($r->idAt(5000, $this->day(3)), 'retired between');
        $this->assertSame($p['one']->id, $r->idAt(5000, $this->day(5)));
        $this->assertSame($p['one']->id, $r->idAt(5000, $this->day(0)));
        $this->assertSame($p['people'][0]->employee_id, app(JurisdictionTeamService::class)->inChargeAsOf($p['one']->id, $this->day(5)));
        $this->assertSame(JurisdictionKind::Section, $restored->kind);

        // a mistake deleted and restored
        $this->actingAs($actor)->postJson('/jurisdictions/add', ['kind' => 'facility', 'subtype' => 'office', 'code' => 'OF-1', 'name' => 'Office', 'start_m' => 100, 'end_m' => 120, 'incharge' => $this->employee()->employee_id])->assertCreated();
        $f = Jurisdiction::where('code', 'OF-1')->firstOrFail();
        $this->actingAs($actor)->postJson('/jurisdictions/delete', ['id' => $f->id])->assertOk();
        $this->actingAs($actor)->postJson("/jurisdictions/{$f->id}/restore", [])->assertOk();
        $this->assertNotSoftDeleted('jurisdictions', ['id' => $f->id]);
    }

    public function test_each_lifecycle_action_has_its_own_permission(): void
    {
        $p = $this->phases();
        $updater = $this->employee(['jurisdiction.view', 'jurisdiction.update', 'jurisdiction.delete']);
        $this->actingAs($updater)->postJson("/jurisdictions/{$p['one']->id}/split", ['at_m' => 10000, 'first' => ['code' => 'A', 'name' => 'A'], 'second' => ['code' => 'B', 'name' => 'B']])->assertForbidden();
        $this->actingAs($updater)->postJson("/jurisdictions/{$p['one']->id}/merge", ['with' => $p['three']->id])->assertForbidden();
        $this->actingAs($updater)->postJson("/jurisdictions/{$p['one']->id}/retire", [])->assertForbidden();
        $this->actingAs($updater)->postJson("/jurisdictions/{$p['one']->id}/restore", [])->assertForbidden();

        $retirer = $this->employee(['jurisdiction.view', 'jurisdiction.retire']);
        $this->actingAs($retirer)->postJson("/jurisdictions/{$p['two']->id}/retire", ['effective_date' => $this->day(1), 'confirm_team' => true, 'confirm_open_works' => true])->assertOk();
        $this->actingAs($retirer)->postJson("/jurisdictions/{$p['two']->id}/restore", [])->assertForbidden();
        $restorer = $this->employee(['jurisdiction.view', 'jurisdiction.restore']);
        $this->actingAs($restorer)->postJson("/jurisdictions/{$p['two']->id}/restore", ['effective_date' => $this->day(3)])->assertOk();
    }

    public function test_daily_works_are_attributed_to_sections_only(): void
    {
        $p = $this->phases();
        $this->svc()->create($this->facility(['start_m' => 5000, 'end_m' => 5100]), $this->employee()->employee_id, $this->admin());
        $w = $this->work('RFI-F', 'K05+050', $this->day(0), 'new', $p['people'][0]->employee_id);
        $this->assertSame($p['one']->id, $w->refresh()->jurisdiction_id, 'the facility inside the section does not take the work');
        $this->assertSame(1, Jurisdiction::sections()->whereKey($p['one']->id)->count());
        $this->assertSame(3, Jurisdiction::sections()->count());
    }
}
