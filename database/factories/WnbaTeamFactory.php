<?php

namespace Database\Factories;

use App\Models\WNBA\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class WnbaTeamFactory extends Factory
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
            'location' => $this->faker->city(),
            'name' => $this->faker->word(),
            'conference' => $this->faker->randomElement(['Eastern', 'Western']),
            'division' => $this->faker->randomElement(['North', 'South', 'East', 'West']),
            'color' => $this->faker->hexColor(),
            'logo_url' => $this->faker->imageUrl(),
            'elo_rating' => 1500,
        ];
    }
}
