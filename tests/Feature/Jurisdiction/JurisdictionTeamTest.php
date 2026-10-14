<?php

namespace Tests\Feature\Jurisdiction;

use App\Models\JurisdictionHandover;
use App\Models\JurisdictionMember;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\JurisdictionTeamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Effective-dated teams: one in-charge, history closed and never deleted, planned and back-dated changes, handovers. */
class JurisdictionTeamTest extends JurisdictionTestCase
{
    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(ChainageResolver::today().' '.($offset >= 0 ? '+' : '').$offset.' day'));
    }

    private function team(): JurisdictionTeamService
    {
        return app(JurisdictionTeamService::class);
    }

    public function test_changing_the_in_charge_closes_the_old_row_keeps_history_and_mirrors_incharge(): void
    {
        $p = $this->phases();
        $new = $this->employee();
        $actor = $this->admin();

        $this->team()->setInCharge($p['one'], $new->employee_id, $actor, JurisdictionMember::DEPUTY);

        $rows = JurisdictionMember::where('jurisdiction_id', $p['one']->id)->orderBy('id')->get();
        $this->assertCount(3, $rows, 'nothing is deleted: old in-charge row, new in-charge row, the old in-charge now a deputy');
        $this->assertSame($this->day(-1), $rows[0]->effective_to->toDateString());
        $this->assertSame(1, $rows->where('role', JurisdictionMember::IN_CHARGE)->whereNull('effective_to')->count(), 'exactly one open in-charge');
        $this->assertSame($new->employee_id, $p['one']->refresh()->incharge);
        $this->assertSame($p['people'][0]->employee_id, $rows->firstWhere('role', JurisdictionMember::DEPUTY)->employee_id);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'jurisdiction.team.in_charge_changed', 'subject_id' => (string) $p['one']->id]);
    }

    public function test_the_database_refuses_a_second_open_in_charge_and_a_second_open_role(): void
    {
        $p = $this->phases();
        $other = $this->employee();
        $this->expectException(QueryException::class);
        JurisdictionMember::create(['jurisdiction_id' => $p['one']->id, 'employee_id' => $other->employee_id, 'role' => JurisdictionMember::IN_CHARGE, 'effective_from' => $this->day(0)]);
    }

    public function test_an_employee_cannot_hold_two_roles_in_one_jurisdiction(): void
    {
        $p = $this->phases();
        $e = $this->employee();
        $this->team()->addMember($p['one'], $e->employee_id, JurisdictionMember::MEMBER);
        $this->expectException(ValidationException::class);
        $this->team()->addMember($p['one'], $e->employee_id, JurisdictionMember::DEPUTY);
    }

    public function test_the_in_charge_cannot_simply_leave(): void
    {
        $p = $this->phases();
        $row = JurisdictionMember::where('jurisdiction_id', $p['one']->id)->first();
        $this->expectException(ValidationException::class);
        $this->team()->endMembership($row);
    }

    public function test_a_member_leaves_by_closing_their_row(): void
    {
        $p = $this->phases();
        $e = $this->employee();
        $row = $this->team()->addMember($p['one'], $e->employee_id, JurisdictionMember::MEMBER, null, $this->day(-0));
        $this->team()->endMembership($row, null, $this->day(4));
        $this->assertSame($this->day(3), $row->refresh()->effective_to->toDateString());
        $this->assertTrue(JurisdictionMember::whereKey($row->id)->exists(), 'the row is kept');
        $ids = fn (string $d) => $this->team()->teamAsOf($p['one'], $d)->pluck('employee_id')->all();
        $this->assertContains($e->employee_id, $ids($this->day(3)));
        $this->assertNotContains($e->employee_id, $ids($this->day(4)));
    }

    public function test_a_planned_handover_takes_effect_on_its_date_by_date_range_not_by_a_job(): void
    {
        $p = $this->phases();
        $old = $p['people'][0]->employee_id;
        $new = $this->employee();
        $this->team()->setInCharge($p['one'], $new->employee_id, $this->admin(), null, $this->day(7));

        $this->assertSame($old, $this->team()->inChargeAsOf($p['one']->id, $this->day(6)));
        $this->assertSame($new->employee_id, $this->team()->inChargeAsOf($p['one']->id, $this->day(7)));
        $this->assertSame($old, $this->team()->inChargeAsOf($p['one']->id), 'today it is still the old in-charge');
        $this->assertSame($old, $p['one']->refresh()->incharge, 'the mirror follows today, not the plan');
    }

    public function test_a_back_dated_team_change_needs_the_history_permission_and_a_reason(): void
    {
        $p = $this->phases();
        $new = $this->employee();
        $manager = $this->employee(['jurisdiction.team.manage']);
        try {
            $this->team()->setInCharge($p['one'], $new->employee_id, $manager, null, $this->day(-5), 'Handed over last week');
            $this->fail('needs jurisdiction.history.correct');
        } catch (AuthorizationException) {
        }
        $corrector = $this->employee(['jurisdiction.team.manage', 'jurisdiction.history.correct']);
        try {
            $this->team()->setInCharge($p['one'], $new->employee_id, $corrector, null, $this->day(-5), null);
            $this->fail('needs a reason');
        } catch (ValidationException) {
        }
        $this->team()->setInCharge($p['one'], $new->employee_id, $corrector, null, $this->day(-5), 'Handed over last week');
        $row = JurisdictionMember::where('employee_id', $new->employee_id)->first();
        $this->assertTrue($row->is_correction);
        $this->assertSame('Handed over last week', $row->reason);
        $this->assertSame($new->employee_id, $this->team()->inChargeAsOf($p['one']->id, $this->day(-5)));
        $this->assertSame($p['people'][0]->employee_id, $this->team()->inChargeAsOf($p['one']->id, $this->day(-6)));
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'jurisdiction.team.history_corrected']);
    }

    public function test_the_handover_snapshots_open_works_and_is_immutable(): void
    {
        $p = $this->phases();
        $old = $p['people'][0]->employee_id;
        $open = $this->work('RFI-1', 'K05+100', $this->day(-3), 'new', $old);
        $this->work('RFI-2', 'K05+200', $this->day(-3), 'completed', $old);
        $new = $this->employee();

        $handover = $this->team()->setInCharge($p['one'], $new->employee_id, $this->admin())['handover'];
        $this->assertSame(1, $handover->open_works_count);
        $this->assertSame([$open->id], $handover->open_work_ids);

        // later work does not change the snapshot, and the row cannot be edited or deleted
        $this->work('RFI-3', 'K05+300', $this->day(0), 'new', $new->employee_id);
        $this->assertSame([$open->id], $handover->fresh()->open_work_ids);
        try {
            $handover->update(['note' => 'x']);
            $this->fail('immutable');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $handover->delete();
    }

    public function test_moving_open_works_is_explicit_previewed_audited_and_never_touches_completed_works(): void
    {
        $p = $this->phases();
        $old = $p['people'][0]->employee_id;
        $open = $this->work('RFI-1', 'K05+100', $this->day(-3), 'new', $old);
        $done = $this->work('RFI-2', 'K05+200', $this->day(-3), 'completed', $old);
        $new = $this->employee();
        $actor = $this->admin();

        $handover = $this->team()->setInCharge($p['one'], $new->employee_id, $actor)['handover'];
        // no automatic move: handing over leaves every recorded in-charge as it was
        $this->assertSame($old, $open->refresh()->incharge);
        $this->assertSame($old, $done->refresh()->incharge);

        $preview = $this->team()->previewTransfer($handover)['works'];
        $this->assertSame([$open->id], $preview->pluck('id')->all(), 'only open works under the outgoing in-charge are offered');
        $this->assertSame($old, $open->refresh()->incharge, 'a preview writes nothing');

        $transfer = $this->team()->transferOpenWorks($handover, [$open->id, $done->id], $actor);
        $this->assertSame(1, $transfer->works_count);
        $this->assertSame($new->employee_id, $open->refresh()->incharge);
        $this->assertSame($old, $done->refresh()->incharge, 'completed works keep their historical in-charge');
        $this->assertSame([$open->id => $old], collect($transfer->previous_incharge)->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all());
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'jurisdiction.handover.works_transferred', 'subject_id' => (string) $handover->id]);
        $this->assertSame([$open->id], JurisdictionHandover::find($handover->id)->open_work_ids, 'the snapshot is unchanged by the move');
    }

    public function test_team_changes_through_the_http_api_are_gated_on_team_manage(): void
    {
        $p = $this->phases();
        $e = $this->employee();
        $viewer = $this->employee(['jurisdiction.view', 'jurisdiction.update']);
        $this->actingAs($viewer)->postJson("/jurisdictions/{$p['one']->id}/team/members", ['employee_id' => $e->employee_id, 'role' => 'member'])->assertForbidden();
        $this->actingAs($viewer)->getJson("/jurisdictions/{$p['one']->id}/team/suggestions")->assertForbidden();

        $manager = $this->employee(['jurisdiction.view', 'jurisdiction.team.manage']);
        $this->actingAs($manager)->postJson("/jurisdictions/{$p['one']->id}/team/members", ['employee_id' => $e->employee_id, 'role' => 'member'])->assertCreated();
        $this->assertDatabaseHas('jurisdiction_members', ['jurisdiction_id' => $p['one']->id, 'employee_id' => $e->employee_id, 'role' => 'member']);
        $this->actingAs($manager)->postJson("/jurisdictions/{$p['one']->id}/team/in-charge", ['employee_id' => $e->employee_id, 'previous_becomes' => 'member', 'effective_date' => $this->day(1)])->assertOk()->assertJsonPath('handover.open_works_count', 0);
        $this->assertSame($p['people'][0]->employee_id, $p['one']->refresh()->incharge, 'planned for tomorrow: today is unchanged');
        $this->assertSame($e->employee_id, $this->team()->inChargeAsOf($p['one']->id, $this->day(1)));
        // back-dating over HTTP: 403 without the permission
        $this->actingAs($manager)->postJson("/jurisdictions/{$p['one']->id}/team/in-charge", ['employee_id' => $p['people'][0]->employee_id, 'effective_date' => $this->day(-2), 'reason' => 'correction'])->assertForbidden();
    }

    public function test_suggestions_are_read_only_and_exclude_the_current_team(): void
    {
        $p = $this->phases();
        $helper = $this->employee();
        $onTeam = $p['people'][0];
        $this->work('RFI-1', 'K05+100', $this->day(-5), 'new', $onTeam->employee_id, $helper->employee_id);
        $this->work('RFI-2', 'K05+200', $this->day(-4), 'new', $onTeam->employee_id, $helper->employee_id);
        $this->work('RFI-OLD', 'K05+300', $this->day(-200), 'new', $onTeam->employee_id, $this->employee()->employee_id);
        $manager = $this->employee(['jurisdiction.view', 'jurisdiction.team.manage']);
        $before = JurisdictionMember::count();

        $res = $this->actingAs($manager)->getJson("/jurisdictions/{$p['one']->id}/team/suggestions")->assertOk();
        $ids = collect($res->json('suggestions'))->pluck('employee_id');
        $this->assertSame([$helper->employee_id], $ids->all(), 'ranked by work in the last 90 days; the current in-charge and old work are left out');
        $this->assertSame(2, $res->json('suggestions.0.works'));
        $this->assertSame($before, JurisdictionMember::count(), 'suggesting never writes');
    }

    public function test_the_handover_report_route_is_gated_on_view_and_carries_the_snapshot(): void
    {
        $p = $this->phases();
        $old = $p['people'][0]->employee_id;
        $open = $this->work('RFI-1', 'K05+100', $this->day(-3), 'new', $old);
        $handover = $this->team()->setInCharge($p['one'], $this->employee()->employee_id, $this->admin())['handover'];

        $this->actingAs($this->employee())->get("/jurisdictions/{$p['one']->id}/handovers/{$handover->id}/report")->assertForbidden();
        $this->actingAs($this->employee(['jurisdiction.view']))->get("/jurisdictions/{$p['one']->id}/handovers/{$handover->id}/report")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Project/Jurisdictions/HandoverReportPrint')->where('handover.open_works_count', 1)->where('works.0.number', 'RFI-1')->where('works.0.moved', false)->has('title_block.revision'));
        // a handover of another jurisdiction under this URL is a 404, not a leak
        $this->actingAs($this->employee(['jurisdiction.view']))->get("/jurisdictions/{$p['two']->id}/handovers/{$handover->id}/report")->assertNotFound();
        $this->assertSame($old, $open->refresh()->incharge);
    }
}
