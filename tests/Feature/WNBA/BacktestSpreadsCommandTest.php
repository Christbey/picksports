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
        ->and($report['gate_record']['wins'])->toBe(1)
        ->and($report['candidate_records']['current_gate']['wins'])->toBe(1)
        ->and($report['candidate_records']['validated_3_5_edge']['wins'])->toBe(1)
        ->and($report['candidate_records']['underdog_2_5_5_edge']['bets'])->toBe(0)
        ->and($report['candidate_records']['all_threshold_plays']['wins'])->toBe(1);
});

it('compares current and candidate wnba spread gates', function () {
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

    $createPrediction = function (
        float $homeSpread,
        float $modelSpread,
        int $homeScore,
        int $awayScore,
        float $confidence
    ) use ($homeTeam, $awayTeam): void {
        $game = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'season' => 2026,
            'week' => 8,
            'status' => 'STATUS_FINAL',
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'odds_updated_at' => now(),
            'odds_data' => [
                'bookmakers' => [[
                    'key' => 'draftkings',
                    'markets' => [[
                        'key' => 'spreads',
                        'outcomes' => [
                            ['name' => 'New York Liberty', 'point' => -$homeSpread, 'price' => -108],
                            ['name' => 'Las Vegas Aces', 'point' => $homeSpread, 'price' => -112],
                        ],
                    ]],
                ]],
            ],
        ]);

        Prediction::factory()->create([
            'game_id' => $game->id,
            'predicted_spread' => $modelSpread,
            'predicted_total' => 166.3,
            'win_probability' => 0.64,
            'confidence_score' => $confidence,
            'winner_correct' => true,
            'spread_error' => abs(($homeScore - $awayScore) - $modelSpread),
            'graded_at' => now(),
        ]);
    };

    $createPrediction(-3.5, 6.6, 86, 78, 70.5); // Current gate favorite win, 3.1 edge.
    $createPrediction(1.0, 2.0, 76, 80, 68.0); // Current gate underdog loss, 3.0 edge.
    $createPrediction(-2.0, 5.4, 81, 80, 82.0); // Blocked high-confidence favorite loss, 3.4 edge.
    $createPrediction(2.0, 3.6, 83, 80, 62.0); // Large edge threshold win, 5.6 edge.

    $exitCode = Artisan::call('wnba:backtest-spreads', [
        '--season' => 2026,
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($report['count'])->toBe(4)
        ->and($report['candidate_records'])->toHaveKeys([
            'current_gate',
            'validated_3_5_edge',
            'underdog_2_5_5_edge',
            'all_threshold_plays',
        ])
        ->and($report['candidate_records']['current_gate'])->toMatchArray([
            'bets' => 2,
            'wins' => 1,
            'losses' => 1,
            'pushes' => 0,
            'win_rate' => 50.0,
        ])
        ->and($report['candidate_records']['validated_3_5_edge'])->toMatchArray([
            'bets' => 3,
            'wins' => 1,
            'losses' => 2,
            'pushes' => 0,
            'win_rate' => 33.3,
        ])
        ->and($report['candidate_records']['underdog_2_5_5_edge'])->toMatchArray([
            'bets' => 1,
            'wins' => 0,
            'losses' => 1,
            'pushes' => 0,
            'win_rate' => 0.0,
        ])
        ->and($report['candidate_records']['all_threshold_plays'])->toMatchArray([
            'bets' => 4,
            'wins' => 2,
            'losses' => 2,
            'pushes' => 0,
            'win_rate' => 50.0,
        ]);
});
