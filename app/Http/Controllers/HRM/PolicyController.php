<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\PolicySimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PolicyController extends Controller
{
    // -----------------------------------------------------------------------
    // Shared validation rules for store / update
    // -----------------------------------------------------------------------
    private function policyRules(): array
    {
        return [
            'name'                   => 'required|string|max:120',
            'scope_type'             => 'required|in:org,department,designation,user',
            'scope_id'               => 'nullable|integer',
            'effective_from'         => 'required|date',
            'effective_to'           => 'nullable|date|after_or_equal:effective_from',
            'punch_strictness'       => 'required|in:warn,flag,restrict',
            'outside_window_minutes' => 'integer|min:0|max:1440',
            'grace_tiers'            => 'nullable|array',
            'rounding'               => 'nullable|array',
        ];
    }

    // -----------------------------------------------------------------------
    // Map an AttendancePolicy model instance to a plain array for responses
    // -----------------------------------------------------------------------
    private function mapPolicy(AttendancePolicy $p): array
    {
        return [
            'id'                     => $p->id,
            'name'                   => $p->name,
            'scope_type'             => $p->scope_type,
            'scope_id'               => $p->scope_id,
            'priority'               => $p->priority,
            'effective_from'         => $p->effective_from?->toDateString(),
            'effective_to'           => $p->effective_to?->toDateString(),
            'version_group_id'       => $p->version_group_id,
            'version'                => $p->version,
            'status'                 => $p->status,
            'punch_strictness'       => $p->punch_strictness,
            'outside_window_minutes' => $p->outside_window_minutes,
            'grace_tiers'            => $p->grace_tiers,
            'rounding'               => $p->rounding,
        ];
    }

    // -----------------------------------------------------------------------
    // GET /attendance/policies
    // -----------------------------------------------------------------------
    public function index(): JsonResponse
    {
        $policies = AttendancePolicy::query()
            ->get()
            ->map(fn ($p) => $this->mapPolicy($p))
            ->values();

        return response()->json(['policies' => $policies]);
    }

    // -----------------------------------------------------------------------
    // POST /attendance/policies
    // -----------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->policyRules());

        $data['status']           = 'draft';
        $data['version']          = 1;
        $data['version_group_id'] = (AttendancePolicy::max('version_group_id') ?? 0) + 1;
        $data['created_by']       = $request->user()->id;

        $policy = AttendancePolicy::create($data);

        return response()->json($this->mapPolicy($policy), 201);
    }

    // -----------------------------------------------------------------------
    // PUT /attendance/policies/{id}
    // -----------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $policy = AttendancePolicy::findOrFail($id);

        abort_unless($policy->status === 'draft', 422, 'Only draft policies can be edited.');

        $data = $request->validate($this->policyRules());

        $policy->update($data);

        return response()->json($this->mapPolicy($policy->fresh()));
    }

    // -----------------------------------------------------------------------
    // POST /attendance/policies/{id}/activate
    // -----------------------------------------------------------------------
    public function activate(Request $request, int $id): JsonResponse
    {
        $policy = AttendancePolicy::findOrFail($id);

        DB::transaction(function () use ($policy, $request): void {
            $before = $this->mapPolicy($policy);

            // Supersede any currently active policies in the same version group
            $supersededDate = $policy->effective_from
                ? $policy->effective_from->toDateString()
                : now()->toDateString();

            AttendancePolicy::where('version_group_id', $policy->version_group_id)
                ->where('status', 'active')
                ->where('id', '!=', $policy->id)
                ->each(function (AttendancePolicy $prior) use ($supersededDate): void {
                    $prior->update([
                        'effective_to' => $supersededDate,
                        'status'       => 'archived',
                    ]);
                });

            $policy->update(['status' => 'active']);

            $after = $this->mapPolicy($policy->fresh());

            app(AttendanceAuditService::class)->record(
                'policy.activate',
                $policy->id,
                $before,
                $after,
                null,
                $request
            );
        });

        return response()->json($this->mapPolicy($policy->fresh()));
    }

    // -----------------------------------------------------------------------
    // POST /attendance/policies/simulate
    // -----------------------------------------------------------------------
    public function simulate(Request $request): JsonResponse
    {
        $request->validate([
            'from'     => 'required|date',
            'to'       => 'required|date|after_or_equal:from',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer',
            // Policy fields (same rules as store/update, but all optional for simulation)
            'name'                   => 'nullable|string|max:120',
            'scope_type'             => 'nullable|in:org,department,designation,user',
            'scope_id'               => 'nullable|integer',
            'effective_from'         => 'nullable|date',
            'effective_to'           => 'nullable|date',
            'punch_strictness'       => 'nullable|in:warn,flag,restrict',
            'outside_window_minutes' => 'nullable|integer|min:0|max:1440',
            'grace_tiers'            => 'nullable|array',
            'rounding'               => 'nullable|array',
        ]);

        // Build a non-persisted draft policy from the request fields
        $draft = new AttendancePolicy([
            'name'                   => $request->input('name'),
            'scope_type'             => $request->input('scope_type', 'org'),
            'scope_id'               => $request->input('scope_id'),
            'effective_from'         => $request->input('effective_from'),
            'effective_to'           => $request->input('effective_to'),
            'punch_strictness'       => $request->input('punch_strictness', 'warn'),
            'outside_window_minutes' => $request->input('outside_window_minutes', 120),
            'grace_tiers'            => $request->input('grace_tiers'),
            'rounding'               => $request->input('rounding'),
        ]);

        $userIds = $request->input('user_ids')
            ?? User::role('Employee')->pluck('id')->all();

        $from = $request->input('from');
        $to   = $request->input('to');

        $result = app(PolicySimulationService::class)->simulate($draft, $userIds, $from, $to);

        return response()->json($result);
    }
}
