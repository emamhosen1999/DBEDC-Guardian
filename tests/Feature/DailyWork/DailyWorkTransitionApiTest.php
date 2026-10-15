<?php

namespace Tests\Feature\DailyWork;

use App\Models\DailyWork;
use App\Models\DailyWorkRevision;
use App\Models\DailyWorkStatusEvent;

/** The status workflow over HTTP: the map, the permissions (re-open is its own), the implied changes, the version check and the history. */
class DailyWorkTransitionApiTest extends DailyWorkTestCase
{
    private array $p = [];

    private function setup_(string $status = 'new', ?string $result = null): array
    {
        $this->p = $this->phases();
        $actor = $this->viewer(['view', 'status.update'], ['report_to' => null]);
        $this->actingAs($this->dwAdmin());
        $w = $this->work('RFI-T1', 'K05+100', '2026-05-01', 'new', $actor->employee_id);
        if ($status !== 'new') {
            \DB::table('daily_works')->where('id', $w->id)->update(['status' => $status, 'inspection_result' => $result]);
        }

        return [$w->fresh(), $actor];
    }

    private function go(DailyWork $w, $actor, array $body)
    {
        return $this->actingAs($actor)->postJson(route('dailyWorks.transition', $w->id), $body + ['lock_version' => $w->lock_version]);
    }

    public function test_the_lifecycle_runs_new_to_in_progress_to_completed_and_the_history_records_who_and_when(): void
    {
        [$w, $actor] = $this->setup_();
        $r = $this->go($w, $actor, ['to' => 'in-progress'])->assertOk();
        $this->assertSame('in-progress', $r->json('dailyWork.status'));
        $w->refresh();

        $this->go($w, $actor, ['to' => 'completed'])->assertStatus(422)->assertJsonValidationErrors('inspection_result');
        $done = $this->go($w, $actor, ['to' => 'completed', 'inspection_result' => 'pass'])->assertOk();
        $this->assertNotNull($done->json('dailyWork.completion_time'));
        $this->assertSame([], $done->json('dailyWork.transitions'), 'a passed inspection offers nothing further');

        $t = $this->actingAs($this->dwAdmin())->getJson(route('dailyWorks.detail', $w->id))->json('turnaround');
        $this->assertSame('recorded events', $t['basis']);
        $this->assertGreaterThanOrEqual(0, $t['days']);

        $events = DailyWorkStatusEvent::where('daily_work_id', $w->id)->orderBy('id')->get();
        $this->assertSame(['new', 'in-progress', 'completed'], $events->pluck('to_status')->all());
        $this->assertSame((string) $actor->employee_id, $events[2]->actor_id);
        $this->assertSame('pass', $events[2]->to_result);
    }

    public function test_a_transition_the_map_refuses_is_a_422_with_the_reason(): void
    {
        [$w, $actor] = $this->setup_('rejected');
        $this->go($w, $actor, ['to' => 'completed', 'inspection_result' => 'pass'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('rejected', $w->fresh()->status);
    }

    public function test_reopening_a_closed_rfi_needs_its_own_permission(): void
    {
        [$w, $actor] = $this->setup_('completed', 'pass');
        $this->go($w, $actor, ['to' => 'in-progress'])->assertForbidden();
        $reopener = $this->viewer(['view', 'status.update', 'status.reopen']);
        \DB::table('daily_works')->where('id', $w->id)->update(['incharge' => $reopener->employee_id]);
        $this->go($w->fresh(), $reopener, ['to' => 'in-progress', 'reason' => 'Inspector found the layer incomplete'])->assertOk();
        $this->assertSame('Inspector found the layer incomplete', DailyWorkRevision::where('daily_work_id', $w->id)->orderByDesc('revision_no')->first()->reason);
    }

    public function test_a_failed_inspection_goes_back_as_the_next_numbered_resubmission_of_the_same_work(): void
    {
        [$w, $actor] = $this->setup_('completed', 'fail');
        $r1 = $this->go($w, $actor, ['to' => 'resubmission'])->assertOk();
        $this->assertSame(1, $r1->json('dailyWork.resubmission_count'));
        $this->assertNull($r1->json('dailyWork.completion_time'));
        $this->assertMatchesRegularExpression('/^1st Resubmission on /', (string) $r1->json('dailyWork.resubmission_date'));
        $w->refresh();
        $this->go($w, $actor, ['to' => 'completed', 'inspection_result' => 'fail'])->assertOk();
        $w->refresh();
        $r2 = $this->go($w, $actor, ['to' => 'resubmission'])->assertOk();
        $this->assertSame(2, $r2->json('dailyWork.resubmission_count'));
        $this->assertSame(1, DailyWork::where('number', 'RFI-T1')->count(), 'one identity, numbered revisions');

        $detail = $this->actingAs($this->dwAdmin())->getJson(route('dailyWorks.detail', $w->id))->assertOk();
        $rounds = $detail->json('history.resubmissions');
        $this->assertSame([1, 2], array_column($rounds, 'round'));
        $this->assertNotNull($rounds[1]['previous_revision_id'], 'each resubmission is linked to the revision before it');
    }

    public function test_rejecting_needs_a_reason_and_a_stale_version_is_a_409(): void
    {
        [$w, $actor] = $this->setup_();
        $this->go($w, $actor, ['to' => 'rejected'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->go($w, $actor, ['to' => 'rejected', 'reason' => 'Wrong layer'])->assertOk();
        $this->go($w, $actor, ['to' => 'resubmission'])->assertStatus(409); // lock_version 0 is now stale
    }

    public function test_without_status_update_the_route_is_forbidden_and_nothing_changes(): void
    {
        [$w] = $this->setup_();
        $viewer = $this->viewer(['view']);
        $this->actingAs($viewer)->postJson(route('dailyWorks.transition', $w->id), ['to' => 'in-progress', 'lock_version' => 0])->assertForbidden();
        $this->assertSame('new', $w->fresh()->status);
    }

    public function test_detail_carries_history_files_the_section_as_of_the_work_date_and_hides_history_without_its_permission(): void
    {
        [$w] = $this->setup_();
        $admin = $this->dwAdmin();
        $d = $this->actingAs($admin)->getJson(route('dailyWorks.detail', $w->id))->assertOk();
        $this->assertSame('PH-1', $d->json('as_of.jurisdiction.code'));
        $this->assertSame('2026-05-01', $d->json('as_of.date'));
        $this->assertSame(1, count($d->json('history.revisions')));
        $this->assertSame('created', $d->json('history.revisions.0.event'));
        $this->assertNotNull($d->json('history.since'));
        $this->assertTrue($d->json('history.complete'));
        $this->assertSame('none', $d->json('turnaround.basis'), 'an unfinished work has no turnaround yet');

        $noHistory = $this->viewer(['view']);
        \DB::table('daily_works')->where('id', $w->id)->update(['incharge' => $noHistory->employee_id]);
        $this->actingAs($noHistory)->getJson(route('dailyWorks.detail', $w->id))->assertOk()->assertJsonPath('history', null);
    }

    public function test_the_chainage_check_resolves_the_section_the_in_charge_the_team_and_a_duplicate_rfi(): void
    {
        [$w] = $this->setup_();
        $admin = $this->dwAdmin();
        $r = $this->actingAs($admin)->postJson(route('dailyWorks.chainageCheck'), ['location' => 'K29+400', 'date' => '2026-05-01', 'number' => 'RFI-T1'])->assertOk();
        $this->assertSame('PH-3', $r->json('jurisdiction.code'));
        $this->assertSame($this->p['people'][2]->employee_id, $r->json('in_charge.employee_id'));
        $this->assertSame('K29+400', $r->json('span.text'));
        $this->assertSame($w->id, $r->json('duplicate.id'));
        $this->assertNotEmpty($r->json('team'));

        $bad = $this->actingAs($admin)->postJson(route('dailyWorks.chainageCheck'), ['location' => 'Main gate'])->assertOk();
        $this->assertStringContainsString('not a readable chainage', $bad->json('error'));
        $out = $this->actingAs($admin)->postJson(route('dailyWorks.chainageCheck'), ['location' => 'K49+500'])->assertOk();
        $this->assertNull($out->json('jurisdiction'));
        $this->assertNotNull($out->json('error'));
        $this->actingAs($this->viewer(['view']))->postJson(route('dailyWorks.chainageCheck'), ['location' => 'K1+000'])->assertForbidden();
    }
}
