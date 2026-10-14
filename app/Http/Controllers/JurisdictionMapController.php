<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\JurisdictionHandover;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\JurisdictionOverviewService;
use App\Services\Jurisdiction\JurisdictionPeriod;
use App\Services\Jurisdiction\JurisdictionTeamService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable jurisdiction map and the handover report: bare Inertia pages (no app chrome) drawn as vector SVG in a
 * fixed light print palette. Printing is viewing, so both sit behind jurisdiction.view. Data minimisation: names,
 * designations and avatars only - no phone, e-mail or other personal data is sent to the page.
 */
class JurisdictionMapController extends Controller
{
    public function __construct(private readonly JurisdictionOverviewService $overview, private readonly JurisdictionTeamService $team) {}

    public function print(Request $request): Response
    {
        $request->validate([
            'paper' => ['nullable', 'in:A3,A4'], 'as_of' => ['nullable', 'date_format:Y-m-d'],
            'period' => ['nullable', 'in:7d,30d,90d,365d,all,custom'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $asOf = $request->query('as_of') ?: ChainageResolver::today();
        $period = JurisdictionPeriod::fromRequest($request);
        // Data minimisation: the print carries names, designations and positions only - no contact details are even sent.
        $data = $this->overview->build($request->user(), $asOf, $period, true, false);

        $generated = now('Asia/Dhaka');
        $fingerprint = substr(sha1(json_encode([$asOf, $period->toArray(), array_map(fn (array $r): array => [$r['id'], $r['code'], $r['start_m'], $r['end_m'], $r['in_charge']['employee_id'] ?? null, $r['stats']['works']], $data['jurisdictions'])])), 0, 7);

        return Inertia::render('Project/Jurisdictions/JurisdictionMapPrint', [
            'paper' => $request->query('paper', 'A3'),
            'data' => $data,
            'title_block' => [
                'project' => config('app.name'), 'title' => 'Jurisdiction Map',
                'generated_at' => $generated->format('d M Y, H:i').' (Asia/Dhaka)',
                'data_as_of' => $asOf, 'prepared_by' => $request->user()->name,
                'revision' => 'JM-'.str_replace('-', '', $asOf).'-'.$fingerprint,
                'source' => 'Centreline: corridor_geometries v'.($data['map']['alignment']['source']['version'] ?? '1').' (OpenStreetMap, dhakabypass). Chainage as recorded in Guardian.',
            ],
        ]);
    }

    /** A4 portrait handover report: the immutable snapshot of open works at the handover and any explicit transfers. */
    public function handoverReport(Request $request, Jurisdiction $jurisdiction, JurisdictionHandover $handover): Response
    {
        abort_unless($handover->jurisdiction_id === $jurisdiction->id, 404);
        $handover->load(['outgoing.designation', 'incoming.designation', 'transfers']);
        $ids = $handover->open_work_ids ?? [];
        $works = DailyWork::query()->withTrashed()->whereIn('id', array_slice($ids, 0, 400))->orderBy('date')->orderBy('id')->get(['id', 'number', 'date', 'type', 'location', 'status', 'incharge']);
        $moved = collect($handover->transfers)->flatMap(fn ($t) => $t->work_ids)->flip();
        $asOf = $handover->effective_date->toDateString();
        $team = $this->team->teamAsOf($jurisdiction, $asOf);

        return Inertia::render('Project/Jurisdictions/HandoverReportPrint', [
            'jurisdiction' => ['id' => $jurisdiction->id, 'code' => $jurisdiction->code, 'name' => $jurisdiction->location, 'start_chainage' => $jurisdiction->start_chainage, 'end_chainage' => $jurisdiction->end_chainage],
            'handover' => [
                'id' => $handover->id, 'effective_date' => $asOf, 'note' => $handover->note, 'open_works_count' => $handover->open_works_count,
                'snapshot_at' => $handover->snapshot_at?->timezone('Asia/Dhaka')->format('d M Y, H:i'),
                'outgoing' => $this->person($handover->outgoing), 'incoming' => $this->person($handover->incoming),
                'transferred' => (int) $handover->transfers->sum('works_count'),
            ],
            'works' => $works->map(fn ($w): array => ['id' => $w->id, 'number' => $w->number, 'date' => substr((string) $w->date, 0, 10), 'type' => $w->type, 'location' => $w->location, 'status' => $w->status, 'moved' => $moved->has($w->id)])->all(),
            'truncated' => max(0, count($ids) - 400),
            'team' => $team->map(fn ($m): array => $this->person($m->employee) + ['role' => $m->role])->all(),
            'title_block' => [
                'project' => config('app.name'), 'title' => 'Handover Report', 'generated_at' => now('Asia/Dhaka')->format('d M Y, H:i').' (Asia/Dhaka)',
                'prepared_by' => $request->user()->name, 'revision' => 'HO-'.$handover->id,
            ],
        ]);
    }

    /** @return array{employee_id: ?string, name: ?string, designation: ?string} */
    private function person($user): array
    {
        return ['employee_id' => $user?->employee_id, 'name' => $user?->name, 'designation' => $user?->designation?->title];
    }
}
