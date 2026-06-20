<?php

namespace Database\Factories\HRM;

use App\Models\HRM\AttendancePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendancePolicyFactory extends Factory
{
    protected $model = AttendancePolicy::class;

    public function definition(): array
    {
        return [
            'name' => 'Policy', 'scope_type' => 'org', 'scope_id' => null, 'priority' => 0,
            'effective_from' => '2026-01-01', 'effective_to' => null,
            'version_group_id' => $this->faker->unique()->numberBetween(1, 100000), 'version' => 1,
            'status' => 'active', 'punch_strictness' => 'warn', 'outside_window_minutes' => 120,
            'grace_tiers' => null, 'rounding' => null, 'rule_overrides' => null,
        ];
    }
}
