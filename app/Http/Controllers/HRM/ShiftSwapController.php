<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\Designation;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Notifications\Attendance\ShiftSwapDecidedNotification;
use App\Notifications\Attendance\ShiftSwapRequestedNotification;
use App\Services\Attendance\RosterService;
use App\Services\Attendance\WorkTimeComplianceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShiftSwapController extends Controller
{
    public function __construct(
        private readonly RosterService $roster,
        private readonly WorkTimeComplianceService $compliance,
    ) {}

    /**
     * Roster-availability check shared by store (request time) and approve (apply time).
     * Validates that both parties are scheduled on their respective dates.
     * Does NOT restrict based on whether the counterparty is free — any same-department
     * employee can swap any shift (same day or different day).
     * Returns [field, message] or null.
     */
    private function rosterAvailabilityProblem(string $type, int $requesterId, int $counterpartyId, string $requesterDate, ?string $counterpartyDate): ?array
    {
        if ($this->roster->effectiveShiftId($requesterId, $requesterDate) === null) {
            return ['requester_date', 'You are not scheduled to work on that date.'];
        }

        // Counterparty cannot be busy on requester_date (must be off/free to take/cover the requester's shift)
        if ($this->roster->effectiveShiftId($counterpartyId, $requesterDate) !== null) {
            $field = $type === 'cover' ? 'counterparty_id' : 'counterparty_date';

            return [$field, 'The counterparty is already scheduled to work on that date.'];
        }

        if ($type === 'swap') {
            if (! $counterpartyDate) {
                return ['counterparty_date', 'Select the shift you will take in return.'];
            }
            if ($this->roster->effectiveShiftId($counterpartyId, $counterpartyDate) === null) {
                return ['counterparty_date', 'The counterparty is not scheduled to work on that date.'];
            }
            // Requester cannot be busy on counterparty_date (must be off/free to take the counterparty's shift)
            if ($this->roster->effectiveShiftId($requesterId, $counterpartyDate) !== null) {
                return ['counterparty_date', 'You are already scheduled to work on that date.'];
            }
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        $shifts = Shift::all()->keyBy('id');

        $swaps = ShiftSwapRequest::with(['requester', 'counterparty'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($swap) use ($shifts) {
                // Prefer the shift codes snapshotted at request time. Once a swap
                // is approved, applySwap rewrites the roster, so a live lookup
                // would return the POST-swap shift (usually OFF) instead of what
                // was actually swapped. requester_shift_code is the sentinel:
                // a non-null value means "trust the snapshot"; only legacy rows
                // (null) fall back to a live roster derivation.
                if ($swap->requester_shift_code !== null) {
                    return $swap;
                }

                $reqDateStr = $swap->requester_date->toDateString();
                $reqShiftId = $this->roster->effectiveShiftId($swap->requester_id, $reqDateStr);
                $swap->requester_shift_code = $reqShiftId ? ($shifts[$reqShiftId]?->code ?? null) : 'OFF';

                if ($swap->counterparty_id) {
                    $cpDateStr = $swap->counterparty_date ? $swap->counterparty_date->toDateString() : $reqDateStr;
                    $cpShiftId = $this->roster->effectiveShiftId($swap->counterparty_id, $cpDateStr);
                    $swap->counterparty_shift_code = $cpShiftId ? ($shifts[$cpShiftId]?->code ?? null) : 'OFF';
                } else {
                    $swap->counterparty_shift_code = null;
                }

                return $swap;
            });

        return response()->json(['swaps' => $swaps]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:swap,cover',
            'requester_date' => 'required|date',
            'counterparty_id' => 'required|integer|exists:users,id',
            'counterparty_date' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $requester = $request->user();
        $counterparty = User::findOrFail($data['counterparty_id']);

        $fail = static fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($counterparty->id === $requester->id) {
            $fail('counterparty_id', 'You cannot swap with yourself.');
        }
        if ($counterparty->department_id === null || $counterparty->department_id !== $requester->department_id) {
            $fail('counterparty_id', 'The counterparty must be in your department.');
        }
        if (! $counterparty->hasRole('Employee')) {
            $fail('counterparty_id', 'The counterparty must be an employee.');
        }

        $cpDate = ($data['counterparty_date'] ?? null) ? Carbon::parse($data['counterparty_date'])->toDateString() : null;
        if ($problem = $this->rosterAvailabilityProblem($data['type'], $requester->id, $counterparty->id, Carbon::parse($data['requester_date'])->toDateString(), $cpDate)) {
            $fail($problem[0], $problem[1]);
        }
        if ($data['type'] === 'cover') {
            $data['counterparty_date'] = null; // a cover has no return shift
        }

        // Snapshot the give-up / take shift codes now, at request time — the
        // display must never re-derive them from the live roster (applySwap
        // rewrites it on approval). requester_shift_code is always set (OFF when
        // off) so it can act as the "snapshotted" sentinel on read.
        $shiftCodeById = Shift::pluck('code', 'id');
        $codeFor = function (?int $userId, ?string $date) use ($shiftCodeById): string {
            if (! $userId || ! $date) {
                return 'OFF';
            }
            $shiftId = $this->roster->effectiveShiftId($userId, $date);

            return $shiftId ? ($shiftCodeById[$shiftId] ?? 'OFF') : 'OFF';
        };
        $requesterShiftCode = $codeFor($requester->id, Carbon::parse($data['requester_date'])->toDateString());
        $counterpartyShiftCode = ($data['type'] === 'cover' || ! $cpDate)
            ? null
            : $codeFor($counterparty->id, $cpDate);

        $swap = DB::transaction(fn () => ShiftSwapRequest::create([
            'type' => $data['type'],
            'requester_id' => $requester->id,
            'requester_date' => $data['requester_date'],
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => $data['counterparty_date'] ?? null,
            'requester_shift_code' => $requesterShiftCode,
            'counterparty_shift_code' => $counterpartyShiftCode,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
            'counterparty_status' => 'pending',
            'approval_chain' => [
                [
                    'action' => 'requested',
                    'user_id' => $requester->id,
                    'user_name' => $requester->name,
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ]));

        // Notify the counterparty (target of the swap request)
        try {
            $counterparty->notify(new ShiftSwapRequestedNotification($swap->id, $requester->name));
        } catch (\Throwable $exception) {
            Log::warning("ShiftSwapRequestedNotification failed for swap #{$swap->id}", [
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Swap request sent to the counterparty for confirmation.',
            'swap' => $swap,
        ], 201);
    }

    /**
     * Same-department, rank-eligible teammates for the given user — the shared
     * candidate pool behind both the swap partner picker (eligible) and the
     * reverse pick-up direction (pickup), BEFORE any date-availability filter.
     *
     * "Rank-eligible" = same or LOWER designation (hierarchy_level >= the
     * requester's level), or no designation at all. If the requester has no
     * designation, all same-department Employees qualify. The requester is
     * always excluded.
     *
     * @return \Illuminate\Support\Collection<int, User> id + name only
     */
    private function deptRankEligibleTeammates(User $user): \Illuminate\Support\Collection
    {
        $query = User::role('Employee')
            ->where('users.id', '!=', $user->id)
            ->where('users.department_id', $user->department_id);

        // Only include teammates with same or lower designation (higher hierarchy_level number)
        $requesterLevel = $user->designation_id
            ? Designation::where('id', $user->designation_id)->value('hierarchy_level')
            : null;

        if ($requesterLevel !== null) {
            $query->leftJoin('designations', 'users.designation_id', '=', 'designations.id')
                ->where(function ($q) use ($requesterLevel) {
                    $q->where('designations.hierarchy_level', '>=', $requesterLevel)
                        ->orWhereNull('users.designation_id');
                })
                ->select('users.id', 'users.name');
        }

        return $query
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);
    }

    /**
     * Same-department Employees — the swap/cover partner picker.
     * Returns same-department employees with the SAME or LOWER designation
     * (hierarchy_level >= requester's level) who are FREE on the date, so any
     * shift can be swapped with any eligible colleague. If the requester has
     * no designation, all same-department employees are considered.
     *
     * Also returns an `eligibility` block so the client can explain the filter
     * ("1 of 5 teammates are free; 4 are already rostered"). The `employees`
     * list is unchanged (free-on-date only) and stays top-level for back-compat.
     */
    public function eligible(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date']);
        $user = $request->user();
        $date = Carbon::parse($data['date'])->toDateString();

        $teammates = $this->deptRankEligibleTeammates($user);
        $departmentEligibleTotal = $teammates->count();

        // Availability filter: only teammates with NO effective shift that date.
        $employees = $teammates
            ->filter(fn ($u) => $this->roster->effectiveShiftId($u->id, $date) === null)
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->values();

        $availableCount = $employees->count();

        return response()->json([
            'employees' => $employees,
            'eligibility' => [
                'date' => $date,
                'department_eligible_total' => $departmentEligibleTotal,
                'available_count' => $availableCount,
                'busy_count' => $departmentEligibleTotal - $availableCount,
            ],
        ]);
    }

    /**
     * A same-department coworker's WORKING roster days in a range — the "shift you
     * will take" picker for a swap. Guarded to the requester's own department.
     */
    public function counterpartyRoster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counterparty_id' => 'required|integer|exists:users,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $user = $request->user();
        $counterparty = User::findOrFail($data['counterparty_id']);
        abort_unless(
            $counterparty->department_id !== null && $counterparty->department_id === $user->department_id,
            403,
            'The counterparty must be in your department.'
        );

        $fmt = static fn ($t) => $t ? Carbon::parse($t)->format('H:i') : null;

        $days = RosterDay::with('shift:id,code,name,start_time,end_time')
            ->where('user_id', $counterparty->id)
            ->whereNotNull('shift_id')
            ->whereBetween('date', [$data['from'], $data['to']])
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date->format('Y-m-d'),
                'code' => $r->shift?->code,
                'name' => $r->shift?->name,
                'start' => $fmt($r->shift?->start_time),
                'end' => $fmt($r->shift?->end_time),
            ])
            ->values();

        return response()->json(['days' => $days]);
    }

    /**
     * Reverse direction of the swap picker — "shifts I could pick up".
     *
     * Returns same-department, rank-eligible teammates' WORKING shifts in
     * [from, to] that the REQUESTER could take, i.e. only on dates the
     * requester is FREE (no effective shift → no double-booking). The
     * requester is excluded and the rank/department pool is the SAME as
     * eligible() (deptRankEligibleTeammates). Ordered by date.
     *
     * Range defaults to the next 14 days and is capped at 31 days.
     */
    public function pickup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $user = $request->user();

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : Carbon::today();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->startOfDay() : $from->copy()->addDays(13);

        // Cap the range so we never scan an unbounded roster window.
        if ($from->diffInDays($to) > 31) {
            throw ValidationException::withMessages([
                'to' => 'The date range cannot exceed 31 days.',
            ]);
        }

        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        $teammates = $this->deptRankEligibleTeammates($user);
        if ($teammates->isEmpty()) {
            return response()->json(['shifts' => []]);
        }

        $names = $teammates->pluck('name', 'id');
        $fmt = static fn ($t) => $t ? Carbon::parse($t)->format('H:i') : null;

        // Memoize the requester-free check per date (many counterparties can
        // work the same date; the invariant is one query per unique date).
        $requesterFree = [];
        $isRequesterFree = function (string $date) use (&$requesterFree, $user): bool {
            return $requesterFree[$date] ??= ($this->roster->effectiveShiftId($user->id, $date) === null);
        };

        $shifts = RosterDay::with('shift:id,code,name,start_time,end_time')
            ->whereIn('user_id', $teammates->pluck('id'))
            ->whereNotNull('shift_id')
            ->whereBetween('date', [$fromStr, $toStr])
            ->orderBy('date')
            ->orderBy('user_id')
            ->get()
            // Only shifts the requester can actually take (they are free → no double-booking).
            ->filter(fn ($r) => $isRequesterFree($r->date->toDateString()))
            ->map(fn ($r) => [
                'date' => $r->date->format('Y-m-d'),
                'counterparty_id' => $r->user_id,
                'counterparty_name' => $names[$r->user_id] ?? null,
                'shift_code' => $r->shift?->code,
                'shift_name' => $r->shift?->name,
                'start' => $fmt($r->shift?->start_time),
                'end' => $fmt($r->shift?->end_time),
            ])
            ->values();

        return response()->json(['shifts' => $shifts]);
    }

    /**
     * Counterparty (the affected coworker) accepts or declines — the peer-consent stage.
     * Accept → moves to manager/admin review; Decline → terminal (rejected).
     */
    public function respond(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:accept,decline']);
        $swap = ShiftSwapRequest::findOrFail($id);

        abort_unless($swap->counterparty_id === $request->user()->id, 403, 'Only the counterparty can respond to this swap.');
        abort_if($swap->counterparty_status !== 'pending', 409, 'This swap is not awaiting your response.');

        if ($data['decision'] === 'accept') {
            $chain = $swap->approval_chain ?? [];
            $chain[] = [
                'action' => 'counterparty_accepted',
                'user_id' => $request->user()->id,
                'user_name' => $request->user()->name,
                'timestamp' => now()->toIso8601String(),
            ];

            $swap->update([
                'counterparty_status' => 'accepted',
                'approval_chain' => $chain,
            ]);

            return response()->json(['message' => 'Swap accepted; sent to your manager for final approval.', 'swap' => $swap->fresh()]);
        }

        $chain = $swap->approval_chain ?? [];
        $chain[] = [
            'action' => 'counterparty_declined',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'timestamp' => now()->toIso8601String(),
        ];

        $swap->update([
            'counterparty_status' => 'declined',
            'status' => 'rejected',
            'approval_chain' => $chain,
        ]);

        return response()->json(['message' => 'Swap declined.', 'swap' => $swap->fresh()]);
    }

    /**
     * Swaps awaiting THIS user's counterparty response (employee-side inbox).
     */
    public function awaitingMe(Request $request): JsonResponse
    {
        $swaps = ShiftSwapRequest::with(['requester', 'counterparty'])
            ->where('counterparty_id', $request->user()->id)
            ->where('counterparty_status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['swaps' => $swaps]);
    }

    /**
     * Swaps THIS user initiated — the requester-side tracking list (status +
     * counterparty consent stage), so an employee can follow their own requests.
     */
    public function mine(Request $request): JsonResponse
    {
        $swaps = ShiftSwapRequest::with(['counterparty:id,name'])
            ->where('requester_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['swaps' => $swaps]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $swap = ShiftSwapRequest::findOrFail($id);
        abort_if($swap->status !== 'pending', 409, 'This swap request has already been decided.');
        abort_if($swap->counterparty_status === 'pending', 409, 'Awaiting the counterparty\'s confirmation before approval.');

        if ($swap->counterparty_id && ($problem = $this->rosterAvailabilityProblem($swap->type, $swap->requester_id, $swap->counterparty_id, $swap->requester_date->toDateString(), $swap->counterparty_date?->toDateString()))) {
            abort(409, 'The roster changed since this request was made: '.$problem[1].' Please ask the employee to resubmit.');
        }

        $complianceViolations = [];
        $blockedByCompliance = false;

        try {
            DB::transaction(function () use ($swap, $request, &$complianceViolations, &$blockedByCompliance) {
                $chain = $swap->approval_chain ?? [];
                $chain[] = [
                    'action' => 'manager_approved',
                    'user_id' => $request->user()->id,
                    'user_name' => $request->user()->name,
                    'timestamp' => now()->toIso8601String(),
                ];

                $swap->update([
                    'status' => 'approved',
                    'approved_by' => $request->user()->id,
                    'approval_chain' => $chain,
                ]);

                $this->roster->applySwap($swap->fresh());

                // Working-time compliance: validate every affected party's
                // surrounding +/-7 day window against the roster as it now
                // stands (still inside this transaction). Enforce mode rolls
                // the whole swap back for a severity=error violation;
                // warnings are always returned but never block.
                $complianceViolations = $this->evaluateSwapCompliance($swap->fresh());
                $hasBlockingError = collect($complianceViolations)
                    ->flatten(1)
                    ->contains(fn (array $v) => ($v['severity'] ?? null) === 'error');

                if (config('attendance.compliance.enforce') && $hasBlockingError) {
                    $blockedByCompliance = true;
                    throw new \RuntimeException('Swap blocked by working-time compliance.');
                }
            });
        } catch (\RuntimeException $exception) {
            if ($blockedByCompliance) {
                return response()->json([
                    'message' => 'This swap violates working-time compliance rules and was not applied.',
                    'compliance_violations' => $complianceViolations,
                ], 422);
            }

            throw $exception;
        }

        // Notify the requester that their swap was approved
        $requesterUser = User::find($swap->requester_id);
        if ($requesterUser) {
            try {
                $requesterUser->notify(new ShiftSwapDecidedNotification($swap->id, 'approved'));
            } catch (\Throwable $exception) {
                Log::warning("ShiftSwapDecidedNotification(approved) failed for swap #{$swap->id}", [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Swap approved and applied.',
            'swap' => $swap->fresh(),
            'compliance_violations' => $complianceViolations,
        ]);
    }

    /**
     * Working-time compliance for both parties of an (already applied) swap,
     * over each party's surrounding +/-7 day window around the swap dates.
     *
     * @return array<int, array<int, array{date: string, rule: string, message: string, severity: string, details: array}>>
     */
    private function evaluateSwapCompliance(ShiftSwapRequest $swap): array
    {
        $dates = array_filter([
            $swap->requester_date?->toDateString(),
            $swap->counterparty_date?->toDateString(),
        ]);
        if (empty($dates)) {
            return [];
        }

        $from = Carbon::parse(min($dates))->subDays(7)->toDateString();
        $to = Carbon::parse(max($dates))->addDays(7)->toDateString();

        $violations = [];
        foreach (array_filter([$swap->requester_id, $swap->counterparty_id]) as $userId) {
            $userViolations = $this->compliance->evaluate((int) $userId, $from, $to);
            if ($userViolations) {
                $violations[$userId] = $userViolations;
            }
        }

        return $violations;
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $swap = ShiftSwapRequest::findOrFail($id);
        abort_if($swap->status !== 'pending', 409, 'This swap request has already been decided.');

        $chain = $swap->approval_chain ?? [];
        $chain[] = [
            'action' => 'manager_rejected',
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'timestamp' => now()->toIso8601String(),
        ];

        $swap->update([
            'status' => 'rejected',
            'approved_by' => $request->user()->id,
            'approval_chain' => $chain,
        ]);

        // Notify the requester that their swap was rejected
        $requesterUser = User::find($swap->requester_id);
        if ($requesterUser) {
            try {
                $requesterUser->notify(new ShiftSwapDecidedNotification($swap->id, 'rejected'));
            } catch (\Throwable $exception) {
                Log::warning("ShiftSwapDecidedNotification(rejected) failed for swap #{$swap->id}", [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json(['message' => 'Swap rejected.', 'swap' => $swap->fresh()]);
    }
}
