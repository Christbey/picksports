<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MLB\PlayerStat>
 */
class MlbPlayerStatFactory extends Factory
{
    protected $model = \App\Models\MLB\PlayerStat::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stat_type' => 'batting',
            'at_bats' => $this->faker->numberBetween(1, 5),
            'runs' => $this->faker->numberBetween(0, 3),
            'hits' => $this->faker->numberBetween(0, 4),
            'home_runs' => $this->faker->numberBetween(0, 2),
            'rbis' => $this->faker->numberBetween(0, 4),
            'walks' => $this->faker->numberBetween(0, 2),
            'strikeouts' => $this->faker->numberBetween(0, 3),
        ];
    }

    public function pitching(): static
    {
        return $this->state(fn () => [
            'stat_type' => 'pitching',
            'at_bats' => null,
            'runs' => null,
            'hits' => null,
            'home_runs' => null,
            'rbis' => null,
            'walks' => null,
            'strikeouts' => null,
            'innings_pitched' => $this->faker->randomFloat(1, 3.0, 9.0),
            'hits_allowed' => $this->faker->numberBetween(0, 10),
            'runs_allowed' => $this->faker->numberBetween(0, 6),
            'earned_runs' => $this->faker->numberBetween(0, 5),
            'walks_allowed' => $this->faker->numberBetween(0, 4),
            'strikeouts_pitched' => $this->faker->numberBetween(0, 12),
            'home_runs_allowed' => $this->faker->numberBetween(0, 3),
            'pitches_thrown' => $this->faker->numberBetween(60, 120),
        ]);
    }
}
