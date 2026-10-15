<?php

namespace Tests\Feature\DailyWork;

use App\Models\DailyWork;
use App\Models\DailyWorkRevision;
use App\Models\DailyWorkStatusEvent;
use App\Services\DailyWork\DailyWorkWorkflow;
use App\Services\DailyWork\History\DailyWorkChangeContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Every daily work keeps an append-only history; status changes are timestamped events; the lifecycle is an explicit map. */
class DailyWorkHistoryTest extends DailyWorkTestCase
{
    private function open(string $number = 'RFI-1', string $loc = 'K05+100'): array
    {
        $p = $this->phases();
        $actor = $this->dwAdmin();
        $this->actingAs($actor);

        return [$this->work($number, $loc, '2026-05-01', 'new', $p['people'][0]->employee_id), $actor, $p];
    }

    public function test_creating_a_work_writes_revision_one_and_the_first_status_event(): void
    {
        [$w, $actor] = $this->open();

        $rev = DailyWorkRevision::where('daily_work_id', $w->id)->get();
        $this->assertCount(1, $rev);
        $this->assertSame(1, $rev[0]->revision_no);
        $this->assertSame('created', $rev[0]->event);
        $this->assertSame((string) $actor->employee_id, $rev[0]->actor_id);
        $this->assertSame('web', $rev[0]->source);
        $fields = collect($rev[0]->changes)->pluck('after', 'field');
        $this->assertSame('RFI-1', $fields['number']);
        $this->assertSame('K05+100', $fields['location']);
        $ev = DailyWorkStatusEvent::where('daily_work_id', $w->id)->get();
        $this->assertSame([null, 'new'], [$ev[0]->from_status, $ev[0]->to_status]);
    }

    public function test_an_update_records_field_level_before_and_after_and_links_to_the_previous_revision(): void
    {
        [$w] = $this->open();
        $w->description = 'Changed description';
        $w->location = 'K06+000';
        $w->save();

        $rev = DailyWorkRevision::where('daily_work_id', $w->id)->orderBy('revision_no')->get();
        $this->assertCount(2, $rev);
        $this->assertSame($rev[0]->id, $rev[1]->previous_revision_id);
        $changes = collect($rev[1]->changes)->keyBy('field');
        $this->assertSame(['d', 'Changed description'], [$changes['description']['before'], $changes['description']['after']]);
        $this->assertSame(['K05+100', 'K06+000'], [$changes['location']['before'], $changes['location']['after']]);
        $this->assertContains('moved', $rev[1]->tags);
        $this->assertArrayNotHasKey('start_m', $changes->all(), 'derived columns are not history');
    }

    public function test_a_save_that_changes_nothing_tracked_writes_no_revision(): void
    {
        [$w] = $this->open();
        $w->lock_version = 5;
        $w->save();
        $this->assertSame(1, DailyWorkRevision::where('daily_work_id', $w->id)->count());
    }

    public function test_status_changes_are_timestamped_events_with_who_and_source(): void
    {
        [$w, $actor] = $this->open();
        $w->status = 'in-progress';
        $w->save();
        DailyWorkChangeContext::with('import', 7, function () use ($w): void {
            $w->status = 'pending';
            $w->save();
        });

        $ev = DailyWorkStatusEvent::where('daily_work_id', $w->id)->orderBy('id')->get();
        $this->assertSame(['new', 'in-progress'], [$ev[1]->from_status, $ev[1]->to_status]);
        $this->assertSame((string) $actor->employee_id, $ev[1]->actor_id);
        $this->assertNotNull($ev[1]->occurred_at);
        $this->assertSame(['in-progress', 'pending', 'import', 7], [$ev[2]->from_status, $ev[2]->to_status, $ev[2]->source, $ev[2]->import_batch_id]);
        $this->assertSame('import', DailyWorkRevision::where('daily_work_id', $w->id)->orderByDesc('revision_no')->first()->source, 'the import context labels the revision');
    }

    public function test_the_source_is_classified_from_the_request(): void
    {
        $this->assertSame('web', DailyWorkChangeContext::source());
        $this->app->instance('request', Request::create('/api/v1/daily-works', 'POST'));
        $this->assertSame('api', DailyWorkChangeContext::source());
        $this->app->instance('request', Request::create('/api/v1/daily-works', 'POST', server: ['HTTP_X_CLIENT' => 'mobile/1.4']));
        $this->assertSame('mobile', DailyWorkChangeContext::source());
        $this->assertSame('import', DailyWorkChangeContext::with('import', 3, fn () => DailyWorkChangeContext::source()));
    }

    public function test_a_soft_delete_and_a_restore_are_revisions_and_nothing_is_hard_deleted(): void
    {
        [$w] = $this->open();
        $w->delete();
        $w->restore();

        $events = DailyWorkRevision::where('daily_work_id', $w->id)->orderBy('revision_no')->pluck('event')->all();
        $this->assertSame(['created', 'deleted', 'restored'], $events);
        $this->assertNotNull(DailyWork::withTrashed()->find($w->id));
    }

    public function test_revisions_and_status_events_cannot_be_edited_or_removed(): void
    {
        [$w] = $this->open();
        $rev = DailyWorkRevision::where('daily_work_id', $w->id)->first();
        try {
            $rev->update(['reason' => 'tampered']);
            $this->fail('append-only');
        } catch (LogicException) {
        }
        try {
            $rev->delete();
            $this->fail('append-only');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        DailyWorkStatusEvent::where('daily_work_id', $w->id)->first()->delete();
    }

    public function test_the_legacy_complete_spelling_is_read_as_completed_and_the_change_is_recorded(): void
    {
        [$w] = $this->open();
        \DB::table('daily_works')->where('id', $w->id)->update(['status' => 'complete']);
        $w = DailyWork::find($w->id);
        $w->description = 'touch';
        $w->save();
        $this->assertSame('completed', $w->fresh()->status);
        $this->assertSame(['complete', 'completed'], [
            collect(DailyWorkRevision::where('daily_work_id', $w->id)->orderByDesc('revision_no')->first()->changes)->firstWhere('field', 'status')['before'],
            collect(DailyWorkRevision::where('daily_work_id', $w->id)->orderByDesc('revision_no')->first()->changes)->firstWhere('field', 'status')['after'],
        ]);
    }

    public function test_the_transition_map_allows_the_lifecycle_and_refuses_the_rest(): void
    {
        $t = fn (string $from, ?string $result = null, bool $reopen = false) => DailyWorkWorkflow::targets($from, $result, $reopen);

        $this->assertContains('completed', $t('in-progress'));
        $this->assertContains('resubmission', $t('rejected'));
        $this->assertSame(['resubmission'], $t('rejected'), 'a rejected RFI can only come back as a resubmission');
        $this->assertEqualsCanonicalizing(['new', 'in-progress', 'resubmission'], $t('rejected', null, true), 'with re-open: back to new / in-progress too');
        $this->assertSame([], $t('completed', 'pass'), 'a passed inspection is closed');
        $this->assertSame(['resubmission'], $t('completed', 'fail'));
        $this->assertContains('in-progress', $t('completed', 'pass', true));
        $this->assertNotContains('new', $t('new'));
        $this->assertSame([], $t('nonsense'));
    }

    public function test_the_model_refuses_a_transition_the_map_does_not_allow(): void
    {
        [$w] = $this->open();
        $w->status = 'rejected';
        $w->save();
        $w->status = 'completed';
        try {
            $w->save();
            $this->fail('rejected cannot go straight to completed');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('rejected', $e->errors()['status'][0]);
        }
        $this->assertSame('rejected', $w->fresh()->status);
    }

    public function test_a_completed_work_never_changes_hands_but_an_open_one_is_reassigned_as_an_event(): void
    {
        [$w, , $p] = $this->open();
        $other = $this->employee();
        $w->incharge = $other->employee_id;
        $w->save();
        $rev = DailyWorkRevision::where('daily_work_id', $w->id)->orderByDesc('revision_no')->first();
        $this->assertSame('reassigned', $rev->event);
        $this->assertSame([$p['people'][0]->employee_id, $other->employee_id], [collect($rev->changes)->firstWhere('field', 'incharge')['before'], collect($rev->changes)->firstWhere('field', 'incharge')['after']]);

        $w->status = 'completed';
        $w->inspection_result = 'pass';
        $w->save();
        $w->assigned = $this->employee()->employee_id;
        try {
            $w->save();
            $this->fail('completed works never change hands');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('incharge', $e->errors());
        }
        $this->assertSame($other->employee_id, $w->fresh()->incharge);
    }
}
