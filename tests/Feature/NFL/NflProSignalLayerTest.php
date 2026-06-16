<?php

use App\Console\Commands\NFL\ReportProSignalsCommand;
use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\GameWeather;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Services\NFL\NflProSignalLayer;

uses()->group('nfl', 'predictions');

function nflProSignalOddsPayload(float $homePoint, int $homePrice = -110, int $awayPrice = -110): array
{
    return [
        'home_team' => 'Detroit Lions',
        'away_team' => 'Chicago Bears',
        'bookmakers' => [[
            'key' => 'draftkings',
            'markets' => [[
                'key' => 'spreads',
                'outcomes' => [
                    ['name' => 'Detroit Lions', 'point' => $homePoint, 'price' => $homePrice],
                    ['name' => 'Chicago Bears', 'point' => -$homePoint, 'price' => $awayPrice],
                ],
            ], [
                'key' => 'totals',
                'outcomes' => [
                    ['name' => 'Over', 'point' => 43.5, 'price' => -110],
                    ['name' => 'Under', 'point' => 43.5, 'price' => -110],
                ],
            ]],
        ]],
    ];
}

function nflProSignalGame(array $overrides = []): Game
{
    $homeTeam = Team::factory()->create([
        'location' => 'Detroit',
        'name' => 'Lions',
        'abbreviation' => 'DET',
        'conference' => 'NFC',
        'division' => 'North',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Bears',
        'abbreviation' => 'CHI',
        'conference' => 'NFC',
        'division' => 'North',
    ]);

    return Game::factory()->create(array_merge([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 5,
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
        'odds_data' => nflProSignalOddsPayload(-2.5),
    ], $overrides))->fresh(['homeTeam', 'awayTeam']);
}

it('identifies the five key number and valid teaser corridor', function () {
    config(['nfl.betting.key_numbers' => [3, 5, 7, 10]]);

    $game = nflProSignalGame();
    $analysis = [
        'reason_codes' => ['strong_model_signal'],
        'risk_flags' => [],
        'calculated_edge' => [
            'market_spread' => 2.5,
            'market_total' => 43.5,
            'spread_points' => 5.5,
            'total_points' => -3.5,
        ],
    ];

    $layer = app(NflProSignalLayer::class)->build(
        $game,
        [
            'qb_form' => ['signal_spread' => 1.5],
            'line_matchup' => ['signal_spread' => 1.2],
            'rolling_efficiency' => ['signal_spread' => 1.3, 'home' => ['turnover_diff' => 2.0], 'away' => ['turnover_diff' => 0.0]],
            'contextual_factors' => [
                'division_rivalry' => ['is_division_game' => true],
                'schedule_spot' => ['applied' => true],
                'weather_total' => ['total_adjustment' => -1.5],
            ],
        ],
        $analysis,
        8.0,
        40.0,
        0.72
    );

    expect(data_get($layer, 'market_context.crossed_key_numbers'))->toBe([3, 5, 7])
        ->and(data_get($layer, 'market_context.teaser_candidate'))->toBeTrue()
        ->and(data_get($layer, 'market_scores.winner.score'))->toBeInt()
        ->and(data_get($layer, 'market_scores.spread.score'))->toBeInt()
        ->and(data_get($layer, 'market_scores.total.score'))->toBeInt()
        ->and(data_get($layer, 'reason_codes'))->toContain('key_number_edge_5')
        ->and(data_get($layer, 'reason_codes'))->toContain('teaser_candidate')
        ->and(data_get($layer, 'reason_codes'))->toContain('weather_total_suppression')
        ->and(data_get($layer, 'tier'))->toBeIn(['lean', 'official_candidate']);
});

it('caps high scoring profiles when no market or key-number value exists', function () {
    $game = nflProSignalGame([
        'odds_data' => nflProSignalOddsPayload(-6.0),
    ]);
    $analysis = [
        'reason_codes' => ['strong_model_signal'],
        'risk_flags' => [],
        'calculated_edge' => [
            'market_spread' => 6.0,
            'market_total' => 43.5,
            'spread_points' => 0.4,
            'total_points' => 0.5,
        ],
    ];

    $layer = app(NflProSignalLayer::class)->build(
        $game,
        [
            'qb_form' => ['signal_spread' => 3.0],
            'line_matchup' => ['signal_spread' => 3.0],
            'rolling_efficiency' => ['signal_spread' => 3.0],
            'opponent_adjusted_efficiency' => ['signal_spread' => 3.0],
            'true_epa' => ['signal_spread' => 3.0],
            'contextual_factors' => ['schedule_spot' => ['applied' => true]],
        ],
        $analysis,
        6.4,
        44.0,
        0.78
    );

    expect(data_get($layer, 'score'))->toBeLessThan(60)
        ->and(data_get($layer, 'tier'))->toBeIn(['pass', 'watchlist'])
        ->and(data_get($layer, 'risk_flags'))->toContain('no_market_or_key_number_gate');
});

it('adds v2 market injury weather efficiency and regression context', function () {
    $game = nflProSignalGame([
        'status' => 'STATUS_FINAL',
        'home_score' => 24,
        'away_score' => 17,
        'odds_data' => [
            'home_team' => 'Detroit Lions',
            'away_team' => 'Chicago Bears',
            'bookmakers' => [[
                'key' => 'sharp',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Detroit Lions', 'point' => -2.5, 'price' => -110],
                        ['name' => 'Chicago Bears', 'point' => 2.5, 'price' => -110],
                    ],
                ]],
            ], [
                'key' => 'slow',
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Detroit Lions', 'point' => -3.5, 'price' => -110],
                        ['name' => 'Chicago Bears', 'point' => 3.5, 'price' => -110],
                    ],
                ]],
            ]],
        ],
    ]);
    GameWeather::query()->create([
        'game_id' => $game->id,
        'provider' => 'test',
        'observed_at' => now(),
        'temperature_f' => 28,
        'wind_speed_mph' => 20,
        'wind_gust_mph' => 27,
        'precipitation_inches' => 0.04,
        'is_indoor' => false,
    ]);

    foreach ([[-1.5, 180], [-3.0, 90], [-2.0, 0]] as [$homePoint, $minutesAgo]) {
        GameOddsSnapshot::query()->create([
            'sport' => 'nfl',
            'game_table' => $game->getTable(),
            'game_id' => $game->id,
            'source' => 'test',
            'captured_at' => now()->subMinutes($minutesAgo),
            'payload_hash' => 'v2-context-'.$homePoint,
            'odds_data' => nflProSignalOddsPayload($homePoint),
        ]);
    }

    $layer = app(NflProSignalLayer::class)->build(
        $game->fresh(['homeTeam', 'awayTeam']),
        [
            'depth_chart_injuries' => [
                'spread_adjustment' => 1.7,
                'home_out_weighted' => 2.0,
                'away_out_weighted' => 0.2,
                'home_questionable_weighted' => 0.5,
                'away_questionable_weighted' => 0.1,
            ],
            'true_epa' => ['signal_spread' => 1.4],
            'line_matchup' => ['signal_spread' => 1.2],
            'rolling_efficiency' => ['signal_spread' => 1.1, 'home' => ['turnover_diff' => 2.2], 'away' => ['turnover_diff' => 0.3]],
            'opponent_adjusted_efficiency' => ['signal_spread' => 1.0],
            'actual_weather' => ['total_adjustment' => -2.0],
        ],
        [
            'reason_codes' => ['strong_model_signal'],
            'risk_flags' => [],
            'calculated_edge' => [
                'market_spread' => 2.5,
                'market_total' => 40.0,
                'spread_points' => 5.5,
                'total_points' => -4.0,
            ],
        ],
        8.0,
        36.0,
        0.74
    );

    expect(data_get($layer, 'number_discipline.low_total_key_number_boost'))->toBeTrue()
        ->and(data_get($layer, 'market_movement.steam_freshness'))->toBeTrue()
        ->and(data_get($layer, 'market_movement.market_setter_slow_book_gap'))->toBeTrue()
        ->and(data_get($layer, 'market_movement.buyback_resistance'))->toBeTrue()
        ->and(data_get($layer, 'market_movement.closing_line_value_points'))->toBe(0.5)
        ->and(data_get($layer, 'injury_replacement.qb_replacement_edge'))->toBeTrue()
        ->and(data_get($layer, 'weather_roof.weather_total_suppression'))->toBeTrue()
        ->and(data_get($layer, 'efficiency_mismatch.epa_edge'))->toBeTrue()
        ->and(data_get($layer, 'regression_context.turnover_luck_flagged'))->toBeTrue()
        ->and(data_get($layer, 'reason_codes'))->toContain('low_total_key_number_boost')
        ->and(data_get($layer, 'reason_codes'))->toContain('positive_clv_profile')
        ->and(data_get($layer, 'reason_codes'))->toContain('qb_replacement_value_edge')
        ->and(data_get($layer, 'reason_codes'))->toContain('efficiency_mismatch_edge');
});

it('uses historical odds snapshots when game odds data is empty', function () {
    $game = nflProSignalGame([
        'odds_data' => null,
    ]);
    GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => $game->getTable(),
        'game_id' => $game->id,
        'source' => 'historical',
        'captured_at' => now()->subDay(),
        'payload_hash' => 'snapshot-fallback-entry',
        'odds_data' => nflProSignalOddsPayload(-2.5),
    ]);

    $layer = app(NflProSignalLayer::class)->build(
        $game->fresh(['homeTeam', 'awayTeam']),
        [],
        [
            'reason_codes' => ['strong_model_signal'],
            'risk_flags' => [],
            'calculated_edge' => [
                'market_spread' => null,
                'market_total' => null,
                'spread_points' => null,
                'total_points' => null,
            ],
        ],
        6.5,
        46.0,
        0.69
    );

    expect(data_get($layer, 'market_context.market_spread'))->toBe(2.5)
        ->and(data_get($layer, 'market_context.market_total'))->toBe(43.5)
        ->and(data_get($layer, 'market_context.spread_edge'))->toBe(4.0)
        ->and(data_get($layer, 'market_context.total_edge'))->toBe(2.5)
        ->and(data_get($layer, 'reason_codes'))->toContain('market_overreaction');
});

it('reports pro signal tiers and reason-code backtest rows', function () {
    $game = nflProSignalGame([
        'season' => 2025,
        'status' => 'STATUS_FINAL',
        'home_score' => 27,
        'away_score' => 20,
        'odds_data' => nflProSignalOddsPayload(-2.5),
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 6.5,
        'predicted_total' => 44.0,
        'win_probability' => 0.68,
        'confidence_score' => 72.0,
        'model_metadata' => [
            'analysis_layer' => [
                'pro_signal_layer' => [
                    'score' => 76,
                    'tier' => 'official_candidate',
                    'market_context' => [
                        'pick_side' => 'home',
                        'market_spread' => 2.5,
                    ],
                    'reason_codes' => ['key_number_edge_3', 'teaser_candidate'],
                ],
            ],
        ],
    ]);
    GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => $game->getTable(),
        'game_id' => $game->id,
        'source' => 'test',
        'captured_at' => now(),
        'payload_hash' => 'test-pro-signal-layer',
        'odds_data' => nflProSignalOddsPayload(-3.0),
    ]);

    $this->artisan(ReportProSignalsCommand::class, [
        '--from-season' => 2025,
        '--to-season' => 2025,
        '--min-sample' => 1,
    ])
        ->expectsOutputToContain('NFL Pro Signal Layer Report')
        ->expectsOutputToContain('Winner %')
        ->expectsOutputToContain('By Winner Tier')
        ->expectsOutputToContain('By Spread Tier')
        ->expectsOutputToContain('official_candidate')
        ->expectsOutputToContain('teaser_candidate')
        ->assertSuccessful();
});

it('reports pro signal rows using snapshot odds when pro layer market context is empty', function () {
    $game = nflProSignalGame([
        'season' => 2025,
        'status' => 'STATUS_FINAL',
        'home_score' => 27,
        'away_score' => 20,
        'odds_data' => null,
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 6.5,
        'predicted_total' => 47.0,
        'win_probability' => 0.68,
        'confidence_score' => 72.0,
        'model_metadata' => [
            'analysis_layer' => [
                'pro_signal_layer' => [
                    'score' => 68,
                    'tier' => 'lean',
                    'market_context' => [
                        'pick_side' => null,
                        'market_spread' => null,
                        'market_total' => null,
                    ],
                    'reason_codes' => ['market_overreaction'],
                ],
            ],
        ],
    ]);
    GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => $game->getTable(),
        'game_id' => $game->id,
        'source' => 'test',
        'captured_at' => now()->subDay(),
        'payload_hash' => 'report-snapshot-fallback-entry',
        'odds_data' => nflProSignalOddsPayload(-2.5),
    ]);

    $this->artisan(ReportProSignalsCommand::class, [
        '--from-season' => 2025,
        '--to-season' => 2025,
        '--min-sample' => 1,
    ])
        ->expectsOutputToContain('NFL Pro Signal Layer Report')
        ->expectsOutputToContain('Winner %')
        ->expectsOutputToContain('By Spread Tier')
        ->expectsOutputToContain('lean')
        ->expectsOutputToContain('market_overreaction')
        ->assertSuccessful();
});

it('does not create a positive clv profile from a flat closing line', function () {
    $game = nflProSignalGame([
        'status' => 'STATUS_FINAL',
        'home_score' => 24,
        'away_score' => 17,
        'odds_data' => nflProSignalOddsPayload(-2.5),
    ]);

    GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => $game->getTable(),
        'game_id' => $game->id,
        'source' => 'test',
        'captured_at' => now()->subHour(),
        'payload_hash' => 'flat-clv-open',
        'odds_data' => nflProSignalOddsPayload(-2.5),
    ]);
    GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => $game->getTable(),
        'game_id' => $game->id,
        'source' => 'test',
        'captured_at' => now(),
        'payload_hash' => 'flat-clv-close',
        'odds_data' => nflProSignalOddsPayload(-2.5),
    ]);

    $layer = app(NflProSignalLayer::class)->build(
        $game->fresh(['homeTeam', 'awayTeam']),
        [],
        [
            'reason_codes' => ['strong_model_signal'],
            'risk_flags' => [],
            'calculated_edge' => [
                'market_spread' => 2.5,
                'market_total' => 43.5,
                'spread_points' => 4.0,
                'total_points' => null,
            ],
        ],
        6.5,
        44.0,
        0.68
    );

    expect(data_get($layer, 'market_movement.closing_line_value_points'))->toBe(0.0)
        ->and(data_get($layer, 'market_movement.positive_clv_profile'))->toBeFalse()
        ->and(data_get($layer, 'reason_codes'))->not->toContain('positive_clv_profile');
});
