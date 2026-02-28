<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WNBA\Game>
 */
class WnbaGameFactory extends Factory
{
    protected $model = \App\Models\WNBA\Game::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'espn_event_id' => $this->faker->unique()->numerify('#########'),
            'espn_uid' => $this->faker->unique()->numerify('s:20~l:28~e:#########'),
            'season' => $this->faker->numberBetween(2020, 2027),
            'week' => $this->faker->numberBetween(1, 40),
            'season_type' => (string) $this->faker->numberBetween(1, 3),
            'game_date' => $this->faker->date(),
            'game_time' => $this->faker->time(),
            'name' => $this->faker->words(3, true),
            'short_name' => $this->faker->words(2, true),
            'venue_name' => $this->faker->company().' Arena',
            'venue_city' => $this->faker->city(),
            'venue_state' => $this->faker->stateAbbr(),
            'status' => $this->faker->randomElement(['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_FINAL']),
            'period' => $this->faker->numberBetween(1, 4),
            'game_clock' => $this->faker->time('i:s'),
            'home_score' => $this->faker->numberBetween(0, 120),
            'away_score' => $this->faker->numberBetween(0, 120),
        ];
    }
}
