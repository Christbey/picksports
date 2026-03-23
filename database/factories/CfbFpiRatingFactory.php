<?php

namespace Database\Factories;

use App\Models\CFB\FpiRating;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FpiRating>
 */
class CfbFpiRatingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => null,
            'season' => $this->faker->numberBetween(2020, 2030),
            'week' => $this->faker->numberBetween(1, 16),
            'fpi' => $this->faker->randomFloat(1, -10, 30),
            'offense' => $this->faker->randomFloat(1, -10, 30),
            'defense' => $this->faker->randomFloat(1, -10, 30),
            'special_teams' => $this->faker->randomFloat(1, -5, 10),
        ];
    }
}
