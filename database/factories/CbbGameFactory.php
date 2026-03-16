<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CBB\Game>
 */
class CbbGameFactory extends Factory
{
    protected $model = \App\Models\CBB\Game::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'espn_event_id' => (string) $this->faker->unique()->numberBetween(100000000, 999999999),
            'espn_uid' => sprintf('s:40~l:59~e:%s', (string) $this->faker->unique()->numberBetween(100000000, 999999999)),
            'season' => $this->faker->numberBetween(2020, 2027),
            'week' => $this->faker->numberBetween(1, 30),
            'season_type' => (string) $this->faker->numberBetween(1, 3),
            'game_date' => $this->faker->date(),
            'game_time' => $this->faker->time(),
            'name' => $this->faker->words(3, true),
            'short_name' => $this->faker->words(2, true),
            'home_team_display_name' => $this->faker->company().' Home',
            'away_team_display_name' => $this->faker->company().' Away',
            'home_team_abbreviation' => strtoupper($this->faker->lexify('???')),
            'away_team_abbreviation' => strtoupper($this->faker->lexify('???')),
            'is_ncaa_tournament' => false,
            'tournament_id' => null,
            'tournament_note' => null,
            'tournament_round' => null,
            'tournament_region' => null,
            'home_seed' => null,
            'away_seed' => null,
            'play_in_target_seed' => null,
            'venue_name' => $this->faker->company().' Arena',
            'venue_city' => $this->faker->city(),
            'venue_state' => $this->faker->stateAbbr(),
            'status' => $this->faker->randomElement(['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_FINAL']),
            'period' => $this->faker->numberBetween(1, 4),
            'game_clock' => $this->faker->time('i:s'),
            'home_score' => $this->faker->numberBetween(0, 100),
            'away_score' => $this->faker->numberBetween(0, 100),
        ];
    }
}
