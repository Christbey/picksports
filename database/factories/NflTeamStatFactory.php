<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NFL\TeamStat>
 */
class NflTeamStatFactory extends Factory
{
    protected $model = \App\Models\NFL\TeamStat::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $passingAttempts = $this->faker->numberBetween(25, 45);
        $passingCompletions = $this->faker->numberBetween(15, $passingAttempts);
        $passingYards = $this->faker->numberBetween(180, 350);
        $passingTouchdowns = $this->faker->numberBetween(0, 4);

        $rushingAttempts = $this->faker->numberBetween(20, 35);
        $rushingYards = $this->faker->numberBetween(80, 200);
        $rushingTouchdowns = $this->faker->numberBetween(0, 3);

        $fumbles = $this->faker->numberBetween(0, 3);
        $fumblesLost = $fumbles > 0 ? $this->faker->numberBetween(0, $fumbles) : 0;

        return [
            'team_type' => $this->faker->randomElement(['home', 'away']),
            'total_yards' => $passingYards + $rushingYards,
            'passing_yards' => $passingYards,
            'passing_completions' => $passingCompletions,
            'passing_attempts' => $passingAttempts,
            'passing_touchdowns' => $passingTouchdowns,
            'interceptions' => $this->faker->numberBetween(0, 3),
            'rushing_yards' => $rushingYards,
            'rushing_attempts' => $rushingAttempts,
            'rushing_touchdowns' => $rushingTouchdowns,
            'fumbles' => $fumbles,
            'fumbles_lost' => $fumblesLost,
            'sacks_allowed' => $this->faker->numberBetween(0, 5),
            'first_downs' => $this->faker->numberBetween(15, 30),
            'third_down_conversions' => $this->faker->numberBetween(3, 10),
            'third_down_attempts' => $this->faker->numberBetween(8, 15),
            'fourth_down_conversions' => $this->faker->numberBetween(0, 2),
            'fourth_down_attempts' => $this->faker->numberBetween(0, 3),
            'red_zone_attempts' => $this->faker->numberBetween(2, 6),
            'red_zone_scores' => $this->faker->numberBetween(1, 5),
            'penalties' => $this->faker->numberBetween(4, 10),
            'penalty_yards' => $this->faker->numberBetween(30, 80),
            'time_of_possession' => $this->faker->time('i:s'),
        ];
    }
}
