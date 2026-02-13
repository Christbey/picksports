<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CBB\Team>
 */
class CbbTeamFactory extends Factory
{
    protected $model = \App\Models\CBB\Team::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'espn_id' => fake()->unique()->numberBetween(1, 999999),
            'abbreviation' => fake()->lexify('???'),
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
