<?php

namespace Database\Factories;

use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RfiObjection>
 */
class RfiObjectionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = RfiObjection::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'category' => fake()->randomElement([
                RfiObjection::CATEGORY_DESIGN_CONFLICT,
                RfiObjection::CATEGORY_SITE_MISMATCH,
                RfiObjection::CATEGORY_MATERIAL_CHANGE,
                RfiObjection::CATEGORY_SAFETY_CONCERN,
                RfiObjection::CATEGORY_SPECIFICATION_ERROR,
                RfiObjection::CATEGORY_OTHER,
            ]),
            'description' => fake()->paragraph(),
            'reason' => fake()->sentence(),
            'status' => RfiObjection::STATUS_DRAFT,
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }

    /**
     * Create an objection with submitted status.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RfiObjection::STATUS_SUBMITTED,
        ]);
    }

    /**
     * Create an objection with resolved status.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RfiObjection::STATUS_RESOLVED,
            'resolution_notes' => fake()->paragraph(),
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
        ]);
    }

    /**
     * Create an objection with a specific category.
     */
    public function category(string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
        ]);
    }
}