<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\DailyWorkRevision;
use App\Models\Jurisdiction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The works register: server-side pagination, sorting and filtering over the viewer's own scope, with a fixed query
 * budget per page (the page, the count, the two employee names, the jurisdiction names; objection and file counts ride
 * in the page query as sub-selects). No per-row queries.
 */
class DailyWorkRegisterService
{
    /** sort key => column. Every one is indexed or cheap on 21k rows. */
    public const SORTS = [
        'date' => 'date', 'number' => 'number', 'status' => 'status', 'type' => 'type', 'location' => 'start_m', 'incharge' => 'incharge',
        'assigned' => 'assigned', 'updated' => 'updated_at', 'completion' => 'completion_time', 'resubmissions' => 'resubmission_count',
    ];

    public function __construct(private readonly DailyWorkVisibility $scope) {}

    /** The viewer's scoped base query with the page's counts (no filters). */
    public function scopedQuery(User $viewer): Builder
    {
        return $this->scope->apply(DailyWork::query(), $viewer);
    }

    /** @return array{paginator: LengthAwarePaginator, rows: array<int, array<string, mixed>>} */
    public function page(User $viewer, DailyWorkFilters $filters, string $sort = 'date', string $dir = 'desc', int $page = 1, int $perPage = 25): array
    {
        $column = self::SORTS[$sort] ?? 'date';
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        $query = $filters->apply($this->scopedQuery($viewer))
            ->with(['inchargeUser:employee_id,name', 'assignedUser:employee_id,name'])
            ->withCount([
                'activeObjections as active_objections_count',
                'media as files_count' => fn ($m) => $m->where('collection_name', 'rfi_files'),
            ])
            ->orderBy($column, $dir)->orderBy('id', $dir);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(max(5, min(100, $perPage)), ['*'], 'page', max(1, $page));
        $works = $paginator->getCollection();

        $jurisdictions = Jurisdiction::withTrashed()->whereIn('id', $works->pluck('jurisdiction_id')->filter()->unique())->get(['id', 'code', 'location'])->keyBy('id');
        $canReopen = $viewer->can('daily-works.status.reopen');
        $canStatus = $viewer->can('daily-works.status.update');

        $rows = $works->map(fn (DailyWork $w): array => $this->row($w, $viewer, $jurisdictions, $canStatus, $canReopen))->all();

        return ['paginator' => $paginator, 'rows' => $rows];
    }

    /**
     * @param  Collection<int, Jurisdiction>  $jurisdictions
     * @return array<string, mixed>
     */
    public function row(DailyWork $w, User $viewer, Collection $jurisdictions, bool $canStatus, bool $canReopen): array
    {
        $attrs = $w->getAttributes();
        $j = isset($attrs['jurisdiction_id']) ? $jurisdictions->get((int) $attrs['jurisdiction_id']) : null;
        $deleted = $w->trashed();

        return [
            'id' => $w->id,
            'number' => $w->number,
            'date' => $w->date?->toDateString(),
            'status' => DailyWorkWorkflow::normalise($w->status),
            'inspection_result' => $w->inspection_result,
            'type' => $w->type,
            'description' => $w->description,
            'location' => $w->location,
            'start_m' => $attrs['start_m'] ?? null,
            'end_m' => $attrs['end_m'] ?? null,
            'side' => $w->side,
            'qty_layer' => $w->qty_layer,
            'planned_time' => $w->planned_time,
            'incharge' => $w->incharge,
            'incharge_name' => $w->inchargeUser?->name,
            'assigned' => $w->assigned,
            'assigned_name' => $w->assignedUser?->name,
            'jurisdiction' => $j === null ? null : ['id' => $j->id, 'code' => $j->code, 'name' => $j->location],
            'completion_time' => $w->completion_time?->format('Y-m-d H:i:s'),
            'rfi_submission_date' => $w->rfi_submission_date?->toDateString(),
            'rfi_response_status' => $w->rfi_response_status,
            'rfi_response_date' => $w->rfi_response_date?->toDateString(),
            'resubmission_count' => (int) $w->resubmission_count,
            'resubmission_date' => $w->resubmission_date,
            'active_objections' => (int) ($attrs['active_objections_count'] ?? 0),
            'files' => (int) ($attrs['files_count'] ?? 0),
            'inspection_details' => $w->inspection_details,
            'lock_version' => (int) $w->lock_version,
            'updated_at' => $w->updated_at?->toIso8601String(),
            'deleted_at' => $w->deleted_at?->toIso8601String(),
            'import_batch_id' => $attrs['import_batch_id'] ?? null,
            // What the viewer may do with THIS row (the server re-checks every action).
            'transitions' => $deleted || ! $canStatus ? [] : DailyWorkWorkflow::targets($w->status, $w->inspection_result, $canReopen),
            'can' => $deleted
                ? ['restore' => $viewer->can('restore', $w)]
                : ['update' => $viewer->can('update', $w), 'delete' => $viewer->can('delete', $w), 'assign' => $viewer->can('updateAssigned', $w), 'reassign' => $viewer->can('updateIncharge', $w) && ! DailyWorkWorkflow::isClosed($w->status)],
        ];
    }

    /** When recording began (the first revision), so the history can say what it does not know. */
    public function historySince(): ?string
    {
        $first = DailyWorkRevision::query()->min('created_at');

        return $first === null ? null : substr((string) $first, 0, 10);
    }
}
