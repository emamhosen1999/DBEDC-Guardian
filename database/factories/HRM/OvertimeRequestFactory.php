<?php

namespace Database\Factories\HRM;

use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OvertimeRequestFactory extends Factory
{
    protected $model = OvertimeRequest::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-06-18',
            'requested_minutes' => 120,
            'reason' => 'ot',
            'status' => 'pending',
        ];
    }
}
