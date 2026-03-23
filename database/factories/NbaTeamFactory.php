<?php

namespace Database\Factories;

use App\Models\NBA\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class NbaTeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Team::class;

    public function definition(): array
    {
        $city = $this->faker->city();

        return [
            'espn_id' => $this->faker->unique()->numerify('##'),
            'abbreviation' => $this->faker->unique()->lexify('???'),
            'location' => $city,
            'name' => $this->faker->randomElement(['Lakers', 'Celtics', 'Warriors', 'Heat', 'Bulls']),
            'conference' => $this->faker->randomElement(['Eastern', 'Western']),
            'division' => $this->faker->randomElement(['Atlantic', 'Central', 'Southeast', 'Northwest', 'Pacific', 'Southwest']),
            'color' => $this->faker->hexColor(),
            'logo_url' => $this->faker->imageUrl(),
            'elo_rating' => 1500,
        ];
    }
}
