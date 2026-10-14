<?php

namespace App\Http\Controllers;

use App\Http\Requests\Jurisdiction\TeamMemberRequest;
use App\Models\Jurisdiction;
use App\Models\JurisdictionHandover;
use App\Models\JurisdictionMember;
use App\Models\User;
use App\Services\Jurisdiction\JurisdictionOverviewService;
use App\Services\Jurisdiction\JurisdictionStatsService;
use App\Services\Jurisdiction\JurisdictionTeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Team changes of a jurisdiction. Every action is gated on jurisdiction.team.manage (route middleware, form request and
 * policy); reading history needs jurisdiction.view. Suggestions only SUGGEST: nothing here writes until the manager adds.
 */
class JurisdictionTeamController extends Controller
{
    public function __construct(private readonly JurisdictionTeamService $team, private readonly JurisdictionStatsService $stats, private readonly JurisdictionOverviewService $overview) {}

    public function add(TeamMemberRequest $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $row = $this->team->addMember($jurisdiction, (string) $request->input('employee_id'), (string) $request->input('role', JurisdictionMember::MEMBER), $request->user(), $request->input('effective_date'), $request->input('reason'));

        return response()->json(['message' => 'Team member added', 'member_id' => $row->id], 201);
    }

    public function inCharge(TeamMemberRequest $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $result = $this->team->setInCharge($jurisdiction, (string) $request->input('employee_id'), $request->user(), $request->input('previous_becomes'),
            $request->input('effective_date'), $request->input('reason'), $request->input('note'));

        return response()->json([
            'message' => 'In-charge changed', 'member_id' => $result['member']->id,
            'handover' => $result['handover'] === null ? null : ['id' => $result['handover']->id, 'open_works_count' => $result['handover']->open_works_count, 'effective_date' => $result['handover']->effective_date->toDateString()],
        ]);
    }

    public function role(TeamMemberRequest $request, Jurisdiction $jurisdiction, JurisdictionMember $member): JsonResponse
    {
        abort_unless($member->jurisdiction_id === $jurisdiction->id, 404);
        $row = $this->team->changeRole($member, (string) $request->input('role'), $request->user(), $request->input('effective_date'), $request->input('reason'));

        return response()->json(['message' => 'Role changed', 'member_id' => $row->id]);
    }

    public function remove(TeamMemberRequest $request, Jurisdiction $jurisdiction, JurisdictionMember $member): JsonResponse
    {
        abort_unless($member->jurisdiction_id === $jurisdiction->id, 404);
        $this->team->endMembership($member, $request->user(), $request->input('effective_date'), $request->input('reason'));

        return response()->json(['message' => 'Team member removed from the team']);
    }

    /** Employees who worked in this jurisdiction in the last 90 days and are not on its team. Read-only. */
    public function suggestions(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        $rows = $this->stats->suggestions($jurisdiction);
        $users = User::query()->with(['designation:id,title', 'media'])->whereIn('employee_id', array_column($rows, 'employee_id'))->get()->keyBy('employee_id');

        return response()->json(['days' => 90, 'suggestions' => array_map(
            fn (array $r): array => $r + ['name' => $users[$r['employee_id']]->name ?? null, 'designation' => $users[$r['employee_id']]->designation?->title ?? null, 'photo' => $users[$r['employee_id']]->profile_image_url ?? null], $rows,
        )]);
    }

    /** Preview of the open works a transfer would move to the incoming in-charge. Writes nothing. */
    public function transferPreview(Request $request, Jurisdiction $jurisdiction, JurisdictionHandover $handover): JsonResponse
    {
        abort_unless($handover->jurisdiction_id === $jurisdiction->id, 404);
        $works = $this->team->previewTransfer($handover)['works'];

        return response()->json([
            'handover' => $this->handoverArray($handover),
            'works' => $works->map(fn ($w): array => ['id' => $w->id, 'number' => $w->number, 'date' => substr((string) $w->date, 0, 10), 'status' => $w->status, 'type' => $w->type, 'location' => $w->location])->all(),
        ]);
    }

    /** The explicit, audited move. Only the confirmed open works change; completed works never do. */
    public function transfer(Request $request, Jurisdiction $jurisdiction, JurisdictionHandover $handover): JsonResponse
    {
        abort_unless($handover->jurisdiction_id === $jurisdiction->id, 404);
        $data = $request->validate(['work_ids' => ['required', 'array', 'min:1', 'max:2000'], 'work_ids.*' => ['integer']]);
        $transfer = $this->team->transferOpenWorks($handover, array_map('intval', $data['work_ids']), $request->user());

        return response()->json(['message' => $transfer->works_count.' open works moved to the incoming in-charge', 'transfer_id' => $transfer->id, 'works' => $transfer->works_count]);
    }

    public function handovers(Request $request, Jurisdiction $jurisdiction): JsonResponse
    {
        return response()->json(['handovers' => $this->overview->handovers($jurisdiction)->map(fn (JurisdictionHandover $h): array => $this->handoverArray($h))->all()]);
    }

    /** @return array<string, mixed> */
    private function handoverArray(JurisdictionHandover $h): array
    {
        return [
            'id' => $h->id, 'jurisdiction_id' => $h->jurisdiction_id, 'effective_date' => $h->effective_date->toDateString(),
            'outgoing' => $h->outgoing === null ? null : ['employee_id' => $h->outgoing_employee_id, 'name' => $h->outgoing->name],
            'incoming' => ['employee_id' => $h->incoming_employee_id, 'name' => $h->incoming?->name], 'note' => $h->note,
            'open_works_count' => $h->open_works_count, 'snapshot_at' => $h->snapshot_at?->toIso8601String(),
            'transferred_works' => $h->relationLoaded('transfers') ? (int) $h->transfers->sum('works_count') : null,
        ];
    }
}
