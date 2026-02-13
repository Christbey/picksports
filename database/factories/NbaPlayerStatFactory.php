<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NBA\PlayerStat>
 */
class NbaPlayerStatFactory extends Factory
{
    protected $model = \App\Models\NBA\PlayerStat::class;

    public function definition(): array
    {
        $fgMade = $this->faker->numberBetween(0, 15);
        $fgAttempted = $fgMade + $this->faker->numberBetween(0, 10);
        $threeMade = $this->faker->numberBetween(0, 5);
        $threeAttempted = $threeMade + $this->faker->numberBetween(0, 5);
        $ftMade = $this->faker->numberBetween(0, 10);
        $ftAttempted = $ftMade + $this->faker->numberBetween(0, 3);

        $offensiveRebounds = $this->faker->numberBetween(0, 5);
        $defensiveRebounds = $this->faker->numberBetween(0, 10);

        return [
            'minutes_played' => $this->faker->numberBetween(0, 48).':'.str_pad($this->faker->numberBetween(0, 59), 2, '0', STR_PAD_LEFT),
            'field_goals_made' => $fgMade,
            'field_goals_attempted' => $fgAttempted,
            'three_point_made' => $threeMade,
            'three_point_attempted' => $threeAttempted,
            'free_throws_made' => $ftMade,
            'free_throws_attempted' => $ftAttempted,
            'rebounds_offensive' => $offensiveRebounds,
            'rebounds_defensive' => $defensiveRebounds,
            'rebounds_total' => $offensiveRebounds + $defensiveRebounds,
            'assists' => $this->faker->numberBetween(0, 15),
            'turnovers' => $this->faker->numberBetween(0, 8),
            'steals' => $this->faker->numberBetween(0, 5),
            'blocks' => $this->faker->numberBetween(0, 5),
            'fouls' => $this->faker->numberBetween(0, 6),
            'points' => ($fgMade * 2) + ($threeMade * 3) + $ftMade,
        ];
    }
}
