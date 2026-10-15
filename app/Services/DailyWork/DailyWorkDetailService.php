<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\DailyWorkRevision;
use App\Models\DailyWorkStatusEvent;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\JurisdictionTeamService;
use Illuminate\Support\Collection;

/** Everything the row detail shows: the work, its documents, objections, resubmissions, revision history and status timeline. */
class DailyWorkDetailService
{
    /** Field names as people read them. */
    public const LABELS = [
        'date' => 'RFI date', 'number' => 'RFI number', 'status' => 'Status', 'inspection_result' => 'Inspection result', 'rfi_response_status' => 'RFI response',
        'rfi_response_date' => 'RFI response date', 'type' => 'Type', 'description' => 'Description', 'location' => 'Location', 'side' => 'Road side',
        'qty_layer' => 'Layer / quantity', 'planned_time' => 'Time', 'incharge' => 'In-charge', 'assigned' => 'Assignee', 'completion_time' => 'Completed at',
        'inspection_details' => 'Inspection details', 'resubmission_count' => 'Resubmission number', 'resubmission_date' => 'Resubmission note',
        'rfi_submission_date' => 'RFI submission date', 'file' => 'RFI document', 'deleted_at' => 'Deleted',
    ];

    public function __construct(
        private readonly DailyWorkRegisterService $register,
        private readonly ChainageResolver $resolver,
        private readonly JurisdictionTeamService $team,
    ) {}

    /** @return array<string, mixed> */
    public function detail(DailyWork $work, User $viewer): array
    {
        $work->loadMissing(['inchargeUser:employee_id,name', 'assignedUser:employee_id,name']);
        $day = $work->date?->toDateString();
        $jurisdiction = $work->jurisdiction_id ? Jurisdiction::withTrashed()->find($work->jurisdiction_id, ['id', 'code', 'location']) : null;
        $canHistory = $viewer->can('history', $work);

        $out = [
            'work' => $this->register->row($work->loadCount([
                'activeObjections as active_objections_count',
                'media as files_count' => fn ($m) => $m->where('collection_name', 'rfi_files'),
            ]), $viewer, collect($jurisdiction ? [$jurisdiction->id => $jurisdiction] : []), $viewer->can('daily-works.status.update'), $viewer->can('daily-works.status.reopen')),
            // The section and the in-charge ON THE WORK'S DATE: the attribution is historical and is never recomputed from today's boundaries.
            'as_of' => [
                'date' => $day,
                'jurisdiction' => $jurisdiction ? ['id' => $jurisdiction->id, 'code' => $jurisdiction->code, 'name' => $jurisdiction->location] : null,
                'in_charge_then' => $jurisdiction && $day ? $this->team->inChargeAsOf($jurisdiction->id, $day) : null,
                'recorded_in_charge' => $work->incharge,
            ],
            'files' => $work->rfi_files,
            'objections' => $work->objections()->get(['rfi_objections.id', 'rfi_objections.title', 'rfi_objections.status', 'rfi_objections.category', 'rfi_objections.type', 'rfi_objections.created_at'])->map(fn ($o): array => [
                'id' => $o->id, 'title' => $o->title, 'status' => $o->status, 'category' => $o->category, 'type' => $o->type, 'attached_at' => $o->pivot?->attached_at,
            ])->all(),
            'turnaround' => $this->turnaround($work),
            'history' => null,
        ];

        if ($canHistory) {
            $out['history'] = $this->history($work);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function history(DailyWork $work): array
    {
        $revisions = DailyWorkRevision::query()->where('daily_work_id', $work->getKey())->orderByDesc('revision_no')->limit(300)->get();
        $events = DailyWorkStatusEvent::query()->where('daily_work_id', $work->getKey())->orderByDesc('occurred_at')->orderByDesc('id')->limit(300)->get();
        $actorIds = $revisions->pluck('actor_id')->merge($events->pluck('actor_id'))->filter()->unique()->values();
        $names = $actorIds->isEmpty() ? collect() : User::query()->whereIn('employee_id', $actorIds)->pluck('name', 'employee_id');
        $people = $this->peopleNames($revisions);

        $mapRevision = fn (DailyWorkRevision $r): array => [
            'id' => $r->id, 'revision_no' => $r->revision_no, 'previous_revision_id' => $r->previous_revision_id, 'event' => $r->event, 'tags' => $r->tags ?? [],
            'at' => $r->created_at?->toIso8601String(), 'source' => $r->source, 'by' => $r->actor_id === null ? null : ['employee_id' => $r->actor_id, 'name' => $names[$r->actor_id] ?? null],
            'reason' => $r->reason, 'import_batch_id' => $r->import_batch_id,
            'changes' => collect($r->changes)->map(fn (array $c): array => [
                'field' => $c['field'], 'label' => self::LABELS[$c['field']] ?? $c['field'],
                'before' => $this->show($c['field'], $c['before'], $people), 'after' => $this->show($c['field'], $c['after'], $people),
            ])->all(),
        ];

        $resubmissions = $revisions->filter(fn (DailyWorkRevision $r): bool => in_array('resubmitted', $r->tags ?? [], true))->sortBy('revision_no')->values();

        return [
            'since' => $this->register->historySince(),
            'complete' => $revisions->contains(fn (DailyWorkRevision $r): bool => $r->event === 'created'),
            'revisions' => $revisions->map($mapRevision)->all(),
            'status_events' => $events->map(fn (DailyWorkStatusEvent $e): array => [
                'id' => $e->id, 'from' => $e->from_status, 'to' => $e->to_status, 'from_result' => $e->from_result, 'to_result' => $e->to_result,
                'at' => $e->occurred_at?->toIso8601String(), 'source' => $e->source, 'by' => $e->actor_id === null ? null : ['employee_id' => $e->actor_id, 'name' => $names[$e->actor_id] ?? null], 'reason' => $e->reason,
            ])->all(),
            // Each resubmission is the next numbered revision of this same work, linked to the one before it.
            'resubmissions' => $resubmissions->map(fn (DailyWorkRevision $r, int $i): array => [
                'round' => $i + 1, 'revision_id' => $r->id, 'previous_revision_id' => $r->previous_revision_id, 'at' => $r->created_at?->toIso8601String(), 'source' => $r->source,
                'by' => $r->actor_id === null ? null : ['employee_id' => $r->actor_id, 'name' => $names[$r->actor_id] ?? null], 'import_batch_id' => $r->import_batch_id,
            ])->all(),
        ];
    }

    /**
     * RFI date to completion. From the recorded events when the work has them (the cycle time is the sum of what happened),
     * else from the RFI date and the completion time stored on older records: it says which.
     *
     * @return array{basis: string, days: ?float, submitted: ?string, completed: ?string}
     */
    public function turnaround(DailyWork $work): array
    {
        $submitted = $work->date?->toDateString();
        $completed = $work->completion_time?->format('Y-m-d H:i:s');
        if ($submitted === null || $completed === null) {
            return ['basis' => 'none', 'days' => null, 'submitted' => $submitted, 'completed' => $completed];
        }
        $event = DailyWorkStatusEvent::query()->where('daily_work_id', $work->getKey())->where('to_status', DailyWork::STATUS_COMPLETED)->orderBy('occurred_at')->first();

        return [
            'basis' => $event ? 'recorded events' : 'RFI date to completion time (older record)',
            'days' => round((strtotime($completed) - strtotime($submitted.' 00:00:00')) / 86400, 1),
            'submitted' => $submitted, 'completed' => $completed,
        ];
    }

    /** employee id => name for the in-charge / assignee fields that appear in the revisions. @param Collection<int, DailyWorkRevision> $revisions */
    private function peopleNames(Collection $revisions): Collection
    {
        $ids = [];
        foreach ($revisions as $r) {
            foreach ($r->changes as $c) {
                if (in_array($c['field'], ['incharge', 'assigned'], true)) {
                    $ids[] = $c['before'];
                    $ids[] = $c['after'];
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? collect() : User::query()->whereIn('employee_id', $ids)->pluck('name', 'employee_id');
    }

    private function show(string $field, mixed $value, Collection $people): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (in_array($field, ['incharge', 'assigned'], true)) {
            return ($people[$value] ?? null) !== null ? $people[$value].' ('.$value.')' : (string) $value;
        }

        return mb_strimwidth((string) $value, 0, 240, '…');
    }
}
