<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NBA\TeamStat>
 */
class NbaTeamStatFactory extends Factory
{
    protected $model = \App\Models\NBA\TeamStat::class;

    public function definition(): array
    {
        $fgMade = $this->faker->numberBetween(35, 50);
        $fgAttempted = $fgMade + $this->faker->numberBetween(30, 50);
        $threeMade = $this->faker->numberBetween(8, 18);
        $threeAttempted = $threeMade + $this->faker->numberBetween(10, 20);
        $ftMade = $this->faker->numberBetween(10, 25);
        $ftAttempted = $ftMade + $this->faker->numberBetween(2, 10);

        $offensiveRebounds = $this->faker->numberBetween(8, 15);
        $defensiveRebounds = $this->faker->numberBetween(25, 40);

        return [
            'team_type' => $this->faker->randomElement(['home', 'away']),
            'field_goals_made' => $fgMade,
            'field_goals_attempted' => $fgAttempted,
            'three_point_made' => $threeMade,
            'three_point_attempted' => $threeAttempted,
            'free_throws_made' => $ftMade,
            'free_throws_attempted' => $ftAttempted,
            'rebounds' => $offensiveRebounds + $defensiveRebounds,
            'offensive_rebounds' => $offensiveRebounds,
            'defensive_rebounds' => $defensiveRebounds,
            'assists' => $this->faker->numberBetween(18, 32),
            'turnovers' => $this->faker->numberBetween(10, 20),
            'steals' => $this->faker->numberBetween(5, 12),
            'blocks' => $this->faker->numberBetween(2, 8),
            'fouls' => $this->faker->numberBetween(15, 25),
            'points' => ($fgMade * 2) + $threeMade + $ftMade,
            'possessions' => $this->faker->randomFloat(1, 95, 105),
            'fast_break_points' => $this->faker->numberBetween(5, 20),
            'points_in_paint' => $this->faker->numberBetween(35, 60),
            'second_chance_points' => $this->faker->numberBetween(8, 18),
            'bench_points' => $this->faker->numberBetween(20, 45),
            'biggest_lead' => $this->faker->numberBetween(0, 25),
            'times_tied' => $this->faker->numberBetween(0, 8),
            'lead_changes' => $this->faker->numberBetween(0, 12),
        ];
    }
}
