<?php

namespace Database\Factories\HRM;

use App\Models\HRM\Attendance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        $date = $this->faker->dateTimeBetween('-20 days', 'now');
        $in = (clone $date)->setTime(9, 0);
        $out = (clone $date)->setTime(17, 30);

        return [
            'user_id' => User::factory(),
            'date' => $in->format('Y-m-d'),
            'punchin' => $in,
            'punchout' => $out,
            'punchin_location' => null,
            'punchout_location' => null,
            'symbol' => '√',
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['punchout' => null]);
    }

    public function night(): static
    {
        return $this->state(function (array $attrs) {
            $base = \Carbon\Carbon::parse($attrs['date']);

            return [
                'punchin' => $base->copy()->setTime(20, 0),
                'punchout' => $base->copy()->addDay()->setTime(4, 0),
            ];
        });
    }
}
