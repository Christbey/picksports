<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MLB\Team>
 */
class MlbTeamFactory extends Factory
{
    protected $model = \App\Models\MLB\Team::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = $this->faker->city();

        return [
            'espn_id' => $this->faker->unique()->numerify('##'),
            'abbreviation' => $this->faker->unique()->lexify('???'),
            'location' => $city,
            'name' => $this->faker->randomElement(['Yankees', 'Red Sox', 'Dodgers', 'Cubs', 'Giants']),
            'nickname' => $this->faker->word(),
            'league' => $this->faker->randomElement(['American', 'National']),
            'division' => $this->faker->randomElement(['East', 'Central', 'West']),
            'color' => $this->faker->hexColor(),
            'logo_url' => $this->faker->imageUrl(),
            'elo_rating' => config('mlb.elo.default_rating'),
        ];
    }
}
