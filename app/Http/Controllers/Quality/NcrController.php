<?php

namespace App\Http\Controllers\Quality;

use App\Http\Controllers\Controller;
use App\Models\QualityNCR;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Non-Conformance Report (NCR) register — Dhaka Bypass Expressway (N-105).
 * Full CRUD + status workflow (open → review → in-progress → verified/consent → closed).
 */
class NcrController extends Controller
{
    private const OPEN_STATUSES = ['open', 'under_review', 'action_assigned', 'action_in_progress'];
    private const SEVERITIES = ['minor', 'major', 'critical'];
    private const STATUSES = ['open', 'under_review', 'action_assigned', 'action_in_progress', 'closed', 'verified'];

    public function index(Request $request): Response
    {
        $rows = QualityNCR::query()
            ->with(['department:id,name', 'assignee:id,name', 'reporter:id,name'])
            ->orderByRaw("FIELD(status,'action_in_progress','under_review','open','action_assigned','verified','closed')")
            ->orderByDesc('detected_date')
            ->get()
            ->map(fn (QualityNCR $n) => $this->present($n));

        return Inertia::render('Quality/NcrRegister', [
            'title'   => 'NCR Register',
            'ncrs'    => $rows->values(),
            'stats'   => $this->stats(),
            'options' => [
                'severities'  => self::SEVERITIES,
                'statuses'    => self::STATUSES,
                'departments' => DB::table('departments')->orderBy('name')->get(['id', 'name']),
                'users'       => User::orderBy('name')->limit(200)->get(['id', 'name']),
            ],
            'can' => [
                'create' => $request->user()->can('quality.ncr.create'),
                'update' => $request->user()->can('quality.ncr.update'),
                'delete' => $request->user()->can('quality.ncr.delete'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAbility($request, 'quality.ncr.create');
        $data = $this->validated($request);

        $data['reported_by'] = Auth::id();
        $data['ncr_number'] = $data['ncr_number'] ?: $this->nextNumber();

        $ncr = DB::transaction(fn () => QualityNCR::create($data));

        return response()->json(['message' => "NCR {$ncr->ncr_number} created.", 'ncr' => $this->present($ncr->fresh(['department', 'assignee', 'reporter']))]);
    }

    public function update(Request $request, QualityNCR $ncr)
    {
        $this->authorizeAbility($request, 'quality.ncr.update');
        $data = $this->validated($request, $ncr->id);

        DB::transaction(fn () => $ncr->update($data));

        return response()->json(['message' => "NCR {$ncr->ncr_number} updated.", 'ncr' => $this->present($ncr->fresh(['department', 'assignee', 'reporter']))]);
    }

    /** Quick status workflow action. */
    public function transition(Request $request, QualityNCR $ncr)
    {
        $this->authorizeAbility($request, 'quality.ncr.update');
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'note'   => ['nullable', 'string', 'max:2000'],
        ]);

        $status = $validated['status'];
        $patch = ['status' => $status];
        if ($status === 'closed') {
            $patch['closure_date'] = now()->toDateString();
            $patch['closed_by'] = Auth::id();
        }
        if ($status === 'verified') {
            $patch['verification_date'] = now()->toDateString();
            $patch['verified_by'] = Auth::id();
        }
        if (in_array($status, self::OPEN_STATUSES)) {
            $patch['closure_date'] = null;
        }
        if (! empty($validated['note'])) {
            $patch['lessons_learned'] = trim(($ncr->lessons_learned ? $ncr->lessons_learned . "\n" : '') . now()->format('d M Y') . ' — ' . $validated['note']);
        }

        DB::transaction(fn () => $ncr->update($patch));

        return response()->json(['message' => "NCR {$ncr->ncr_number} → " . str_replace('_', ' ', $status) . '.', 'ncr' => $this->present($ncr->fresh(['department', 'assignee', 'reporter']))]);
    }

    public function destroy(Request $request, QualityNCR $ncr)
    {
        $this->authorizeAbility($request, 'quality.ncr.delete');
        $number = $ncr->ncr_number;
        DB::transaction(fn () => $ncr->delete());

        return response()->json(['message' => "NCR {$number} deleted."]);
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'ncr_number'         => ['nullable', 'string', 'max:255', Rule::unique('quality_ncrs', 'ncr_number')->ignore($ignoreId)],
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['required', 'string'],
            'severity'           => ['required', Rule::in(self::SEVERITIES)],
            'status'             => ['required', Rule::in(self::STATUSES)],
            'department_id'      => ['nullable', 'exists:departments,id'],
            'assigned_to'        => ['nullable', 'exists:users,id'],
            'detected_date'      => ['required', 'date'],
            'root_cause_analysis'=> ['nullable', 'string'],
            'immediate_action'   => ['nullable', 'string'],
            'corrective_action'  => ['nullable', 'string'],
            'preventive_action'  => ['nullable', 'string'],
            'closure_date'       => ['nullable', 'date'],
        ]);
    }

    private function nextNumber(): string
    {
        $max = 0;
        QualityNCR::pluck('ncr_number')->each(function ($n) use (&$max) {
            if (preg_match('/(\d+)/', (string) $n, $m)) $max = max($max, (int) $m[1]);
        });

        return sprintf('NCR-%03d', $max + 1);
    }

    private function stats(): array
    {
        $bySeverity = QualityNCR::whereIn('status', self::OPEN_STATUSES)
            ->select('severity', DB::raw('count(*) c'))->groupBy('severity')->pluck('c', 'severity');
        $byStatus = QualityNCR::select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status');

        return [
            'issued'       => QualityNCR::count(),
            'open'         => QualityNCR::whereIn('status', self::OPEN_STATUSES)->count(),
            'consent'      => (int) ($byStatus['verified'] ?? 0),
            'closed'       => (int) ($byStatus['closed'] ?? 0),
            'in_process'   => (int) ($byStatus['action_in_progress'] ?? 0),
            'under_review' => (int) ($byStatus['under_review'] ?? 0),
            'severity'     => [
                'critical' => (int) ($bySeverity['critical'] ?? 0),
                'major'    => (int) ($bySeverity['major'] ?? 0),
                'minor'    => (int) ($bySeverity['minor'] ?? 0),
            ],
        ];
    }

    private function present(QualityNCR $n): array
    {
        return [
            'id'            => $n->id,
            'ncr_number'    => $n->ncr_number,
            'title'         => $n->title,
            'description'   => $n->description,
            'severity'      => $n->severity,
            'status'        => $n->status,
            'is_open'       => in_array($n->status, self::OPEN_STATUSES),
            'department_id' => $n->department_id,
            'department'    => $n->department->name ?? null,
            'assigned_to'   => $n->assigned_to,
            'assignee'      => $n->assignee->name ?? null,
            'reporter'      => $n->reporter->name ?? null,
            'detected_date' => optional($n->detected_date)->toDateString(),
            'closure_date'  => optional($n->closure_date)->toDateString(),
            'root_cause_analysis' => $n->root_cause_analysis,
            'immediate_action'    => $n->immediate_action,
            'corrective_action'   => $n->corrective_action,
            'preventive_action'   => $n->preventive_action,
            'chainage_m'    => $this->chainageMeters($n->title . ' ' . $n->description),
        ];
    }

    private function chainageMeters(?string $text): ?int
    {
        if ($text && preg_match('/(\d{1,2})\s*\+\s*(\d{1,3})/', $text, $m)) {
            $meters = ((int) $m[1]) * 1000 + (int) $m[2];
            return ($meters >= 0 && $meters <= 48000) ? $meters : null;
        }
        return null;
    }

    private function authorizeAbility(Request $request, string $ability): void
    {
        abort_unless($request->user()?->can($ability), 403);
    }
}
