<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WNBA\Team>
 */
class WnbaTeamFactory extends Factory
{
    protected $model = \App\Models\WNBA\Team::class;

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
