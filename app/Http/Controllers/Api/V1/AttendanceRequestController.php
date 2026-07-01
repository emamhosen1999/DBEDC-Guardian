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
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceRequestController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RegularizationService $regularization,
        private readonly OvertimeService $overtime,
        private readonly CompOffService $compOff,
        private readonly RosterService $roster,
        private readonly RosterOverlayService $overlay,
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
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $rows = RosterDay::with(['shift:id,code,color,name,start_time,end_time,type,crosses_midnight', 'user:id,name'])
            ->where('user_id', $request->user()->id)
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

        $overlay = $this->overlay->forRange([$request->user()->id], $data['from'], $data['to']);
        $daysArr = $days->toArray();

        foreach (($overlay['leave'][$request->user()->id] ?? []) as $date => $info) {
            if (! isset($daysArr[$date])) {
                $daysArr[$date] = [
                    'code' => null, 'name' => null, 'color' => null, 'type' => null,
                    'start' => null, 'end' => null, 'crosses_midnight' => false, 'off' => true,
                ];
            }
            $daysArr[$date]['leave'] = $info;
        }

        return $this->successResponse([
            'name' => $request->user()->name,
            'days' => $daysArr,
            'holidays' => $overlay['holidays'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Shift swaps (ESS) — request, my list, inbox (awaiting my consent), respond
    // -------------------------------------------------------------------------

    /**
     * Roster-availability check (mirrors the web ShiftSwapController): a swap is
     * only valid if the requester is scheduled on their date, the counterparty is
     * free that date, and — for a trade — the counterparty works (and the requester
     * is free) on the return date. Returns [field, message] or null.
     */
    private function rosterAvailabilityProblem(string $type, int $requesterId, int $counterpartyId, string $requesterDate, ?string $counterpartyDate): ?array
    {
        if ($this->roster->effectiveShiftId($requesterId, $requesterDate) === null) {
            return ['requester_date', 'You are not scheduled to work on that date.'];
        }
        if ($this->roster->effectiveShiftId($counterpartyId, $requesterDate) !== null) {
            return ['counterparty_id', 'The counterparty is already scheduled on that date.'];
        }
        if ($type === 'swap') {
            if (! $counterpartyDate) {
                return ['counterparty_date', 'Select the shift you will take in return.'];
            }
            if ($this->roster->effectiveShiftId($counterpartyId, $counterpartyDate) === null) {
                return ['counterparty_date', 'The counterparty is not scheduled to work on that date.'];
            }
            if ($this->roster->effectiveShiftId($requesterId, $counterpartyDate) !== null) {
                return ['counterparty_date', 'You are already scheduled on that date.'];
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
            $swap->update(['counterparty_status' => 'accepted']);

            return $this->successResponse($swap->fresh(), 'Swap accepted; sent to your manager for final approval.');
        }

        $swap->update(['counterparty_status' => 'declined', 'status' => 'rejected']);

        return $this->successResponse($swap->fresh(), 'Swap declined.');
    }

    /**
     * Same-department Employees who are FREE on the given date — the swap/cover
     * counterparty picker (mirrors the web ShiftSwapController@eligible).
     */
    public function swapEligible(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date']);
        $user = $request->user();
        $date = Carbon::parse($data['date'])->toDateString();

        // Pin to the web guard: roles are registered for 'web', but this API runs
        // under sanctum, so the scope's default-guard lookup would otherwise throw
        // RoleDoesNotExist when the app's default guard resolves to 'sanctum'.
        $employees = User::role('Employee', 'web')
            ->where('id', '!=', $user->id)
            ->where('department_id', $user->department_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(function ($c) use ($date) {
                // A coworker whose roster can't be resolved (e.g. misconfigured
                // rotation) is not offered as free — never 500 the whole picker.
                try {
                    return $this->roster->effectiveShiftId($c->id, $date) === null;
                } catch (\Throwable $e) {
                    report($e);

                    return false;
                }
            })
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
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
}
