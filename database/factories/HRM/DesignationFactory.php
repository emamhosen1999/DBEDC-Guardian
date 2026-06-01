<?php

namespace Database\Factories\HRM;

use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;

class DesignationFactory extends Factory
{
    protected $model = Designation::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->unique()->jobTitle(),
            'department_id' => Department::factory(),
            'hierarchy_level' => $this->faker->numberBetween(1, 10),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
