<?php

namespace Database\Factories;

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Play>
 */
class CbbPlayFactory extends Factory
{
    protected $model = Play::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'possession_team_id' => Team::factory(),
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
