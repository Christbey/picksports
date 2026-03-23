<?php

namespace Database\Factories;

use App\Models\CFB\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class CfbTeamFactory extends Factory
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
            'espn_id' => $this->faker->unique()->numerify('###'),
            'abbreviation' => $this->faker->unique()->lexify('???'),
            'school' => $this->faker->city(),
            'mascot' => $this->faker->word(),
            'conference' => $this->faker->randomElement(['SEC', 'BIG10', 'BIG12', 'ACC', 'PAC12']),
            'division' => $this->faker->randomElement(['North', 'South', 'East', 'West']),
            'color' => $this->faker->hexColor(),
            'logo_url' => $this->faker->imageUrl(),
            'elo_rating' => 1500,
        ];
    }
}
