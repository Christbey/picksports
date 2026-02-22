<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CBB\TeamStat>
 */
class CbbTeamStatFactory extends Factory
{
    protected $model = \App\Models\CBB\TeamStat::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fgMade = $this->faker->numberBetween(20, 35);
        $fgAttempted = $fgMade + $this->faker->numberBetween(15, 30);
        $threeMade = $this->faker->numberBetween(3, 10);
        $threeAttempted = $threeMade + $this->faker->numberBetween(5, 15);
        $ftMade = $this->faker->numberBetween(8, 20);
        $ftAttempted = $ftMade + $this->faker->numberBetween(2, 8);

        $offensiveRebounds = $this->faker->numberBetween(5, 15);
        $defensiveRebounds = $this->faker->numberBetween(15, 30);

        return [
            'team_type' => $this->faker->randomElement(['home', 'away']),
            'points' => ($fgMade * 2) + $threeMade + $ftMade,
            'field_goals_made' => $fgMade,
            'field_goals_attempted' => $fgAttempted,
            'three_point_made' => $threeMade,
            'three_point_attempted' => $threeAttempted,
            'free_throws_made' => $ftMade,
            'free_throws_attempted' => $ftAttempted,
            'rebounds' => $offensiveRebounds + $defensiveRebounds,
            'offensive_rebounds' => $offensiveRebounds,
            'defensive_rebounds' => $defensiveRebounds,
            'assists' => $this->faker->numberBetween(10, 25),
            'steals' => $this->faker->numberBetween(3, 12),
            'blocks' => $this->faker->numberBetween(2, 8),
            'turnovers' => $this->faker->numberBetween(8, 18),
            'fouls' => $this->faker->numberBetween(12, 22),
            'possessions' => $this->faker->randomFloat(1, 60.0, 80.0),
            'fast_break_points' => $this->faker->numberBetween(0, 15),
            'points_in_paint' => $this->faker->numberBetween(20, 40),
            'second_chance_points' => $this->faker->numberBetween(5, 15),
            'bench_points' => $this->faker->numberBetween(10, 30),
            'biggest_lead' => $this->faker->numberBetween(0, 20),
            'times_tied' => $this->faker->numberBetween(0, 8),
            'lead_changes' => $this->faker->numberBetween(0, 12),
        ];
    }
}
