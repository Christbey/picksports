<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NBA\Game>
 */
class NbaGameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = \App\Models\NBA\Game::class;

    public function definition(): array
    {
        return [
            'espn_event_id' => $this->faker->unique()->numerify('#########'),
            'espn_uid' => $this->faker->unique()->numerify('s:40~l:46~e:#########'),
            'season' => $this->faker->numberBetween(2020, 2025),
            'week' => $this->faker->numberBetween(1, 26),
            'season_type' => $this->faker->numberBetween(1, 3),
            'game_date' => $this->faker->date(),
            'game_time' => $this->faker->time(),
            'name' => $this->faker->words(3, true),
            'short_name' => $this->faker->words(2, true),
            'venue_name' => $this->faker->company().' Arena',
            'venue_city' => $this->faker->city(),
            'venue_state' => $this->faker->stateAbbr(),
            'status' => $this->faker->randomElement(['scheduled', 'in_progress', 'completed']),
            'period' => $this->faker->numberBetween(1, 4),
            'game_clock' => $this->faker->time('i:s'),
            'home_score' => $this->faker->numberBetween(0, 120),
            'away_score' => $this->faker->numberBetween(0, 120),
        ];
    }
}
