<?php

namespace App\Services\Attendance;

use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class ShiftService
{
    public function __construct(
        private readonly RosterService $rosterService,
        private readonly WorkTimeComplianceService $compliance,
    ) {}

    public function createAssignment(array $data): ShiftAssignment
    {
        $hasShift = ! empty($data['shift_id']);
        $hasPattern = ! empty($data['rotation_pattern_id']);

        if ($hasShift === $hasPattern) {
            throw new InvalidArgumentException('Exactly one of shift_id or rotation_pattern_id must be set.');
        }

        if ($this->assignmentsOverlap(
            $data['scope_type'],
            $data['scope_id'] ?? null,
            $data['effective_from'],
            $data['effective_to'] ?? null,
        )) {
            throw new InvalidArgumentException('Assignment effective dates overlap an existing assignment for this scope.');
        }

        return ShiftAssignment::create($data);
    }

    /**
     * Update an existing assignment (e.g. end-date it to supersede from a future date).
     * Overlap is checked against every other assignment for the same scope, ignoring itself.
     */
    public function updateAssignment(ShiftAssignment $assignment, array $data): ShiftAssignment
    {
        $finalShift = array_key_exists('shift_id', $data) ? $data['shift_id'] : $assignment->shift_id;
        $finalPattern = array_key_exists('rotation_pattern_id', $data) ? $data['rotation_pattern_id'] : $assignment->rotation_pattern_id;

        if (empty($finalShift) === empty($finalPattern)) {
            throw new InvalidArgumentException('Exactly one of shift_id or rotation_pattern_id must be set.');
        }

        $scopeType = $data['scope_type'] ?? $assignment->scope_type;
        $scopeId = array_key_exists('scope_id', $data) ? $data['scope_id'] : $assignment->scope_id;
        $from = $data['effective_from'] ?? $assignment->effective_from->toDateString();
        $to = array_key_exists('effective_to', $data)
            ? $data['effective_to']
            : $assignment->effective_to?->toDateString();

        if ($to !== null && $to < $from) {
            throw new InvalidArgumentException('Effective to must be on or after effective from.');
        }

        if ($this->assignmentsOverlap($scopeType, $scopeId, $from, $to, $assignment->id)) {
            throw new InvalidArgumentException('Assignment effective dates overlap an existing assignment for this scope.');
        }

        $assignment->update($data);

        return $assignment->fresh();
    }

    public function assignmentsOverlap(string $scopeType, ?int $scopeId, string $from, ?string $to, ?int $ignoreId = null): bool
    {
        $query = ShiftAssignment::where('scope_type', $scopeType);
        $scopeId === null ? $query->whereNull('scope_id') : $query->where('scope_id', $scopeId);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        // Overlap: existing.from <= new.to (or new open) AND existing.to (or open) >= new.from
        return $query->get()->contains(function (ShiftAssignment $a) use ($from, $to) {
            $aFrom = $a->effective_from->toDateString();
            $aTo = $a->effective_to?->toDateString();

            $newToOk = $aTo === null || $aTo >= $from;       // existing ends after new starts
            $existingToOk = $to === null || $aFrom <= $to;   // existing starts before new ends

            return $newToOk && $existingToOk;
        });
    }

    /**
     * Working-time compliance for every user affected by an assignment
     * (already persisted), over the first 35 days of its effective window
     * (capped by effective_to if it ends sooner). The day-by-day shift is
     * derived via RosterService::resolveShift() — the same resolution the
     * roster grid uses — rather than roster_days, since generation may not
     * have materialized this assignment yet.
     *
     * @return array<int, array<int, array{date: string, rule: string, message: string, severity: string, details: array}>> keyed by user_id
     */
    public function complianceForAssignment(ShiftAssignment $assignment): array
    {
        $userIds = $this->affectedUserIds($assignment);
        if (empty($userIds)) {
            return [];
        }

        $from = $this->toCarbonDate($assignment->effective_from);
        $to = $from->copy()->addDays(34);

        if ($assignment->effective_to) {
            $effectiveTo = $this->toCarbonDate($assignment->effective_to);
            if ($effectiveTo->lessThan($to)) {
                $to = $effectiveTo;
            }
        }

        $violations = [];
        foreach ($userIds as $userId) {
            $days = [];
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $days[] = ['date' => $d->toDateString(), 'shift' => $this->rosterService->resolveShift($userId, $d)];
            }

            $userViolations = $this->compliance->evaluateSequence($days);
            if ($userViolations) {
                $violations[$userId] = $userViolations;
            }
        }

        return $violations;
    }

    private function toCarbonDate(CarbonInterface|string $value): Carbon
    {
        return ($value instanceof CarbonInterface ? Carbon::parse($value) : Carbon::parse($value))->startOfDay();
    }

    /**
     * Resolve the concrete user ids an assignment scope applies to.
     *
     * @return array<int, int>
     */
    private function affectedUserIds(ShiftAssignment $assignment): array
    {
        return match ($assignment->scope_type) {
            'user' => $assignment->scope_id ? [(int) $assignment->scope_id] : [],
            'department' => User::where('department_id', $assignment->scope_id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'designation' => User::where('designation_id', $assignment->scope_id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'org' => User::pluck('id')->map(fn ($id) => (int) $id)->all(),
            default => [],
        };
    }
}
