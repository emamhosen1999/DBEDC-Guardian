<?php

namespace Database\Factories\HRM;

use App\Models\HRM\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Morning', 'Evening', 'Night', 'General']),
            'code' => strtoupper($this->faker->unique()->bothify('SH-###')),
            'type' => 'fixed',
            'start_time' => '09:00',
            'end_time' => '17:30',
            'crosses_midnight' => false,
            'break_minutes' => 60,
            'grace_in_minutes' => 15,
            'grace_out_minutes' => 0,
            'full_day_minutes' => 480,
            'half_day_minutes' => 240,
            'min_present_minutes' => 0,
            'color' => '#3b82f6',
            'is_active' => true,
        ];
    }
}
