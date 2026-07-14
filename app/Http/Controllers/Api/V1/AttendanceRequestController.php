<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\HRM\AttendanceRegularization;
use App\Models\HRM\CompOffLedger;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\RosterDay;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Services\Attendance\CompOffService;
use App\Services\Attendance\OvertimeService;
use App\Services\Attendance\RegularizationService;
use App\Services\Attendance\RosterOverlayService;
use App\Services\Attendance\RosterService;
use App\Services\Attendance\AttendanceApprovalService;
use App\Notifications\Attendance\TimeCorrectionDecidedNotification;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTeamMembers;

class AttendanceRequestController extends Controller
{
    use ResolvesTeamMembers;
    use ApiResponse;

    public function __construct(
        private readonly RegularizationService $regularization,
        private readonly OvertimeService $overtime,
        private readonly CompOffService $compOff,
        private readonly RosterService $roster,
        private readonly RosterOverlayService $overlay,
        private readonly AttendanceApprovalService $approvals,
    ) {}

    // -------------------------------------------------------------------------
    // Regularization
    // -------------------------------------------------------------------------

    public function storeRegularization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'               => 'required|date',
            'type'               => 'required|in:missing_punchin,missing_punchout,wrong_time,missed_day,other',
            'requested_punchin'  => 'nullable|date',
            'requested_punchout' => 'nullable|date',
            'reason'             => 'required|string|max:500',
        ]);

        $regularization = $this->regularization->request($request->user()->id, $data);

        return $this->successResponse($regularization, 'Regularization request submitted.', 201);
    }

    public function myRegularizations(Request $request): JsonResponse
    {
        $items = AttendanceRegularization::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return $this->successResponse($items);
    }

    public function pendingRegularizations(Request $request): JsonResponse
    {
        if (! $this->isManagerUser($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $status = $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $requests = $this->approvals->forApprover($request->user(), AttendanceRegularization::class, $status)
            ->load(['user:id,name,employee_id', 'user.media']);

        return $this->successResponse($requests->values());
    }

    public function approveRegularization(Request $request, int $id): JsonResponse
    {
        if (! $this->isManagerUser($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $r = AttendanceRegularization::findOrFail($id);
        $res = $this->regularization->approve($r, $request->user(), $request->input('comments'));

        if ($res['success'] ?? false) {
            return $this->successResponse($res['request'] ?? $r, 'Regularization approved.');
        }

        return response()->json(['success' => false, 'message' => $res['message'] ?? 'Failed to approve regularization.'], 422);
    }

    public function rejectRegularization(Request $request, int $id): JsonResponse
    {
        if (! $this->isManagerUser($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $data = $request->validate(['reason' => 'required|string|max:500']);
        $r = AttendanceRegularization::findOrFail($id);
        $res = $this->approvals->reject($r, $request->user(), $data['reason']);

        if ($res['success'] ?? false) {
            $requesterUser = User::find($r->user_id);
            if ($requesterUser) {
                try {
                    $requesterUser->notify(new TimeCorrectionDecidedNotification($r->id, 'rejected'));
                } catch (\Throwable $exception) {
                    Log::warning("TimeCorrectionDecidedNotification(rejected) failed for regularization #{$r->id}", [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
            return $this->successResponse($r, 'Regularization rejected.');
        }

        return response()->json(['success' => false, 'message' => $res['message'] ?? 'Failed to reject regularization.'], 422);
    }

    // -------------------------------------------------------------------------
    // Overtime
    // -------------------------------------------------------------------------

    public function storeOvertime(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'              => 'required|date',
            'requested_minutes' => 'required|integer|min:1|max:1440',
            'reason'            => 'required|string|max:500',
        ]);

        $ot = $this->overtime->request($request->user()->id, $data);

        return $this->successResponse($ot, 'Overtime request submitted.', 201);
    }

    public function myOvertime(Request $request): JsonResponse
    {
        $items = OvertimeRequest::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return $this->successResponse($items);
    }

    public function pendingOvertime(Request $request): JsonResponse
    {
        if (! $this->isManagerUser($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $status = $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $requests = $this->approvals->forApprover($request->user(), OvertimeRequest::class, $status)
            ->load(['user:id,name,employee_id', 'user.media']);

        return $this->successResponse($requests->values());
    }

    public function approveOvertime(Request $request, int $id): JsonResponse
    {
        if (! $this->isManagerUser($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $ot = OvertimeRequest::findOrFail($id);
        $res = $this->overtime->approve(
            $ot,
            $request->user(),
            $request->input('comments'),
            $request->boolean('grant_comp_off')
        );

        if ($res['success'] ?? false) {
            return $this->successResponse($res['request'] ?? $ot, 'Overtime approved.');
        }

        return response()->json(['success' => false, 'message' => $res['message'] ?? 'Failed to approve overtime.'], 422);
    }

    public function rejectOvertime(Request $request, int $id): JsonResponse
    {
        if (! $this->isManagerUser($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $data = $request->validate(['reason' => 'required|string|max:500']);
        $ot = OvertimeRequest::findOrFail($id);
        $res = $this->approvals->reject($ot, $request->user(), $data['reason']);

        if ($res['success'] ?? false) {
            return $this->successResponse($ot, 'Overtime rejected.');
        }

        return response()->json(['success' => false, 'message' => $res['message'] ?? 'Failed to reject overtime.'], 422);
    }

    // -------------------------------------------------------------------------
    // Comp-off
    // -------------------------------------------------------------------------

    public function myCompOff(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $balance = $this->compOff->balance($userId);

        $entries = CompOffLedger::where('user_id', $userId)
            ->latest()
            ->limit(50)
            ->get();

        return $this->successResponse([
            'balance_minutes' => $balance,
            'entries'         => $entries,
        ]);
    }

    // -------------------------------------------------------------------------
    // My-roster
    // -------------------------------------------------------------------------

    public function myRoster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from'    => 'required|date',
            'to'      => 'required|date|after_or_equal:from',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $targetUser = $request->user();

        if ($request->filled('user_id')) {
            $userId = (int) $data['user_id'];
            if ($userId !== $request->user()->id) {
                if (! $this->isManagerUser($request->user())) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
                }

                $teamMemberIds = $this->resolveTeamMemberIds($request->user());
                if (! in_array($userId, $teamMemberIds, true)) {
                    return response()->json(['success' => false, 'message' => 'User is not in your team.'], 403);
                }

                $targetUser = User::findOrFail($userId);
            }
        }

        $rows = RosterDay::with(['shift:id,code,color,name,start_time,end_time,type,crosses_midnight', 'user:id,name'])
            ->where('user_id', $targetUser->id)
            ->whereBetween('date', [$data['from'], $data['to']])
            ->get();

        $formatTime = static fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('H:i') : null;

        $days = $rows->keyBy(fn ($row) => $row->date->format('Y-m-d'))
            ->map(fn ($row) => [
                'code'             => $row->shift?->code,
                'name'             => $row->shift?->name,
                'color'            => $row->shift?->color,
                'type'             => $row->shift?->type,
                'start'            => $formatTime($row->shift?->start_time),
                'end'              => $formatTime($row->shift?->end_time),
                'crosses_midnight' => (bool) ($row->shift?->crosses_midnight ?? false),
                'off'              => $row->shift_id === null,
            ]);

        $overlay = $this->overlay->forRange([$targetUser->id], $data['from'], $data['to']);
        $daysArr = $days->toArray();

        foreach (($overlay['leave'][$targetUser->id] ?? []) as $date => $info) {
            if (! isset($daysArr[$date])) {
                $daysArr[$date] = [
                    'code' => null, 'name' => null, 'color' => null, 'type' => null,
                    'start' => null, 'end' => null, 'crosses_midnight' => false, 'off' => true,
                ];
            }
            $daysArr[$date]['leave'] = $info;
        }

        return $this->successResponse([
            'name' => $targetUser->name,
            'days' => $daysArr,
            'holidays' => $overlay['holidays'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Shift swaps (ESS) — request, my list, inbox (awaiting my consent), respond
    // -------------------------------------------------------------------------

    /**
     * Roster-availability check (mirrors the web ShiftSwapController).
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

    public function storeSwap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'              => 'required|in:swap,cover',
            'requester_date'    => 'required|date',
            'counterparty_id'   => 'required|integer|exists:users,id',
            'counterparty_date' => 'nullable|date',
            'reason'            => 'nullable|string|max:500',
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

        $swap = ShiftSwapRequest::create([
            'type'                => $data['type'],
            'requester_id'        => $requester->id,
            'requester_date'      => $data['requester_date'],
            'counterparty_id'     => $counterparty->id,
            'counterparty_date'   => $data['counterparty_date'] ?? null,
            'reason'              => $data['reason'] ?? null,
            'status'              => 'pending',
            'counterparty_status' => 'pending',
            'approval_chain'      => [
                [
                    'action' => 'requested',
                    'user_id' => $requester->id,
                    'user_name' => $requester->name,
                    'timestamp' => now()->toIso8601String(),
                ]
            ],
        ]);

        return $this->successResponse(
            $swap->load(['counterparty:id,name']),
            'Swap request sent to the counterparty for confirmation.',
            201
        );
    }

    public function mySwaps(Request $request): JsonResponse
    {
        $swaps = ShiftSwapRequest::with(['counterparty:id,name'])
            ->where('requester_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->successResponse($swaps);
    }

    public function swapsAwaitingMe(Request $request): JsonResponse
    {
        $swaps = ShiftSwapRequest::with(['requester:id,name'])
            ->where('counterparty_id', $request->user()->id)
            ->where('counterparty_status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return $this->successResponse($swaps);
    }

    public function respondSwap(Request $request, int $id): JsonResponse
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

            return $this->successResponse($swap->fresh(), 'Swap accepted; sent to your manager for final approval.');
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

        return $this->successResponse($swap->fresh(), 'Swap declined.');
    }

    /**
     * Same-department Employees — the swap/cover counterparty picker
     * (mirrors the web ShiftSwapController@eligible).
     * Returns same-department employees with the SAME or LOWER designation
     * (hierarchy_level >= requester's level) so any shift can be swapped
     * with any eligible colleague. If the requester has no designation,
     * all same-department employees are returned.
     */
    public function swapEligible(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date']);
        $user = $request->user();

        // Pin to the web guard: roles are registered for 'web', but this API runs
        // under sanctum, so the scope's default-guard lookup would otherwise throw
        // RoleDoesNotExist when the app's default guard resolves to 'sanctum'.
        $query = User::role('Employee', 'web')
            ->where('users.id', '!=', $user->id)
            ->where('users.department_id', $user->department_id);

        // Only show teammates with same or lower designation (higher hierarchy_level number)
        $requesterLevel = $user->designation_id
            ? \App\Models\HRM\Designation::where('id', $user->designation_id)->value('hierarchy_level')
            : null;

        if ($requesterLevel !== null) {
            $query->leftJoin('designations', 'users.designation_id', '=', 'designations.id')
                ->where(function ($q) use ($requesterLevel) {
                    $q->where('designations.hierarchy_level', '>=', $requesterLevel)
                      ->orWhereNull('users.designation_id');
                })
                ->select('users.id', 'users.name');
        }

        // The avatar comes from the media library (profile_image_url accessor), NOT from a
        // users.profile_image column — that column does not exist in the live schema.
        $employees = $query
            ->with('media')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->filter(fn ($u) => $this->roster->effectiveShiftId($u->id, $data['date']) === null)
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'profile_image_url' => $c->profile_image_url,
            ])
            ->values();

        return $this->successResponse($employees);
    }

    /**
     * A same-department coworker's WORKING roster days in a range — the "shift you
     * will take" picker for a swap (mirrors the web ShiftSwapController@counterpartyRoster).
     */
    public function counterpartyRoster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counterparty_id' => 'required|integer|exists:users,id',
            'from'            => 'required|date',
            'to'              => 'required|date|after_or_equal:from',
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
                'date'  => $r->date->format('Y-m-d'),
                'code'  => $r->shift?->code,
                'name'  => $r->shift?->name,
                'start' => $fmt($r->shift?->start_time),
                'end'   => $fmt($r->shift?->end_time),
            ])
            ->values();

        return $this->successResponse($days);
    }

    public function pendingSwaps(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        if (! $this->isManagerUser($currentUser)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access swap requests.',
            ], 403);
        }

        $teamMemberIds = $this->resolveTeamMemberIds($currentUser);

        $swaps = ShiftSwapRequest::with(['requester.designation', 'counterparty.designation'])
            ->whereIn('requester_id', $teamMemberIds)
            ->where('status', 'pending')
            ->where('counterparty_status', 'approved')
            ->orderByDesc('created_at')
            ->get();

        $shifts = \App\Models\HRM\Shift::all()->keyBy('id');

        $swaps->map(function ($swap) use ($shifts) {
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

        return response()->json([
            'success' => true,
            'data' => $swaps,
        ]);
    }

    public function approveSwap(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $this->isManagerUser($currentUser)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to approve swap requests.',
            ], 403);
        }

        $swap = ShiftSwapRequest::findOrFail($id);
        if ($swap->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This swap request has already been decided.',
            ], 409);
        }
        if ($swap->counterparty_status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Awaiting the counterparty\'s confirmation before approval.',
            ], 409);
        }

        if ($swap->counterparty_id) {
            $problem = $this->rosterAvailabilityProblem(
                $swap->type,
                $swap->requester_id,
                $swap->counterparty_id,
                $swap->requester_date->toDateString(),
                $swap->counterparty_date?->toDateString()
            );

            if ($problem) {
                return response()->json([
                    'success' => false,
                    'message' => 'The roster changed since this request was made: ' . $problem[1],
                ], 409);
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($swap, $request) {
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
        });

        $requesterUser = User::find($swap->requester_id);
        if ($requesterUser) {
            try {
                $requesterUser->notify(new \App\Notifications\Attendance\ShiftSwapDecidedNotification($swap->id, 'approved'));
            } catch (\Throwable $exception) {
                Log::warning("ShiftSwapDecidedNotification(approved) failed for swap #{$swap->id}", [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Swap approved and applied.',
            'data' => $swap->fresh(),
        ]);
    }

    public function rejectSwap(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();
        if (! $this->isManagerUser($currentUser)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to reject swap requests.',
            ], 403);
        }

        $swap = ShiftSwapRequest::findOrFail($id);
        if ($swap->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This swap request has already been decided.',
            ], 409);
        }

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

        $requesterUser = User::find($swap->requester_id);
        if ($requesterUser) {
            try {
                $requesterUser->notify(new \App\Notifications\Attendance\ShiftSwapDecidedNotification($swap->id, 'rejected'));
            } catch (\Throwable $exception) {
                Log::warning("ShiftSwapDecidedNotification(rejected) failed for swap #{$swap->id}", [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Swap rejected.',
            'data' => $swap->fresh(),
        ]);
    }
}
