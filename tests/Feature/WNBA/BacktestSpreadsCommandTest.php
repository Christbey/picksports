<?php

use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\Team;
use Illuminate\Support\Facades\Artisan;

it('backtests wnba spreads from stored odds data', function () {
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
        'status' => 'STATUS_FINAL',
        'home_score' => 86,
        'away_score' => 78,
        'odds_updated_at' => now(),
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'New York Liberty', 'point' => 3.5, 'price' => -108],
                        ['name' => 'Las Vegas Aces', 'point' => -3.5, 'price' => -112],
                    ],
                ]],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 6.6,
        'predicted_total' => 166.3,
        'win_probability' => 0.64,
        'confidence_score' => 70.5,
        'winner_correct' => true,
        'spread_error' => 1.4,
        'graded_at' => now(),
    ]);

    $exitCode = Artisan::call('wnba:backtest-spreads', [
        '--season' => 2026,
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($report['count'])->toBe(1)
        ->and($report['missing_spread_line'])->toBe(0)
        ->and($report['threshold_record']['wins'])->toBe(1)
        ->and($report['gate_record']['wins'])->toBe(1);
});
