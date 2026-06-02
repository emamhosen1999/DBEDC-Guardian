<?php

namespace Database\Factories\HRM;

use App\Models\HRM\LeaveSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeaveSettingFactory extends Factory
{
    protected $model = LeaveSetting::class;

    public function definition(): array
    {
        return [
            'type' => $this->faker->unique()->randomElement(['Annual', 'Sick', 'Casual', 'Maternity', 'Paternity', 'Unpaid']),
            'days' => $this->faker->numberBetween(5, 30),
            'eligibility' => 'All employees',
            'carry_forward' => $this->faker->boolean(),
            'earned_leave' => $this->faker->boolean(),
            'requires_approval' => true,
            'auto_approve' => false,
            'special_conditions' => null,
        ];
    }
}
