<?php

namespace App\Services\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Notifications\Attendance\ShiftSwapDecidedNotification;
use App\Notifications\Attendance\ShiftSwapRequestedNotification;
use App\Services\Realtime\RealtimeSignal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SINGLE PIPELINE for shift-swap decisions.
 *
 * Every side effect a swap can produce — state transition (status /
 * counterparty_status / approval_chain), roster application (via RosterService),
 * the recipient NOTIFICATION (request → counterparty; decision → requester),
 * the realtime {@see RealtimeSignal} marker, and working-time compliance — lives
 * here and ONLY here. Both the web ({@see \App\Http\Controllers\HRM\ShiftSwapController})
 * and the mobile ({@see \App\Http\Controllers\Api\V1\AttendanceRequestController})
 * controllers validate/authorize their own request shapes and then delegate to
 * these methods, so the effects fire exactly once regardless of caller. This is
 * the coupling the Leave domain lacked (web approvals that fired no notification
 * and no realtime update).
 *
 * Realtime contract (do NOT change entity/bucket): every swap change signals the
 * team-wide `roster`/`all` bucket the swap-list screens subscribe to; an APPLIED
 * approval additionally signals each affected `roster`/`{Y-m}` month bucket the
 * roster-grid screens subscribe to. actorId is always the ACTING user so their
 * own client self-echo-suppresses.
 */
class ShiftSwapService
{
    public function __construct(
        private readonly RosterService $roster,
        private readonly WorkTimeComplianceService $compliance,
        private readonly RealtimeSignal $signal,
    ) {}

    /**
     * Roster-availability check shared by request time (store) and apply time
     * (approve). Canonical, pickup-aware version. Validates that both parties are
     * scheduled/free on their respective dates. Does NOT restrict based on whether
     * the counterparty is otherwise free — any same-department employee can swap
     * any shift. Returns [field, message] or null when the roster permits the swap.
     */
    public function rosterAvailabilityProblem(string $type, int $requesterId, int $counterpartyId, string $requesterDate, ?string $counterpartyDate): ?array
    {
        // Pickup = the mirror of cover: the requester TAKES a counterparty's shift
        // on counterparty_date (they give nothing up). The counterparty must have a
        // shift to hand over on that date and the requester must be FREE on it.
        if ($type === 'pickup') {
            if (! $counterpartyDate) {
                return ['counterparty_date', 'Select the shift you want to pick up.'];
            }
            if ($this->roster->effectiveShiftId($counterpartyId, $counterpartyDate) === null) {
                return ['counterparty_date', 'The counterparty is not scheduled to work on that date.'];
            }
            if ($this->roster->effectiveShiftId($requesterId, $counterpartyDate) !== null) {
                return ['counterparty_date', 'You are already scheduled to work on that date.'];
            }

            return null;
        }

        if ($this->roster->effectiveShiftId($requesterId, $requesterDate) === null) {
            return ['requester_date', 'You are not scheduled to work on that date.'];
        }

        // Counterparty cannot be busy on requester_date (must be off/free to take/cover it).
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
            if ($this->roster->effectiveShiftId($requesterId, $counterpartyDate) !== null) {
                return ['counterparty_date', 'You are already scheduled to work on that date.'];
            }
        }

        return null;
    }

    /**
     * Create a swap request and own its side effects: persist the row (with the
     * shift-code snapshot captured NOW, before applySwap can rewrite the roster),
     * notify the counterparty whose consent is needed, and wake the swap-list
     * screens (roster/all). Callers validate their own request shape first.
     */
    public function createRequest(
        User $requester,
        User $counterparty,
        string $type,
        ?string $requesterDate,
        ?string $counterpartyDate,
        ?string $reason
    ): ShiftSwapRequest {
        $isPickup = $type === 'pickup';
        $cpDate = $counterpartyDate ? Carbon::parse($counterpartyDate)->toDateString() : null;
        // For a pickup the requester ends up working the picked-up date.
        $requesterDateStr = $isPickup ? $cpDate : Carbon::parse($requesterDate)->toDateString();
        // A cover has no return shift.
        $persistCounterpartyDate = $type === 'cover' ? null : ($counterpartyDate ?: null);

        // Snapshot the give-up / take shift CODES now. The display must never
        // re-derive these from the live roster: once approved, applySwap rewrites
        // the roster and a later lookup would return the POST-swap shift (usually
        // OFF). requester_shift_code is the sentinel — always set (OFF when off).
        $shiftCodeById = Shift::pluck('code', 'id');
        $codeFor = function (?int $userId, ?string $date) use ($shiftCodeById): string {
            if (! $userId || ! $date) {
                return 'OFF';
            }
            $shiftId = $this->roster->effectiveShiftId($userId, $date);

            return $shiftId ? ($shiftCodeById[$shiftId] ?? 'OFF') : 'OFF';
        };

        $requesterShiftCode = $isPickup ? 'OFF' : $codeFor($requester->id, $requesterDateStr);
        $counterpartyShiftCode = ($type === 'cover' || ! $cpDate)
            ? null
            : $codeFor($counterparty->id, $cpDate);

        $swap = DB::transaction(fn () => ShiftSwapRequest::create([
            'type'                    => $type,
            'requester_id'            => $requester->id,
            // A pickup persists requester_date = counterparty_date to satisfy the
            // NOT NULL column and keep downstream display coherent.
            'requester_date'          => $isPickup ? $cpDate : $requesterDate,
            'counterparty_id'         => $counterparty->id,
            'counterparty_date'       => $persistCounterpartyDate,
            'requester_shift_code'    => $requesterShiftCode,
            'counterparty_shift_code' => $counterpartyShiftCode,
            'reason'                  => $reason,
            'status'                  => 'pending',
            'counterparty_status'     => 'pending',
            'approval_chain'          => [[
                'action'    => 'requested',
                'user_id'   => $requester->id,
                'user_name' => $requester->name,
                'timestamp' => now()->toIso8601String(),
            ]],
        ]));

        // Request → notify the counterparty who must consent.
        $this->notify(
            $counterparty,
            new ShiftSwapRequestedNotification($swap->id, $requester->name),
            "ShiftSwapRequestedNotification for swap #{$swap->id}"
        );

        // Living update: a new request has no roster effect yet, so wake the
        // team-wide roster/all channel the swap-list screens subscribe to.
        $this->signal->touch('roster', 'all', $requester->id, 'swap_requested');

        return $swap;
    }

    /**
     * Counterparty consent stage: accept → forwards to manager review; decline →
     * terminal (rejected). State transition + realtime signal live here. Callers
     * enforce that the actor IS the counterparty and the swap is still awaiting them.
     */
    public function respond(ShiftSwapRequest $swap, User $actor, string $decision): ShiftSwapRequest
    {
        $chain = $swap->approval_chain ?? [];

        if ($decision === 'accept') {
            $chain[] = $this->chainEntry('counterparty_accepted', $actor);
            $swap->update([
                'counterparty_status' => 'accepted',
                'approval_chain'      => $chain,
            ]);

            // Acceptance opens the manager approval queue and updates the
            // requester's list — both watch the team-wide roster/all channel.
            $this->signal->touch('roster', 'all', $actor->id, 'swap_accepted');

            return $swap->fresh();
        }

        $chain[] = $this->chainEntry('counterparty_declined', $actor);
        $swap->update([
            'counterparty_status' => 'declined',
            'status'              => 'rejected',
            'approval_chain'      => $chain,
        ]);

        // A decline resolves the swap on the requester's list.
        $this->signal->touch('roster', 'all', $actor->id, 'swap_declined');

        return $swap->fresh();
    }

    /**
     * Manager/admin final approval — the single place that transitions to approved,
     * applies the roster, evaluates working-time compliance (observe vs enforce per
     * config), notifies the requester, and wakes roster/all + every affected month.
     *
     * Returns a caller-agnostic result the controllers map to their own HTTP shape:
     *   ['ok'=>bool, 'code'=>string, 'message'=>string,
     *    'swap'=>?ShiftSwapRequest, 'compliance_violations'=>array]
     * Non-ok codes: already_decided | awaiting_counterparty | roster_changed | compliance_blocked.
     */
    public function approve(ShiftSwapRequest $swap, User $actor): array
    {
        if ($swap->status !== 'pending') {
            return $this->fail('already_decided', 'This swap request has already been decided.');
        }
        if ($swap->counterparty_status === 'pending') {
            return $this->fail('awaiting_counterparty', "Awaiting the counterparty's confirmation before approval.");
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
                return $this->fail('roster_changed', 'The roster changed since this request was made: '.$problem[1]);
            }
        }

        $complianceViolations = [];
        $blockedByCompliance = false;

        try {
            DB::transaction(function () use ($swap, $actor, &$complianceViolations, &$blockedByCompliance) {
                $chain = $swap->approval_chain ?? [];
                $chain[] = $this->chainEntry('manager_approved', $actor);

                $swap->update([
                    'status'         => 'approved',
                    'approved_by'    => $actor->id,
                    'approval_chain' => $chain,
                ]);

                $this->roster->applySwap($swap->fresh());

                // Working-time compliance over each affected party's +/-7 day
                // window against the roster as it now stands (still in-transaction).
                // Enforce mode rolls the whole swap back on a severity=error
                // violation; warnings are returned but never block.
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
                return [
                    'ok'                    => false,
                    'code'                  => 'compliance_blocked',
                    'message'               => 'This swap violates working-time compliance rules and was not applied.',
                    'swap'                  => null,
                    'compliance_violations' => $complianceViolations,
                ];
            }

            throw $exception;
        }

        // Decision → notify the requester.
        $requester = User::find($swap->requester_id);
        if ($requester) {
            $this->notify(
                $requester,
                new ShiftSwapDecidedNotification($swap->id, 'approved'),
                "ShiftSwapDecidedNotification(approved) for swap #{$swap->id}"
            );
        }

        // Living update: an approved swap rewrites the roster, so wake the
        // team-wide roster/all channel AND every affected month bucket.
        $this->signal->touch('roster', 'all', $actor->id, 'swap_approved');
        foreach ($this->affectedMonths($swap) as $month) {
            $this->signal->touch('roster', $month, $actor->id, 'swap_approved');
        }

        return [
            'ok'                    => true,
            'code'                  => 'approved',
            'message'               => 'Swap approved and applied.',
            'swap'                  => $swap->fresh(),
            'compliance_violations' => $complianceViolations,
        ];
    }

    /**
     * Manager/admin rejection — transitions to rejected (no roster change),
     * notifies the requester, wakes roster/all. Same result contract as approve().
     */
    public function reject(ShiftSwapRequest $swap, User $actor): array
    {
        if ($swap->status !== 'pending') {
            return $this->fail('already_decided', 'This swap request has already been decided.');
        }

        $chain = $swap->approval_chain ?? [];
        $chain[] = $this->chainEntry('manager_rejected', $actor);

        $swap->update([
            'status'         => 'rejected',
            'approved_by'    => $actor->id,
            'approval_chain' => $chain,
        ]);

        $requester = User::find($swap->requester_id);
        if ($requester) {
            $this->notify(
                $requester,
                new ShiftSwapDecidedNotification($swap->id, 'rejected'),
                "ShiftSwapDecidedNotification(rejected) for swap #{$swap->id}"
            );
        }

        $this->signal->touch('roster', 'all', $actor->id, 'swap_rejected');

        return [
            'ok'                    => true,
            'code'                  => 'rejected',
            'message'               => 'Swap rejected.',
            'swap'                  => $swap->fresh(),
            'compliance_violations' => [],
        ];
    }

    /**
     * The unique Y-m month buckets a swap's dates fall in (requester + counterparty),
     * so an approval can wake every roster-grid viewer of an affected month.
     *
     * @return array<int, string>
     */
    private function affectedMonths(ShiftSwapRequest $swap): array
    {
        return collect([$swap->requester_date, $swap->counterparty_date])
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->format('Y-m'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Working-time compliance for both parties of an (already applied) swap, over
     * each party's surrounding +/-7 day window around the swap dates.
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

    private function chainEntry(string $action, User $actor): array
    {
        return [
            'action'    => $action,
            'user_id'   => $actor->id,
            'user_name' => $actor->name,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'swap' => null, 'compliance_violations' => []];
    }

    private function notify(User $user, $notification, string $context): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $exception) {
            Log::warning("{$context} failed", ['error' => $exception->getMessage()]);
        }
    }
}
