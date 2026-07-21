<?php

namespace App\Services\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RosterService
{
    public function generateRoster(array $userIds, string $fromDate, string $toDate): int
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();
        $written = 0;

        DB::transaction(function () use ($userIds, $from, $to, &$written) {
            foreach ($userIds as $userId) {
                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    $existing = RosterDay::where('user_id', $userId)->whereDate('date', $d->toDateString())->first();

                    // Never overwrite locked / manual / swap rows.
                    if ($existing && ($existing->locked || in_array($existing->source, ['manual', 'swap'], true))) {
                        continue;
                    }

                    // Derive straight from the assignment/pattern — NOT via
                    // resolveShift(), whose first rule returns any existing
                    // materialized row. Going through resolveShift() here made
                    // regeneration read each day's own stale value back and
                    // rewrite it unchanged, so a changed assignment/pattern
                    // never re-applied to already-generated days.
                    $shift = $this->resolveFromAssignment($userId, $d);

                    RosterDay::updateOrCreate(
                        ['user_id' => $userId, 'date' => $d->toDateString()],
                        ['shift_id' => $shift?->id, 'source' => 'pattern'],
                    );
                    $written++;
                }
            }
        });

        return $written;
    }

    public function resolveShift(int $userId, CarbonInterface $date): ?Shift
    {
        $dateStr = $date->copy()->startOfDay()->toDateString();

        // 1. ANY materialized roster row wins — the roster the grid shows IS the
        // roster people are judged against. (Pattern-source rows used to be
        // display-only, silently re-derived from assignments at scoring time;
        // when a pattern/assignment changed after generation, the calendar and
        // the engine told two different stories.) Manual > swap > pattern when
        // a day carries several rows; scoring stays single-schedule, so the
        // first row under that ordering is the deterministic primary.
        $rosterDay = RosterDay::where('user_id', $userId)
            ->whereDate('date', $dateStr)
            ->orderByRaw("CASE source WHEN 'manual' THEN 0 WHEN 'swap' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->first();
        if ($rosterDay) {
            return $rosterDay->shift_id ? $rosterDay->shift : null; // null = off day
        }

        // No materialized row at all → fall through to live derivation.
        return $this->resolveFromAssignment($userId, $date);
    }

    /**
     * The shift a day would carry purely from the effective-dated assignment /
     * rotation pattern, IGNORING any materialized roster_days row. This is what
     * generation writes: it must re-derive from the current assignment so a
     * changed pattern/anchor/shift re-applies to existing days. resolveShift()
     * layers the materialized-row precedence on top of this for read-time.
     * null = off / no work.
     */
    public function resolveFromAssignment(int $userId, CarbonInterface $date): ?Shift
    {
        // 1. Effective-dated assignment, highest precedence scope first.
        $assignment = $this->resolveAssignment($userId, $date);
        if (! $assignment) {
            return null;
        }

        if ($assignment->shift_id) {
            return $assignment->shift;
        }

        // 2. Rotation pattern → phase by anchor_date.
        $pattern = $assignment->rotationPattern;
        $cycleLength = (int) ($pattern->cycle_length_days ?? 0);
        if (! $pattern || empty($pattern->definition) || $cycleLength < 1 || ! $assignment->anchor_date) {
            return null; // misconfigured rotation (no anchor / zero-length cycle) → treat as off
        }

        $phase = $assignment->anchor_date->startOfDay()->diffInDays($date->copy()->startOfDay()) % $cycleLength;
        $entry = $pattern->definition[$phase] ?? 'off';

        return $entry === 'off' ? null : Shift::find($entry);
    }

    public function applySwap(ShiftSwapRequest $swap): void
    {
        if ($swap->status !== 'approved') {
            return;
        }

        DB::transaction(function () use ($swap) {
            if ($swap->type === 'pickup') {
                // Pickup (mirror of cover): the requester TAKES the counterparty's
                // shift on counterparty_date. Counterparty relinquishes it (goes
                // off); requester gains it. Nothing on the requester's own day changes.
                $cpDate = $swap->counterparty_date->toDateString();
                $counterpartyShiftId = $this->effectiveShiftId($swap->counterparty_id, $cpDate);
                $this->writeSwapDay($swap->counterparty_id, $cpDate, null);
                $this->writeSwapDay($swap->requester_id, $cpDate, $counterpartyShiftId);

                return;
            }

            $reqDate = $swap->requester_date->toDateString();
            $requesterShiftId = $this->effectiveShiftId($swap->requester_id, $reqDate);

            if ($swap->type === 'cover') {
                // Counterparty takes over the requester's shift; requester gets the day off.
                $this->writeSwapDay($swap->requester_id, $reqDate, null);
                $this->writeSwapDay($swap->counterparty_id, $reqDate, $requesterShiftId);

                return;
            }

            // swap: trade two specific rostered shifts (4-cell exchange).

            if (! $swap->counterparty_id) {
                // Genuine open/give-away: no counterparty to roster; requester-off is the full effect.
                $this->writeSwapDay($swap->requester_id, $reqDate, null);

                return;
            }

            if ($swap->type === 'swap' && ! $swap->counterparty_date) {
                // Data error: a named counterparty but no counterparty_date on a swap.
                throw new \LogicException("Swap #{$swap->id} is type=swap with a counterparty but no counterparty_date");
            }

            $cpDate = $swap->counterparty_date->toDateString();
            $counterpartyShiftId = $this->effectiveShiftId($swap->counterparty_id, $cpDate);

            if ($reqDate === $cpDate) {
                // Same-day trade (e.g. MCE <-> MCN on same date): swap directly.
                $this->writeSwapDay($swap->requester_id, $reqDate, $counterpartyShiftId);
                $this->writeSwapDay($swap->counterparty_id, $reqDate, $requesterShiftId);
            } else {
                // Different-day trade: clear old shifts first, then assign new ones.
                $this->writeSwapDay($swap->requester_id, $reqDate, null);
                $this->writeSwapDay($swap->counterparty_id, $reqDate, $requesterShiftId);
                $this->writeSwapDay($swap->counterparty_id, $cpDate, null);
                $this->writeSwapDay($swap->requester_id, $cpDate, $counterpartyShiftId);
            }
        });
    }

    /**
     * The effective shift_id for a user on a date from the MATERIALIZED roster
     * (roster_days of ANY source) first, else the resolved assignment/pattern.
     * Unlike resolveShift(), this honors source=pattern rows — it is the truth
     * the employee sees and what swaps/covers operate on. null = off / no work.
     */
    public function effectiveShiftId(int $userId, string $date): ?int
    {
        $rosterDay = RosterDay::where('user_id', $userId)->whereDate('date', $date)->first();
        if ($rosterDay) {
            return $rosterDay->shift_id;
        }

        return $this->resolveShift($userId, Carbon::parse($date))?->id;
    }

    private function writeSwapDay(int $userId, string $date, ?int $shiftId): void
    {
        RosterDay::updateOrCreate(
            ['user_id' => $userId, 'date' => $date],
            ['shift_id' => $shiftId, 'source' => 'swap', 'locked' => true],
        );
    }

    public function resolveAssignment(int $userId, CarbonInterface $date): ?ShiftAssignment
    {
        $user = User::find($userId);
        $dateStr = $date->copy()->startOfDay()->toDateString();

        // Precedence: user > designation > department > org.
        $scopes = [
            ['type' => 'user', 'id' => $userId],
            ['type' => 'designation', 'id' => $user?->designation_id],
            ['type' => 'department', 'id' => $user?->department_id],
            ['type' => 'org', 'id' => null],
        ];

        foreach ($scopes as $scope) {
            if ($scope['id'] === null && $scope['type'] !== 'org') {
                continue;
            }

            $query = ShiftAssignment::where('scope_type', $scope['type'])
                ->whereDate('effective_from', '<=', $dateStr)
                ->where(function ($q) use ($dateStr) {
                    $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dateStr);
                })
                ->orderByDesc('priority')
                ->orderByDesc('effective_from');

            $scope['id'] === null ? $query->whereNull('scope_id') : $query->where('scope_id', $scope['id']);

            $assignment = $query->first();
            if ($assignment) {
                return $assignment;
            }
        }

        return null;
    }
}
