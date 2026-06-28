<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\RosterDay;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Notifications\Attendance\ShiftSwapDecidedNotification;
use App\Notifications\Attendance\ShiftSwapRequestedNotification;
use App\Services\Attendance\RosterService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShiftSwapController extends Controller
{
    public function __construct(private readonly RosterService $roster) {}

    /**
     * Roster-availability check shared by store (request time) and approve (apply time):
     * a swap valid when requested but whose rosters changed before approval must fail
     * loudly instead of corrupting the roster. Returns [field, message] or null.
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

        DB::transaction(function () use ($swap, $request) {
            $swap->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
            ]);

            $this->roster->applySwap($swap->fresh());
        });

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
