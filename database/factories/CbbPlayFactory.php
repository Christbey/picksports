<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CBB\Play>
 */
class CbbPlayFactory extends Factory
{
    protected $model = \App\Models\CBB\Play::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => \App\Models\CBB\Game::factory(),
            'possession_team_id' => \App\Models\CBB\Team::factory(),
            'espn_play_id' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'sequence_number' => $this->faker->unique()->numberBetween(1, 500),
            'period' => 1,
            'clock' => '20:00',
            'play_type' => 'jumpball',
            'play_text' => 'Start of half',
            'score_value' => 0,
            'shooting_play' => false,
            'made_shot' => false,
            'assist' => false,
            'is_turnover' => false,
            'is_foul' => false,
            'home_score' => 0,
            'away_score' => 0,
            'is_epa_eligible' => false,
            'expected_points_before' => null,
            'expected_points_after' => null,
            'true_epa' => null,
        ];
    }
}
