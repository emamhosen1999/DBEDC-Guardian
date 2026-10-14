<?php

namespace App\Http\Controllers;

use App\Http\Requests\Jurisdiction\DeleteJurisdictionRequest;
use App\Http\Requests\Jurisdiction\LifecycleRequest;
use App\Http\Requests\Jurisdiction\StoreJurisdictionRequest;
use App\Http\Requests\Jurisdiction\UpdateJurisdictionRequest;
use App\Http\Resources\Jurisdiction\JurisdictionResource;
use App\Models\Jurisdiction;
use App\Models\JurisdictionMember;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\GeofenceSource;
use App\Services\Jurisdiction\JurisdictionOverviewService;
use App\Services\Jurisdiction\JurisdictionPeriod;
use App\Services\Jurisdiction\JurisdictionTeamService;
use App\Services\Jurisdiction\JurisdictionVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jurisdictions (project phases). Reads are gated on jurisdiction.view, writes on create / update / delete (routes and
 * policy use the same names). Boundary changes go through JurisdictionVersionService, which keeps the history.
 */
class JurisdictionController extends Controller
{
    public function __construct(
        private readonly JurisdictionVersionService $versions,
        private readonly JurisdictionTeamService $team,
        private readonly JurisdictionOverviewService $overview,
    ) {}

    public function index()
    {
        return redirect()->route('daily-works-unified', ['tab' => 'jurisdictions']);
    }

    /** Backward compatible list (current values). */
    public function allJurisdictions(Request $request): JsonResponse
    {
        return response()->json(['jurisdictions' => $this->list()], 200);
    }

    /** The tab's data as of a date and for a period: boundaries, teams, stats, coverage, map. */
    public function overview(Request $request): JsonResponse
    {
        $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'period' => ['nullable', 'in:7d,30d,90d,365d,all,custom'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $asOf = $request->query('as_of') ?: ChainageResolver::today();
        $data = $this->overview->build($request->user(), $asOf, JurisdictionPeriod::fromRequest($request));
        $data['abilities'] = $this->abilities($request);

        return response()->json($data);
    }

    /** One jurisdiction's history timeline: boundary versions, in-charge tenures, member changes and handovers. */
    public function timeline(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $events = [];
        foreach ($this->versions->history($jurisdiction) as $v) {
            $events[] = ['kind' => 'boundary', 'date' => $v->valid_from?->toDateString(), 'title' => $v->code.' '.$v->name.', '.$v->start_chainage.' to '.$v->end_chainage,
                'detail' => $v->valid_to ? 'Valid until '.$v->valid_to->toDateString() : 'Current definition', 'reason' => $v->change_reason, 'by' => $v->changed_by, 'correction' => false, 'id' => 'v'.$v->id];
        }
        foreach ($this->team->history($jurisdiction) as $m) {
            $who = $m->employee?->name ?? $m->employee_id;
            $events[] = ['kind' => $m->role === JurisdictionMember::IN_CHARGE ? 'in_charge' : 'member', 'date' => $m->effective_from?->toDateString(),
                'title' => $who.' as '.$this->team->roleLabel($m->role), 'detail' => $m->effective_to ? 'Until '.$m->effective_to->toDateString() : 'Open-ended',
                'designation' => $m->employee?->designation?->title, 'reason' => $m->reason, 'by' => $m->assigner?->name, 'correction' => (bool) $m->is_correction, 'id' => 'm'.$m->id];
        }
        foreach ($this->overview->handovers($jurisdiction) as $h) {
            $events[] = ['kind' => 'handover', 'date' => $h->effective_date->toDateString(),
                'title' => 'Handover: '.($h->outgoing?->name ?? 'none').' to '.($h->incoming?->name ?? $h->incoming_employee_id),
                'detail' => $h->open_works_count.' open works at handover'.($h->transfers->isNotEmpty() ? ', '.$h->transfers->sum('works_count').' moved' : ''),
                'reason' => $h->note, 'by' => $h->created_by, 'correction' => false, 'id' => 'h'.$h->id, 'handover_id' => $h->id];
        }
        usort($events, fn (array $a, array $b): int => [$b['date'] === null ? '0000' : $b['date'], $b['id']] <=> [$a['date'] === null ? '0000' : $a['date'], $a['id']]);

        return response()->json(['jurisdiction' => ['id' => $jurisdiction->id, 'code' => $jurisdiction->code, 'name' => $jurisdiction->location], 'events' => $events]);
    }

    public function addJurisdiction(StoreJurisdictionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->versions->create($data, (string) $data['incharge'], $request->user(), $data['effective_date'] ?? null, $data['reason'] ?? null);

        return response()->json(['message' => 'Jurisdiction added successfully', 'jurisdiction' => new JurisdictionResource($result['jurisdiction']), 'jurisdictions' => $this->list()], 201);
    }

    public function updateJurisdiction(UpdateJurisdictionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $jurisdiction = Jurisdiction::query()->findOrFail($data['id']);
        $result = $this->versions->change($jurisdiction, $data, $request->user(), $data['effective_date'] ?? null, $data['reason'] ?? null);
        if (! empty($data['incharge']) && (string) $data['incharge'] !== (string) $result['jurisdiction']->incharge) {
            $this->team->setInCharge($result['jurisdiction'], (string) $data['incharge'], $request->user(), null, $data['effective_date'] ?? null, $data['reason'] ?? null);
        }

        return response()->json([
            'message' => $result['versioned'] ? 'Jurisdiction updated; the previous definition is kept in its history' : 'Jurisdiction updated successfully',
            'jurisdiction' => new JurisdictionResource($result['jurisdiction']->refresh()), 'jurisdictions' => $this->list(), 'reattributed_works' => $result['reattributed'],
        ], 200);
    }

    public function deleteJurisdiction(DeleteJurisdictionRequest $request): JsonResponse
    {
        $jurisdiction = Jurisdiction::query()->findOrFail($request->integer('id'));
        // Delete is for a jurisdiction created by mistake; anything with history must be retired instead (the service refuses).
        $this->versions->delete($jurisdiction, $request->user(), $request->input('reason'));

        return response()->json(['message' => 'Jurisdiction deleted successfully', 'jurisdictions' => $this->list()], 200);
    }

    /** One section becomes two at a chainage from a date (permission jurisdiction.restructure). */
    public function split(LifecycleRequest $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $r = $this->versions->split($jurisdiction, $request->integer('at_m'), $request->input('first'), $request->input('second'), $request->user(), $request->input('effective_date'), $request->input('reason'));

        return response()->json(['message' => 'Section split in two; '.$r['reattributed'].' works re-attributed from that date', 'first' => $r['first']->id, 'second' => $r['second']->id, 'jurisdictions' => $this->list()], 201);
    }

    /** Two adjacent sections become one from a date (permission jurisdiction.restructure). */
    public function merge(LifecycleRequest $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $other = Jurisdiction::query()->findOrFail($request->integer('with'));
        $r = $this->versions->merge($jurisdiction, $other, ['code' => $request->input('code'), 'name' => $request->input('name'), 'incharge' => $request->input('incharge')], $request->user(), $request->input('effective_date'), $request->input('reason'));

        return response()->json(['message' => 'Sections merged; '.$r['reattributed'].' works re-attributed from that date', 'merged' => $r['merged']->id, 'jurisdictions' => $this->list()], 201);
    }

    /** Remove with history kept (permission jurisdiction.retire). */
    public function retire(LifecycleRequest $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $r = $this->versions->retire($jurisdiction, $request->user(), $request->input('effective_date'), $request->input('reason'), $request->boolean('confirm_team'), $request->boolean('confirm_open_works'));

        return response()->json(['message' => 'Jurisdiction retired; its history stays visible as of earlier dates', 'open_works' => $r['open_works'], 'jurisdictions' => $this->list()]);
    }

    /** Restore a retired or deleted jurisdiction (permission jurisdiction.restore). */
    public function restore(LifecycleRequest $request, int $jurisdiction): JsonResponse
    {
        $j = $this->versions->restore($jurisdiction, $request->user(), $request->input('effective_date'), $request->input('reason'), $request->input('incharge'));

        return response()->json(['message' => 'Jurisdiction restored', 'jurisdiction' => new JurisdictionResource($j), 'jurisdictions' => $this->list()]);
    }

    /** Geofence polygons that can become an area. */
    public function geofences(): JsonResponse
    {
        return response()->json(['geofences' => array_map(fn (array $g): array => ['id' => $g['id'], 'name' => $g['name'], 'vertices' => $g['vertices']], app(GeofenceSource::class)->all())]);
    }

    private function list()
    {
        return JurisdictionResource::collection(Jurisdiction::with('inchargeUser:employee_id,name')->orderBy('start_m')->get())->resolve();
    }

    /** @return array<string, bool> */
    private function abilities(Request $request): array
    {
        $u = $request->user();

        return [
            'create' => $u->can('create', Jurisdiction::class), 'update' => $u->can('update', Jurisdiction::class), 'delete' => $u->can('delete', Jurisdiction::class),
            'team' => $u->can('manageTeam', Jurisdiction::class), 'correct_history' => $u->can('jurisdiction.history.correct'),
            'restructure' => $u->can('restructure', Jurisdiction::class), 'retire' => $u->can('retire', Jurisdiction::class), 'restore' => $u->can('restore', Jurisdiction::class),
        ];
    }
}
