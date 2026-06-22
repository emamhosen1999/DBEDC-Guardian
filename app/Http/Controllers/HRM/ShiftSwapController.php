<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\RosterDay;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Services\Attendance\RosterService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftSwapController extends Controller
{
    public function __construct(private readonly RosterService $roster) {}

    public function index(Request $request): JsonResponse
    {
        $swaps = ShiftSwapRequest::with(['requester', 'counterparty'])
            ->orderByDesc('created_at')
            ->get();

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

        // Requester must actually be rostered to work the day they give up.
        if ($this->roster->effectiveShiftId($requester->id, Carbon::parse($data['requester_date'])->toDateString()) === null) {
            $fail('requester_date', 'You are not scheduled to work on that date.');
        }
        // Counterparty must be free that day to take the shift.
        if ($this->roster->effectiveShiftId($counterparty->id, Carbon::parse($data['requester_date'])->toDateString()) !== null) {
            $fail('counterparty_id', 'The counterparty is already scheduled on that date.');
        }

        if ($data['type'] === 'swap') {
            if (empty($data['counterparty_date'])) {
                $fail('counterparty_date', 'Select the shift you will take in return.');
            }
            $cpDate = Carbon::parse($data['counterparty_date'])->toDateString();
            if ($this->roster->effectiveShiftId($counterparty->id, $cpDate) === null) {
                $fail('counterparty_date', 'The counterparty is not scheduled to work on that date.');
            }
            if ($this->roster->effectiveShiftId($requester->id, $cpDate) !== null) {
                $fail('counterparty_date', 'You are already scheduled on that date.');
            }
        } else {
            $data['counterparty_date'] = null; // a cover has no return shift
        }

        $swap = DB::transaction(fn () => ShiftSwapRequest::create([
            'type' => $data['type'],
            'requester_id' => $requester->id,
            'requester_date' => $data['requester_date'],
            'counterparty_id' => $counterparty->id,
            'counterparty_date' => $data['counterparty_date'] ?? null,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
            'counterparty_status' => 'pending',
        ]));

        return response()->json([
            'message' => 'Swap request sent to the counterparty for confirmation.',
            'swap' => $swap,
        ], 201);
    }

    /**
     * Same-department Employees who are FREE on the given date — the swap/cover
     * partner picker.
     */
    public function eligible(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date']);
        $user = $request->user();
        $date = Carbon::parse($data['date'])->toDateString();

        $employees = User::role('Employee')
            ->where('id', '!=', $user->id)
            ->where('department_id', $user->department_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn ($c) => $this->roster->effectiveShiftId($c->id, $date) === null)
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->values();

        return response()->json(['employees' => $employees]);
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
            $swap->update(['counterparty_status' => 'accepted']);

            return response()->json(['message' => 'Swap accepted; sent to your manager for final approval.', 'swap' => $swap->fresh()]);
        }

        $swap->update(['counterparty_status' => 'declined', 'status' => 'rejected']);

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

    public function approve(Request $request, int $id): JsonResponse
    {
        $swap = ShiftSwapRequest::findOrFail($id);
        abort_if($swap->status !== 'pending', 409, 'This swap request has already been decided.');
        abort_if($swap->counterparty_status === 'pending', 409, 'Awaiting the counterparty\'s confirmation before approval.');

        DB::transaction(function () use ($swap, $request) {
            $swap->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
            ]);

            $this->roster->applySwap($swap->fresh());
        });

        return response()->json(['message' => 'Swap approved and applied.', 'swap' => $swap->fresh()]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $swap = ShiftSwapRequest::findOrFail($id);
        abort_if($swap->status !== 'pending', 409, 'This swap request has already been decided.');

        $swap->update([
            'status' => 'rejected',
            'approved_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Swap rejected.', 'swap' => $swap->fresh()]);
    }
}
