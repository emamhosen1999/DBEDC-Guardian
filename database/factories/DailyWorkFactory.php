<?php

namespace Database\Factories;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyWork>
 */
class DailyWorkFactory extends Factory
{
    protected $model = DailyWork::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['Embankment', 'Structure', 'Pavement'];
        $type = $this->faker->randomElement($types);

        return [
            'date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'number' => $this->generateRfiNumber($type),
            'status' => DailyWork::STATUS_NEW,
            'inspection_result' => null,
            'type' => $type,
            'description' => $this->faker->sentence(10),
            'location' => $this->generateChainage(),
            'side' => $this->faker->randomElement(['TR-L', 'TR-R', 'SR-L', 'SR-R']),
            'qty_layer' => $type === 'Embankment' ? $this->faker->numberBetween(1, 20).'th' : null,
            'planned_time' => $this->faker->time('H:i'),
            'incharge' => User::factory(),
            'assigned' => User::factory(),
            'completion_time' => null,
            'inspection_details' => null,
            'resubmission_count' => 0,
            'resubmission_date' => null,
            'rfi_submission_date' => null,
        ];
    }

    /**
     * Generate a realistic RFI number based on type.
     */
    private function generateRfiNumber(string $type): string
    {
        $prefix = match ($type) {
            'Embankment' => 'E',
            'Structure' => 'S',
            'Pavement' => 'P',
            default => 'D',
        };

        $date = now()->format('Ymd');
        $sequence = $this->faker->unique()->numberBetween(10000, 99999);

        return "{$prefix}{$date}-{$sequence}";
    }

    /**
     * Generate a realistic chainage location.
     */
    private function generateChainage(): string
    {
        $startK = $this->faker->numberBetween(0, 45);
        $startPlus = $this->faker->numberBetween(0, 999);
        $endK = $startK + $this->faker->numberBetween(0, 2);
        $endPlus = $this->faker->numberBetween(0, 999);

        return sprintf('K%02d+%03d-K%02d+%03d', $startK, $startPlus, $endK, $endPlus);
    }

    /**
     * Indicate that the daily work is new.
     */
    public function newStatus(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyWork::STATUS_NEW,
            'completion_time' => null,
            'inspection_result' => null,
        ]);
    }

    /**
     * Indicate that the daily work is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyWork::STATUS_IN_PROGRESS,
            'completion_time' => null,
            'inspection_result' => null,
        ]);
    }

    /**
     * Indicate that the daily work is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyWork::STATUS_COMPLETED,
            'completion_time' => now(),
            'inspection_result' => DailyWork::INSPECTION_PASS,
            'rfi_submission_date' => now()->subDays(rand(1, 5)),
        ]);
    }

    /**
     * Indicate that the daily work has been rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyWork::STATUS_REJECTED,
            'inspection_result' => DailyWork::INSPECTION_FAIL,
        ]);
    }

    /**
     * Indicate that this is a resubmission.
     */
    public function resubmission(int $count = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyWork::STATUS_RESUBMISSION,
            'resubmission_count' => $count,
            'resubmission_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Indicate that the daily work is an emergency.
     */
    public function emergency(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyWork::STATUS_EMERGENCY,
        ]);
    }

    /**
     * Indicate that the daily work is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DailyWork::STATUS_PENDING,
        ]);
    }

    /**
     * Set the daily work type to Embankment.
     */
    public function embankment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'Embankment',
            'number' => $this->generateRfiNumber('Embankment'),
            'qty_layer' => $this->faker->numberBetween(1, 20).'th',
        ]);
    }

    /**
     * Set the daily work type to Structure.
     */
    public function structure(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'Structure',
            'number' => $this->generateRfiNumber('Structure'),
            'qty_layer' => null,
        ]);
    }

    /**
     * Set the daily work type to Pavement.
     */
    public function pavement(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'Pavement',
            'number' => $this->generateRfiNumber('Pavement'),
            'qty_layer' => null,
        ]);
    }

    /**
     * Set a specific incharge user.
     */
    public function forIncharge(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'incharge' => $user->id,
        ]);
    }

    /**
     * Set a specific assigned user.
     */
    public function forAssigned(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned' => $user->id,
        ]);
    }

    /**
     * Set both incharge and assigned users.
     */
    public function forUsers(User $incharge, User $assigned): static
    {
        return $this->state(fn (array $attributes) => [
            'incharge' => $incharge->id,
            'assigned' => $assigned->id,
        ]);
    }

    /**
     * Set a specific date.
     */
    public function forDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    /**
     * Set a specific location/chainage.
     */
    public function atLocation(string $location): static
    {
        return $this->state(fn (array $attributes) => [
            'location' => $location,
        ]);
    }

    /**
     * Set inspection details.
     */
    public function withInspectionDetails(string $details): static
    {
        return $this->state(fn (array $attributes) => [
            'inspection_details' => $details,
        ]);
    }

    /**
     * Set inspection result to pass.
     */
    public function passed(): static
    {
        return $this->state(fn (array $attributes) => [
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ]);
    }

    /**
     * Set inspection result to fail.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'inspection_result' => DailyWork::INSPECTION_FAIL,
        ]);
    }
}
