<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MLB\Game>
 */
class MlbGameFactory extends Factory
{
    protected $model = \App\Models\MLB\Game::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'espn_event_id' => $this->faker->unique()->numerify('#########'),
            'espn_uid' => $this->faker->unique()->numerify('s:1~l:10~e:#########'),
            'season' => $this->faker->numberBetween(2020, 2025),
            'week' => $this->faker->numberBetween(1, 26),
            'season_type' => config('mlb.season.types.regular'),
            'game_date' => $this->faker->date(),
            'game_time' => $this->faker->time(),
            'name' => $this->faker->words(3, true),
            'short_name' => $this->faker->words(2, true),
            'venue_name' => $this->faker->company().' Stadium',
            'venue_city' => $this->faker->city(),
            'venue_state' => $this->faker->stateAbbr(),
            'status' => $this->faker->randomElement(['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_FINAL']),
            'inning' => $this->faker->numberBetween(1, 9),
            'inning_half' => $this->faker->randomElement(['top', 'bottom']),
            'balls' => $this->faker->numberBetween(0, 3),
            'strikes' => $this->faker->numberBetween(0, 2),
            'outs' => $this->faker->numberBetween(0, 2),
            'home_score' => $this->faker->numberBetween(0, 12),
            'away_score' => $this->faker->numberBetween(0, 12),
        ];
    }

    public function regularSeason(): static
    {
        return $this->state(fn () => [
            'season_type' => config('mlb.season.types.regular'),
        ]);
    }

    public function postseason(): static
    {
        return $this->state(fn () => [
            'season_type' => config('mlb.season.types.postseason'),
        ]);
    }
}
