<?php

namespace Tests\Feature\Jurisdiction;

use App\Models\Jurisdiction;
use App\Models\JurisdictionVersion;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\HistoryGuard;
use App\Services\Jurisdiction\JurisdictionAttribution;
use App\Services\Jurisdiction\JurisdictionVersionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Versioned boundaries: as-of resolution, temporal and spatial rules, retroactive corrections, re-attribution. */
class JurisdictionHistoryTest extends JurisdictionTestCase
{
    private function today(): string
    {
        return ChainageResolver::today();
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime($this->today().' '.($offset >= 0 ? '+' : '').$offset.' day'));
    }

    public function test_a_boundary_change_closes_the_version_and_opens_a_new_one_valid_the_next_day(): void
    {
        $p = $this->phases();
        $admin = $this->admin();
        $svc = app(JurisdictionVersionService::class);

        // Phase One shrinks to K25+999 and Phase Three grows to start at K26 - from a planned date, so it is a plain change.
        $effective = $this->day(10);
        $svc->change($p['one'], ['code' => 'PH-1', 'name' => 'Phase One', 'start_m' => 0, 'end_m' => 25999], $admin, $effective);
        $svc->change($p['three'], ['code' => 'PH-3', 'name' => 'Phase Three', 'start_m' => 26000, 'end_m' => 37999], $admin, $effective);

        $v = JurisdictionVersion::where('jurisdiction_id', $p['one']->id)->orderBy('id')->get();
        $this->assertCount(2, $v);
        $this->assertSame($this->day(9), $v[0]->valid_to->toDateString(), 'the old version ends the day before');
        $this->assertSame($effective, $v[1]->valid_from->toDateString());
        $this->assertNull($v[1]->valid_to);

        $r = app(ChainageResolver::class);
        // adjacent days at the boundary: before the date the old boundary holds, on the date the new one does
        $this->assertSame($p['one']->id, $r->idAt(27000, $this->day(9)));
        $this->assertSame($p['three']->id, $r->idAt(27000, $effective));
        $this->assertSame($p['three']->id, $r->idAt(27000, $this->day(11)));
        $this->assertSame($p['one']->id, $r->idAt(27000, $this->day(-400)), 'history before the change is untouched');
        // and the mirror on the identity row is still today's definition until the date arrives
        $this->assertSame(27999, $p['one']->refresh()->end_m);
    }

    public function test_versions_of_one_jurisdiction_never_overlap_and_a_change_cannot_predate_the_current_one(): void
    {
        $p = $this->phases();
        $svc = app(JurisdictionVersionService::class);
        $admin = $this->admin();
        $svc->change($p['one'], ['code' => 'PH-1', 'name' => 'Phase One', 'start_m' => 0, 'end_m' => 26999], $admin, $this->day(5));

        $this->expectException(ValidationException::class);
        $svc->change($p['one'], ['code' => 'PH-1', 'name' => 'Phase One', 'start_m' => 0, 'end_m' => 25999], $admin, $this->day(5));
    }

    public function test_live_versions_of_different_jurisdictions_never_overlap_in_space(): void
    {
        $p = $this->phases();
        try {
            app(JurisdictionVersionService::class)->change($p['one'], ['code' => 'PH-1', 'name' => 'Phase One', 'start_m' => 0, 'end_m' => 29999], $this->admin(), $this->day(3));
            $this->fail('Overlap with Phase Three must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Phase Three', $e->errors()['start_m'][0]);
        }
        $this->assertSame(1, JurisdictionVersion::where('jurisdiction_id', $p['one']->id)->count(), 'nothing was written');
    }

    public function test_a_spatial_clash_only_matters_while_the_dates_overlap(): void
    {
        $p = $this->phases();
        $svc = app(JurisdictionVersionService::class);
        $admin = $this->admin();
        // Phase Three retires on day+5; a new definition of Phase One may take its road from day+5 onward.
        $svc->retire($p['three'], $admin, $this->day(5));
        $svc->change($p['one'], ['code' => 'PH-1', 'name' => 'Phase One', 'start_m' => 0, 'end_m' => 37999], $admin, $this->day(5));
        $this->assertSame($p['one']->id, app(ChainageResolver::class)->idAt(30000, $this->day(6)));
        $this->assertSame($p['three']->id, app(ChainageResolver::class)->idAt(30000, $this->day(4)), 'the retired phase still owns its past');
    }

    public function test_a_retroactive_change_needs_the_permission_and_a_reason(): void
    {
        $p = $this->phases();
        $svc = app(JurisdictionVersionService::class);
        $editor = $this->employee(['jurisdiction.update']); // can update, cannot correct history
        $data = ['code' => 'PH-1', 'name' => 'Phase One', 'start_m' => 0, 'end_m' => 26999];

        try {
            $svc->change($p['one'], $data, $editor, $this->day(-3), 'Survey correction');
            $this->fail('A back-dated change without jurisdiction.history.correct must be refused');
        } catch (AuthorizationException) {
            $this->assertSame(1, JurisdictionVersion::where('jurisdiction_id', $p['one']->id)->count());
        }

        $corrector = $this->employee(['jurisdiction.update', 'jurisdiction.history.correct']);
        try {
            $svc->change($p['one'], $data, $corrector, $this->day(-3), '  ');
            $this->fail('A reason is mandatory');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $svc->change($p['one'], $data, $corrector, $this->day(-3), 'Survey correction K27+000 to K27+999');
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'jurisdiction.history_corrected', 'subject_id' => (string) $p['one']->id]);
    }

    public function test_the_guard_accepts_future_and_today_without_the_permission(): void
    {
        $nobody = $this->employee();
        $this->assertFalse(HistoryGuard::check($nobody, $this->day(7), null)['correction']);
        $this->assertFalse(HistoryGuard::check($nobody, null, null)['correction']);
    }

    public function test_work_attribution_is_as_of_the_work_date_and_reattribution_touches_only_the_affected_range(): void
    {
        $p = $this->phases();
        $incharge = $p['people'][0]->employee_id;
        // K27+500 belongs to Phase One until the boundary moves; works on both sides of the change date.
        $old = $this->work('RFI-OLD', 'K27+500', $this->day(-20), 'completed', $incharge);
        $this->assertSame($p['one']->id, $old->refresh()->jurisdiction_id);

        $corrector = $this->employee(['jurisdiction.update', 'jurisdiction.history.correct']);
        $svc = app(JurisdictionVersionService::class);
        $svc->change($p['one'], ['code' => 'PH-1', 'name' => 'Phase One', 'start_m' => 0, 'end_m' => 26999], $corrector, $this->day(-10), 'Boundary moved');
        $svc->change($p['three'], ['code' => 'PH-3', 'name' => 'Phase Three', 'start_m' => 27000, 'end_m' => 37999], $corrector, $this->day(-10), 'Boundary moved');

        $recent = $this->work('RFI-NEW', 'K27+500', $this->day(-2), 'new', $incharge);
        $this->assertSame($p['three']->id, $recent->refresh()->jurisdiction_id, 'a work after the change is attributed with the new boundary');
        $this->assertSame($p['one']->id, $old->refresh()->jurisdiction_id, 'a work before the change keeps what was true then');
        $this->assertSame($incharge, $old->refresh()->incharge, 'the recorded in-charge is never rewritten');

        // Idempotent: a second run over everything changes nothing.
        $this->assertSame(0, app(JurisdictionAttribution::class)->run(null, null, true)['changed']);

        // Break one row inside the range and one outside; a scoped run fixes only the one inside.
        \DB::table('daily_works')->where('id', $old->id)->update(['jurisdiction_id' => 999]);
        \DB::table('daily_works')->where('id', $recent->id)->update(['jurisdiction_id' => 999]);
        $dry = app(JurisdictionAttribution::class)->run($this->day(-5), null, false);
        $this->assertSame(1, $dry['changed']);
        $this->assertSame(999, $recent->refresh()->jurisdiction_id, 'a dry run writes nothing');
        app(JurisdictionAttribution::class)->run($this->day(-5), null, true);
        $this->assertSame($p['three']->id, $recent->refresh()->jurisdiction_id);
        $this->assertSame(999, $old->refresh()->jurisdiction_id, 'outside the range stays as it was');
    }

    public function test_a_legacy_direct_write_keeps_a_version_in_step(): void
    {
        $j = Jurisdiction::create(['code' => 'X-1', 'location' => 'Legacy', 'start_chainage' => 'K1+000', 'end_chainage' => 'K2+000', 'incharge' => $this->employee()->employee_id]);
        $this->assertSame(1, JurisdictionVersion::where('jurisdiction_id', $j->id)->count());
        $this->assertSame(1000, $j->start_m);
        $this->assertNull(JurisdictionVersion::where('jurisdiction_id', $j->id)->first()->valid_from, 'a record with no known start has none: dates are never invented');
    }
}
