<?php

namespace Database\Factories;

use App\Models\LivePredictionSnapshot;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LivePredictionSnapshot> */
class LivePredictionSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sport' => 'nba', 'game_table' => 'nba_games',
            'game_id' => Game::factory()->state(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]),
            'baseline' => null, 'state' => [], 'projection' => null,
            'status' => 'missing_pregame', 'calculator' => 'fixture',
            'state_hash' => hash('sha256', fake()->unique()->uuid()), 'observed_at' => now(),
        ];
    }
}
