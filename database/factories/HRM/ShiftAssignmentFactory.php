<?php

namespace Database\Factories\HRM;

use App\Models\HRM\ShiftAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftAssignmentFactory extends Factory
{
    protected $model = ShiftAssignment::class;

    public function definition(): array
    {
        return [
            'scope_type' => 'user',
            'scope_id' => 1,
            'shift_id' => null,
            'rotation_pattern_id' => null,
            'anchor_date' => '2026-06-01',
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'priority' => 0,
            'assigned_by' => null,
        ];
    }
}
