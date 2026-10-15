<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailyWork\RegisterDailyWorksRequest;
use App\Models\DailyWork;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Corridor\ChainageResolver;
use App\Services\DailyWork\DailyWorkDetailService;
use App\Services\DailyWork\DailyWorkRegisterService;
use App\Services\DailyWork\DailyWorkTransitionService;
use App\Services\DailyWork\DailyWorkWorkflow;
use App\Services\Jurisdiction\JurisdictionTeamResolver;
use App\Services\Jurisdiction\JurisdictionTeamService;
use App\Support\Corridor\Chainage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The works register API: the paged, filtered, sorted list; one work's detail with its history; the status transition;
 * restore; and the helpers the form needs (people, chainage check). Every action is gated on its own permission on the
 * route and re-checked by the policy for the work.
 */
class DailyWorkRegisterController extends Controller
{
    public function __construct(
        private readonly DailyWorkRegisterService $register,
        private readonly DailyWorkDetailService $detail,
        private readonly DailyWorkTransitionService $transitions,
    ) {}

    public function index(RegisterDailyWorksRequest $request): JsonResponse
    {
        $viewer = $request->user();
        $filters = $request->filters();
        $result = $this->register->page($viewer, $filters, (string) $request->query('sort', 'date'), (string) $request->query('dir', 'desc'), (int) $request->query('page', 1), (int) $request->query('per_page', 25));
        $p = $result['paginator'];

        // An honest empty state: when nothing matches, say how far back the viewer's records go.
        $latest = $p->total() === 0 ? $this->register->scopedQuery($viewer)->max('date') : null;
        $total = $p->total() === 0 ? $this->register->scopedQuery($viewer)->count() : null;

        return response()->json([
            'data' => $result['rows'],
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage(), 'from' => $p->firstItem(), 'to' => $p->lastItem()],
            'filters' => $filters->toArray(),
            'empty' => $p->total() === 0 ? ['scoped_total' => $total, 'latest_work_date' => $latest ? substr((string) $latest, 0, 10) : null] : null,
            'counts' => ['unplaced' => (clone $this->register->scopedQuery($viewer))->whereNull('start_m')->count(), 'unattributed' => (clone $this->register->scopedQuery($viewer))->whereNull('jurisdiction_id')->count()],
            'can' => $this->abilities($viewer),
        ]);
    }

    public function show(Request $request, DailyWork $dailyWork): JsonResponse
    {
        $this->authorize('view', $dailyWork);

        return response()->json($this->detail->detail($dailyWork, $request->user()) + ['can' => $this->abilities($request->user())]);
    }

    /** One status transition: the map, the permission, the version and the implied changes are enforced here. */
    public function transition(Request $request, DailyWork $dailyWork): JsonResponse
    {
        $this->authorize('updateStatus', $dailyWork);
        $data = $request->validate([
            'to' => ['required', Rule::in(DailyWork::$statuses)],
            'inspection_result' => ['nullable', Rule::in(DailyWork::$inspectionResults)],
            'reason' => ['nullable', 'string', 'max:500'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        // A move only the re-open permission makes possible (out of a closed state) needs that permission; a move the map never allows is a 422.
        $to = DailyWorkWorkflow::normalise($data['to']);
        if (in_array($to, DailyWorkWorkflow::targets($dailyWork->status, $dailyWork->inspection_result, true), true)
            && ! in_array($to, DailyWorkWorkflow::targets($dailyWork->status, $dailyWork->inspection_result, false), true)) {
            $this->authorize('reopen', $dailyWork);
        }
        $work = $this->transitions->transition($dailyWork, $data['to'], $data['inspection_result'] ?? null, $data['reason'] ?? null, (int) $data['lock_version'], $request->user());

        return response()->json([
            'message' => 'Status changed to '.$work->status,
            'dailyWork' => $this->register->row($work, $request->user(), collect(), true, $request->user()->can('daily-works.status.reopen')),
        ]);
    }

    /** Restore a deleted work (its own permission). The history records the restore. */
    public function restore(Request $request, DailyWork $dailyWork): JsonResponse
    {
        $this->authorize('restore', $dailyWork);
        abort_unless($dailyWork->trashed(), 422, 'This work is not deleted.');
        $dailyWork->restore();

        return response()->json(['message' => "Daily work '{$dailyWork->number}' restored", 'id' => $dailyWork->id]);
    }

    /** Employees for the pickers (in-charge, assignee). Only what a picker needs, only for those who can assign. */
    public function people(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->canAny(['daily-works.assign', 'daily-works.incharge.update', 'daily-works.create', 'daily-works.update']), 403);
        // Only the active employees this viewer may see (DepartmentScope); the save paths enforce the same limit.
        $rows = app(DepartmentScope::class)->applyToUsers(User::query(), $u)->whereNull('deleted_at')->where('is_active', true)
            ->with(['designation:id,title', 'department:id,name'])->orderBy('name')->get(['employee_id', 'name', 'designation_id', 'department_id']);

        return response()->json(['people' => $rows->map(fn (User $p): array => ['employee_id' => (string) $p->employee_id, 'name' => $p->name, 'designation' => $p->designation?->title, 'department' => $p->department?->name])->all()]);
    }

    /**
     * What a chainage and a date mean, before saving: the parsed span, the section on that date, its in-charge then, the team to
     * assign from, and whether the RFI number is already used. Used by the create / edit form for live, clear errors.
     */
    public function chainageCheck(Request $request, ChainageResolver $resolver, JurisdictionTeamService $team, JurisdictionTeamResolver $teams): JsonResponse
    {
        abort_unless($request->user()->canAny(['daily-works.create', 'daily-works.update']), 403);
        $data = $request->validate(['location' => ['nullable', 'string', 'max:255'], 'date' => ['nullable', 'date_format:Y-m-d'], 'number' => ['nullable', 'string', 'max:255'], 'id' => ['nullable', 'integer']]);
        $out = ['span' => null, 'error' => null, 'jurisdiction' => null, 'in_charge' => null, 'team' => [], 'duplicate' => null];

        if (filled($data['location'] ?? null)) {
            $span = $resolver->span($data['location']);
            if ($span === null) {
                $out['error'] = 'This location is not a readable chainage. Write it like K5+900 or K05+900-K06+400.';
            } else {
                $out['span'] = ['from' => $span['from'], 'to' => $span['to'], 'text' => $span['from'] === $span['to'] ? Chainage::format($span['from']) : Chainage::format($span['from']).' to '.Chainage::format($span['to'])];
                $date = $data['date'] ?? ChainageResolver::today();
                $j = $resolver->forSpan($span['from'], $span['to'], $date);
                if ($j === null) {
                    $out['error'] = "No jurisdiction covers {$out['span']['text']} on ".$date.'.';
                } else {
                    $out['jurisdiction'] = ['id' => $j->id, 'code' => $j->code, 'name' => $j->location];
                    $inCharge = $team->inChargeAsOf($j->id, $date) ?? (string) $j->incharge;
                    $out['in_charge'] = ['employee_id' => $inCharge, 'name' => User::query()->whereKey($inCharge)->value('name')];
                    $tree = $teams->resolve(collect([$j]), [$j->id => $team->teamAsOf($j, $date)], [], $date, null, false)[$j->id]['tree'] ?? [];
                    $flat = [];
                    $walk = function (array $nodes) use (&$walk, &$flat): void {
                        foreach ($nodes as $n) {
                            $flat[] = ['employee_id' => $n['employee_id'], 'name' => $n['name'], 'designation' => $n['designation'], 'position' => $n['position']];
                            $walk($n['children']);
                        }
                    };
                    $walk($tree);
                    $out['team'] = $flat;
                }
            }
        }
        if (filled($data['number'] ?? null)) {
            $dup = DailyWork::withTrashed()->where('number', $data['number'])->when($data['id'] ?? null, fn ($q, $id) => $q->where('id', '<>', $id))->first(['id', 'number', 'date', 'status', 'deleted_at']);
            $out['duplicate'] = $dup ? ['id' => $dup->id, 'number' => $dup->number, 'date' => $dup->date?->toDateString(), 'status' => $dup->status, 'deleted' => $dup->trashed()] : null;
        }

        return response()->json($out);
    }

    /** @return array<string, bool> */
    private function abilities(User $u): array
    {
        return [
            'create' => $u->can('create', DailyWork::class), 'import' => $u->can('daily-works.import'), 'export' => $u->can('export', DailyWork::class),
            'status' => $u->can('daily-works.status.update'), 'reopen' => $u->can('daily-works.status.reopen'), 'assign' => $u->can('daily-works.assign'),
            'reassign' => $u->can('daily-works.incharge.update'), 'bulk' => $u->can('daily-works.bulk'), 'delete' => $u->can('daily-works.delete'),
            'restore' => $u->can('daily-works.restore'), 'history' => $u->can('daily-works.history.view'), 'files' => $u->can('daily-works.files.manage'),
            'import_reverse' => $u->can('daily-works.import.reverse'), 'inspection' => $u->can('daily-works.inspection.update'),
        ];
    }
}
