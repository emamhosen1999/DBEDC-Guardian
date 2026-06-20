<?php

namespace Database\Factories\HRM;

use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceRegularizationFactory extends Factory
{
    protected $model = AttendanceRegularization::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-06-18',
            'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00',
            'reason' => 'forgot to punch out',
            'status' => 'pending',
        ];
    }
}
