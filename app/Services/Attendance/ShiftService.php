<?php

namespace App\Services\Attendance;

use App\Models\HRM\ShiftAssignment;
use InvalidArgumentException;

class ShiftService
{
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
}
