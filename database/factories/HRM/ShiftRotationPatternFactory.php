<?php

namespace Database\Factories\HRM;

use App\Models\HRM\ShiftRotationPattern;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftRotationPatternFactory extends Factory
{
    protected $model = ShiftRotationPattern::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'code' => strtoupper($this->faker->unique()->bothify('ROT-###')),
            'cycle_length_days' => 2,
            'definition' => ['off', 'off'],
            'is_active' => true,
        ];
    }
}
