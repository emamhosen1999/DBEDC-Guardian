<?php

namespace App\Observers;

use App\Models\DailyWork;
use App\Models\DailyWorkRevision;
use App\Models\DailyWorkStatusEvent;
use App\Services\DailyWork\History\DailyWorkChangeContext;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only history of a daily work. Every create, update, soft delete and restore, from any write path that goes
 * through the model, becomes a revision: field-level before and after, who, when and the source (web, import, api,
 * mobile, console). A status or inspection-result change is also written as a timestamped status event.
 * Best-effort: a history failure is reported and must never break the change it records.
 */
class DailyWorkHistoryObserver
{
    /** The fields whose changes are history. Derived columns (start_m, end_m, jurisdiction_id) and the lock version are not. */
    public const TRACKED = [
        'date', 'number', 'status', 'inspection_result', 'rfi_response_status', 'rfi_response_date', 'type', 'description', 'location', 'side',
        'qty_layer', 'planned_time', 'incharge', 'assigned', 'completion_time', 'inspection_details', 'resubmission_count', 'resubmission_date',
        'rfi_submission_date', 'file',
    ];

    public function created(DailyWork $work): void
    {
        $changes = [];
        foreach (self::TRACKED as $field) {
            $after = $this->value($work->getAttributes()[$field] ?? null);
            if ($after !== null) {
                $changes[] = ['field' => $field, 'before' => null, 'after' => $after];
            }
        }
        $this->guard(function () use ($work, $changes): void {
            $revision = $this->revision($work, 'created', $changes, ['created']);
            $this->statusEvent($work, null, (string) $work->status, null, $work->inspection_result, $revision);
        });
    }

    public function updated(DailyWork $work): void
    {
        $changes = [];
        foreach (array_keys($work->getChanges()) as $field) {
            if (! in_array($field, self::TRACKED, true)) {
                continue;
            }
            $before = $this->value($work->getRawOriginal($field));
            $after = $this->value($work->getAttributes()[$field] ?? null);
            if ($before !== $after) {
                $changes[] = ['field' => $field, 'before' => $before, 'after' => $after];
            }
        }
        if ($changes === []) {
            return;
        }
        $fields = array_column($changes, 'field');
        $tags = [];
        if (array_intersect($fields, ['status', 'inspection_result'])) {
            $tags[] = 'status_changed';
        }
        if (array_intersect($fields, ['incharge', 'assigned'])) {
            $tags[] = 'reassigned';
        }
        if (in_array('resubmission_count', $fields, true)) {
            $before = (int) ($this->find($changes, 'resubmission_count')['before'] ?? 0);
            $after = (int) ($this->find($changes, 'resubmission_count')['after'] ?? 0);
            if ($after > $before) {
                $tags[] = 'resubmitted';
            }
        }
        if (array_intersect($fields, ['location', 'date'])) {
            $tags[] = 'moved';
        }
        $event = $tags[0] ?? 'updated';
        if (! in_array($event, ['status_changed', 'reassigned', 'resubmitted'], true)) {
            $event = 'updated';
        }

        $this->guard(function () use ($work, $changes, $tags, $event, $fields): void {
            $revision = $this->revision($work, $event, $changes, $tags);
            if (array_intersect($fields, ['status', 'inspection_result'])) {
                $this->statusEvent(
                    $work,
                    $this->value($work->getRawOriginal('status')),
                    (string) $work->status,
                    $this->value($work->getRawOriginal('inspection_result')),
                    $work->inspection_result,
                    $revision,
                );
            }
        });
    }

    public function deleted(DailyWork $work): void
    {
        $this->guard(fn () => $this->revision($work, 'deleted', [['field' => 'deleted_at', 'before' => null, 'after' => now()->toDateTimeString()]], ['deleted']));
    }

    public function restored(DailyWork $work): void
    {
        $this->guard(fn () => $this->revision($work, 'restored', [['field' => 'deleted_at', 'before' => 'deleted', 'after' => null]], ['restored']));
    }

    /** @param array<int, array{field: string, before: mixed, after: mixed}> $changes */
    private function revision(DailyWork $work, string $event, array $changes, array $tags): DailyWorkRevision
    {
        $last = DailyWorkRevision::query()->where('daily_work_id', $work->getKey())->orderByDesc('revision_no')->first(['id', 'revision_no']);

        return DailyWorkRevision::query()->create([
            'daily_work_id' => $work->getKey(), 'revision_no' => ($last?->revision_no ?? 0) + 1, 'previous_revision_id' => $last?->id,
            'event' => $event, 'tags' => $tags, 'changes' => $changes, 'source' => DailyWorkChangeContext::source(),
            'actor_id' => DailyWorkChangeContext::actor(), 'import_batch_id' => DailyWorkChangeContext::batchId(), 'reason' => DailyWorkChangeContext::reason(),
        ]);
    }

    private function statusEvent(DailyWork $work, ?string $from, string $to, ?string $fromResult, ?string $toResult, DailyWorkRevision $revision): void
    {
        DailyWorkStatusEvent::query()->create([
            'daily_work_id' => $work->getKey(), 'from_status' => $from, 'to_status' => $to, 'from_result' => $fromResult, 'to_result' => $toResult,
            'actor_id' => $revision->actor_id, 'source' => $revision->source, 'import_batch_id' => $revision->import_batch_id, 'reason' => $revision->reason, 'occurred_at' => now(),
        ]);
    }

    /** @param array<int, array{field: string, before: mixed, after: mixed}> $changes */
    private function find(array $changes, string $field): array
    {
        foreach ($changes as $c) {
            if ($c['field'] === $field) {
                return $c;
            }
        }

        return [];
    }

    /** Dates and datetimes as plain strings, everything else as stored. */
    private function value(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            $v = $v->format('Y-m-d H:i:s');
        }
        if (is_string($v) && str_ends_with($v, ' 00:00:00') && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $v)) {
            return substr($v, 0, 10); // a date column, not a moment: keep it a plain date
        }

        return $v === '' ? null : (is_scalar($v) || $v === null ? $v : json_encode($v));
    }

    private function guard(callable $write): void
    {
        try {
            if (Schema::hasTable('daily_work_revisions')) {
                $write();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
