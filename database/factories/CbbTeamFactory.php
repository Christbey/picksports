<?php

namespace Database\Factories;

use App\Models\CBB\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class CbbTeamFactory extends Factory
{
    protected $model = Team::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'espn_id' => fake()->unique()->numberBetween(1, 999999),
            'abbreviation' => strtoupper(fake()->bothify('???####')),
            'school' => fake()->words(2, true),
            'mascot' => fake()->word(),
            'conference' => fake()->word(),
            'division' => fake()->optional()->word(),
            'color' => fake()->hexColor(),
            'logo_url' => fake()->optional()->imageUrl(),
            'elo_rating' => fake()->numberBetween(1000, 2000),
        ];
    }
}
