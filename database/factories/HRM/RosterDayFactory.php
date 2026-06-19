<?php

namespace Database\Factories\HRM;

use App\Models\HRM\RosterDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RosterDayFactory extends Factory
{
    protected $model = RosterDay::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-06-19',
            'shift_id' => null,
            'source' => 'pattern',
            'locked' => false,
        ];
    }
}
