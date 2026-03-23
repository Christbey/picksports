<?php

namespace Database\Factories;

use App\Models\WCBB\Team;
use App\Models\WCBB\TournamentForecast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TournamentForecast>
 */
class WcbbTournamentForecastFactory extends Factory
{
    protected $model = TournamentForecast::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'season' => fake()->numberBetween(2024, 2030),
            'selection_score' => fake()->randomFloat(4, -3, 3),
            'projected_seed' => fake()->optional()->numberBetween(1, 16),
            'auto_bid' => fake()->boolean(30),
            'auto_bid_probability' => fake()->randomFloat(5, 0, 1),
            'at_large_probability' => fake()->randomFloat(5, 0, 1),
            'first_four_probability' => fake()->randomFloat(5, 0, 0.4),
            'first_four_auto_probability' => fake()->randomFloat(5, 0, 0.3),
            'first_four_at_large_probability' => fake()->randomFloat(5, 0, 0.3),
            'bid_thief_probability' => fake()->randomFloat(5, 0, 0.4),
            'tournament_make_probability' => fake()->randomFloat(5, 0, 1),
            'champion_probability' => fake()->randomFloat(5, 0, 0.3),
            'final_four_probability' => fake()->randomFloat(5, 0, 0.5),
            'title_game_probability' => fake()->randomFloat(5, 0, 0.35),
            'simulated_field_appearances' => fake()->numberBetween(0, 5000),
            'simulated_titles' => fake()->numberBetween(0, 1200),
            'simulation_runs' => 5000,
        ];
    }
}
