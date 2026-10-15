<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\DailyWorkImportBatch;
use App\Models\DailyWorkRevision;
use App\Models\DailyWorkSummary;
use App\Models\User;
use App\Services\Access\AccessAudit;
use App\Services\DailyWork\History\DailyWorkChangeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Take an import batch back, as an audited SOFT action: the works it created are soft-deleted (they can be restored), the
 * works it resubmitted get the values they had before (the revision keeps both), the summaries it added are subtracted.
 * Nothing is reversed that someone has worked on since: a work with a later revision, an objection or a document is a
 * conflict, reported by name; the whole reversal is refused unless the caller accepts to skip the conflicts.
 */
class DailyWorkImportReversal
{
    /**
     * What a reversal would do, without doing it.
     *
     * @return array{delete: array<int, array<string, mixed>>, revert: array<int, array<string, mixed>>, conflicts: array<int, array<string, mixed>>}
     */
    public function plan(DailyWorkImportBatch $batch): array
    {
        $delete = [];
        $revert = [];
        $conflicts = [];

        $created = DailyWork::query()->where('import_batch_id', $batch->id)->get(['id', 'number', 'deleted_at']);
        foreach ($created as $work) {
            $info = ['id' => $work->id, 'number' => $work->number];
            if ($work->trashed()) {
                continue; // already deleted since
            }
            if (($why = $this->touchedSince($work, $batch)) !== null) {
                $conflicts[] = $info + ['reason' => $why];
            } else {
                $delete[] = $info;
            }
        }

        $createdIds = $created->pluck('id')->all();
        $resubmitted = DailyWorkRevision::query()->where('import_batch_id', $batch->id)->where('event', 'resubmitted')->whereNotIn('daily_work_id', $createdIds)->get();
        foreach ($resubmitted as $rev) {
            $work = DailyWork::withTrashed()->find($rev->daily_work_id, ['id', 'number', 'deleted_at']);
            if ($work === null) {
                continue;
            }
            $info = ['id' => $work->id, 'number' => $work->number, 'revision_id' => $rev->id];
            $latest = DailyWorkRevision::query()->where('daily_work_id', $work->id)->max('revision_no');
            if ($latest !== null && (int) $latest > $rev->revision_no) {
                $conflicts[] = $info + ['reason' => 'It was changed after the import.'];
            } else {
                $revert[] = $info;
            }
        }

        return compact('delete', 'revert', 'conflicts');
    }

    /** @return array{deleted: int, reverted: int, skipped: int} */
    public function reverse(DailyWorkImportBatch $batch, string $reason, User $actor, bool $skipConflicts = false): array
    {
        if ($batch->status !== DailyWorkImportBatch::COMMITTED) {
            throw ValidationException::withMessages(['batch' => 'Only a committed import can be reversed.']);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Say why the import is reversed (at least 5 characters).']);
        }
        $plan = $this->plan($batch);
        if ($plan['conflicts'] !== [] && ! $skipConflicts) {
            throw ValidationException::withMessages(['conflicts' => count($plan['conflicts']).' works were worked on after the import and cannot be reversed: '.collect($plan['conflicts'])->take(5)->pluck('number')->implode(', ').'. Confirm to reverse the rest.']);
        }

        return DB::transaction(function () use ($batch, $reason, $actor, $plan): array {
            DailyWorkChangeContext::with('import', $batch->id, function () use ($plan): void {
                foreach ($plan['delete'] as $info) {
                    DailyWork::query()->find($info['id'])?->delete();
                }
                DailyWorkWorkflow::unrestricted(function () use ($plan): void {
                    foreach ($plan['revert'] as $info) {
                        $rev = DailyWorkRevision::query()->find($info['revision_id']);
                        $before = collect($rev->changes)->mapWithKeys(fn (array $c): array => [$c['field'] => $c['before']])->all();
                        if ($before !== []) {
                            DailyWork::query()->find($info['id'])?->forceFill($before)->save();
                        }
                    }
                });
            }, 'Reversal of import batch #'.$batch->id.': '.$reason, (string) $actor->employee_id);

            foreach ((array) ($batch->summary['summary_changes'] ?? []) as $change) {
                $row = DailyWorkSummary::query()->whereDate('date', $change['date'])->where('incharge', $change['incharge'])->first();
                if ($row === null) {
                    continue;
                }
                foreach ($change['delta'] as $k => $v) {
                    $row->{$k} = max(0, (int) $row->{$k} - (int) $v);
                }
                $row->totalDailyWorks > 0 ? $row->save() : $row->delete();
            }

            $batch->forceFill(['status' => DailyWorkImportBatch::REVERSED, 'reversed_by' => $actor->employee_id, 'reversed_at' => now(), 'reverse_reason' => $reason])->save();
            app(AccessAudit::class)->record('daily-works.import.reversed', DailyWorkImportBatch::class, $batch->id, ['status' => 'committed'], [
                'status' => 'reversed', 'reason' => $reason, 'deleted' => count($plan['delete']), 'reverted' => count($plan['revert']), 'skipped' => count($plan['conflicts']),
            ]);

            return ['deleted' => count($plan['delete']), 'reverted' => count($plan['revert']), 'skipped' => count($plan['conflicts'])];
        });
    }

    /** Why a work the batch created is no longer untouched (null = untouched). */
    private function touchedSince(DailyWork $work, DailyWorkImportBatch $batch): ?string
    {
        $mine = DailyWorkRevision::query()->where('daily_work_id', $work->id)->where('import_batch_id', $batch->id)->max('revision_no');
        $later = DailyWorkRevision::query()->where('daily_work_id', $work->id)->where('revision_no', '>', (int) $mine)->exists();
        if ($later) {
            return 'It was changed after the import.';
        }
        if ($work->objections()->exists()) {
            return 'It has objections attached.';
        }
        if ($work->media()->exists()) {
            return 'It has RFI documents.';
        }

        return null;
    }
}
