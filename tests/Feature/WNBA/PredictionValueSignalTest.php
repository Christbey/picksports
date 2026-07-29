<?php

use App\Models\User;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\Team;
use Laravel\Sanctum\Sanctum;

it('exposes a compact wnba value signal without leaking raw betting value', function () {
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);

    $homeTeam = Team::factory()->create([
        'location' => 'Las Vegas',
        'name' => 'Aces',
        'abbreviation' => 'LV',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Liberty',
        'abbreviation' => 'NY',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 8,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-18',
        'game_time' => '18:05:00',
        'odds_updated_at' => now(),
        'odds_data' => [
            'bookmakers' => [[
                'markets' => [
                    [
                        'key' => 'spreads',
                        'outcomes' => [
                            ['name' => 'New York Liberty', 'point' => 3.5, 'price' => -108],
                            ['name' => 'Las Vegas Aces', 'point' => -3.5, 'price' => -112],
                        ],
                    ],
                    [
                        'key' => 'totals',
                        'outcomes' => [
                            ['name' => 'Over', 'point' => 161.5, 'price' => -110],
                            ['name' => 'Under', 'point' => 161.5, 'price' => -110],
                        ],
                    ],
                ],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 6.2,
        'predicted_total' => 166.3,
        'win_probability' => 0.64,
        'confidence_score' => 82.0,
        'model_metadata' => [
            'market_blend' => ['applied' => true],
            'season_context' => ['sample_games' => 16],
        ],
    ]);

    $this->getJson('/api/v2/sports/wnba/predictions?season=2026&from_date=2026-06-18&to_date=2026-06-18')
        ->assertOk()
        ->assertJsonPath('data.0.confidence_context.label', 'High')
        ->assertJsonPath('data.0.confidence_context.sample_games', 16)
        ->assertJsonPath('data.0.market_summary.has_odds', true)
        ->assertJsonPath('data.0.market_summary.markets.0', 'spread')
        ->assertJsonPath('data.0.market_summary.markets.1', 'total')
        ->assertJsonPath('data.0.value_signal.has_playable_value', true)
        ->assertJsonPath('data.0.value_signal.best.type', 'total')
        ->assertJsonPath('data.0.value_signal.best.label', 'Bet Over')
        ->assertJsonMissingPath('data.0.betting_value')
        ->assertJsonMissingPath('data.0.betting_value_summary');
});
