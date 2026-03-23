<?php

namespace Database\Factories;

use App\Models\WCBB\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class WcbbTeamFactory extends Factory
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
            'espn_id' => $this->faker->unique()->numberBetween(1, 999999),
            'abbreviation' => $this->faker->lexify('???'),
            'school' => $this->faker->words(2, true),
            'mascot' => $this->faker->word(),
            'conference' => $this->faker->word(),
            'division' => $this->faker->optional()->word(),
            'color' => $this->faker->hexColor(),
            'logo_url' => $this->faker->optional()->imageUrl(),
        ];
    }
}
