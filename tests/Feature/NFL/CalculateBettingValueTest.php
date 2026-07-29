<?php

use App\Actions\NFL\CalculateBettingValue;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;

it('uses the home outcome line when calculating nfl spread value', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Detroit',
        'name' => 'Lions',
        'abbreviation' => 'DET',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'New Orleans',
        'name' => 'Saints',
        'abbreviation' => 'NO',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_data' => [
            'home_team' => 'Detroit Lions',
            'away_team' => 'New Orleans Saints',
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Detroit Lions', 'point' => -7, 'price' => -110],
                        ['name' => 'New Orleans Saints', 'point' => 7, 'price' => -110],
                    ],
                ]],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 8.9,
        'predicted_total' => 46.2,
        'win_probability' => 0.78,
        'confidence_score' => 78.1,
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh(['prediction', 'homeTeam', 'awayTeam']));

    expect($recommendations)->toBeNull();
});

it('adds grading and risk details to nfl betting value recommendations', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Seattle',
        'name' => 'Seahawks',
        'abbreviation' => 'SEA',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'New England',
        'name' => 'Patriots',
        'abbreviation' => 'NE',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 1,
        'odds_data' => [
            'home_team' => 'Seattle Seahawks',
            'away_team' => 'New England Patriots',
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'New England Patriots', 'point' => 3.5, 'price' => -105],
                        ['name' => 'Seattle Seahawks', 'point' => -3.5, 'price' => -115],
                    ],
                ]],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 6.9,
        'predicted_total' => 44.9,
        'win_probability' => 0.73,
        'confidence_score' => 72.82,
        'model_metadata' => [
            'true_epa' => ['applied' => true],
            'market_blend' => ['applied' => true],
        ],
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh(['prediction', 'homeTeam', 'awayTeam']));
    $spread = collect($recommendations)->firstWhere('type', 'spread');

    expect($spread)->not->toBeNull()
        ->and($spread['recommendation'])->toBe('Bet Seattle Seahawks -3.5')
        ->and($spread['grade'])->toBeIn(['B', 'C'])
        ->and($spread['recommendation_strength'])->toBeIn(['play', 'lean'])
        ->and($spread['is_playable'])->toBeTrue()
        ->and($spread['risk_flags'])->toContain('early_season')
        ->and($spread['bet_units'])->toBeGreaterThan(0);
});

it('keeps weaker nfl moneyline value on the watchlist until historical play gates clear', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Kansas City',
        'name' => 'Chiefs',
        'abbreviation' => 'KC',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Denver',
        'name' => 'Broncos',
        'abbreviation' => 'DEN',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 4,
        'odds_data' => [
            'home_team' => 'Kansas City Chiefs',
            'away_team' => 'Denver Broncos',
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'Kansas City Chiefs', 'price' => -250],
                        ['name' => 'Denver Broncos', 'price' => 170],
                    ],
                ]],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 2.0,
        'predicted_total' => 44.0,
        'win_probability' => 0.60,
        'confidence_score' => 76.0,
        'model_metadata' => [
            'true_epa' => ['applied' => true],
            'market_blend' => ['applied' => true],
            'analysis_layer' => [
                'trust_score' => 76.0,
            ],
        ],
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh(['prediction', 'homeTeam', 'awayTeam']));
    $moneyline = collect($recommendations)->firstWhere('type', 'moneyline');

    expect($moneyline)->not->toBeNull()
        ->and($moneyline['recommendation'])->toBe('Bet Denver Broncos ML')
        ->and($moneyline['model_probability'])->toBe(40.0)
        ->and($moneyline['no_vig_implied_probability'])->toBeLessThan(35.0)
        ->and($moneyline['edge'])->toBeGreaterThan(5.0)
        ->and($moneyline['expected_value_percent'])->toBeGreaterThan(0)
        ->and($moneyline['fair_odds'])->toBe(150)
        ->and($moneyline['moneyline_signal_action'])->toBe('lean')
        ->and($moneyline['grade'])->toBe('Watchlist')
        ->and($moneyline['is_playable'])->toBeFalse();
});

it('promotes nfl moneyline value only when the play gate is explicitly enabled', function () {
    config()->set('nfl.betting.moneyline.play_enabled', true);

    $homeTeam = Team::factory()->create([
        'location' => 'Kansas City',
        'name' => 'Chiefs',
        'abbreviation' => 'KC',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Denver',
        'name' => 'Broncos',
        'abbreviation' => 'DEN',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 4,
        'odds_data' => [
            'home_team' => 'Kansas City Chiefs',
            'away_team' => 'Denver Broncos',
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'Kansas City Chiefs', 'price' => -250],
                        ['name' => 'Denver Broncos', 'price' => 250],
                    ],
                ]],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 2.0,
        'predicted_total' => 44.0,
        'win_probability' => 0.60,
        'confidence_score' => 86.0,
        'model_metadata' => [
            'true_epa' => ['applied' => true],
            'market_blend' => ['applied' => true],
            'analysis_layer' => [
                'trust_score' => 86.0,
            ],
        ],
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh(['prediction', 'homeTeam', 'awayTeam']));
    $moneyline = collect($recommendations)->firstWhere('type', 'moneyline');

    expect($moneyline)->not->toBeNull()
        ->and($moneyline['recommendation'])->toBe('Bet Denver Broncos ML')
        ->and($moneyline['edge'])->toBeGreaterThan(10.0)
        ->and($moneyline['moneyline_signal_action'])->toBe('play')
        ->and($moneyline['is_playable'])->toBeTrue();
});

it('requires matching nfl total rule support before surfacing over under value', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Green Bay',
        'name' => 'Packers',
        'abbreviation' => 'GB',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Bears',
        'abbreviation' => 'CHI',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 8,
        'odds_data' => [
            'home_team' => 'Green Bay Packers',
            'away_team' => 'Chicago Bears',
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'point' => 43.5, 'price' => -110],
                        ['name' => 'Under', 'point' => 43.5, 'price' => -110],
                    ],
                ]],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 3.0,
        'predicted_total' => 39.8,
        'win_probability' => 0.64,
        'confidence_score' => 70.0,
        'model_metadata' => [
            'true_epa' => ['applied' => true],
            'market_blend' => ['applied' => true],
            'analysis_layer' => [
                'trust_score' => 70.0,
                'reason_codes' => ['market_total_edge_over', 'fast_pace_over_signal'],
                'bet_rule_evaluation' => [
                    'matched_rules' => [[
                        'name' => 'dome_fast_track_over',
                        'action' => 'lean',
                        'market' => 'total',
                    ]],
                ],
            ],
        ],
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh(['prediction', 'homeTeam', 'awayTeam']));
    expect(collect($recommendations)->firstWhere('type', 'total'))->toBeNull();

    $game->prediction->update([
        'model_metadata' => [
            'true_epa' => ['applied' => true],
            'market_blend' => ['applied' => true],
            'analysis_layer' => [
                'trust_score' => 70.0,
                'reason_codes' => ['market_total_edge_under', 'cold_weather_under_signal'],
                'bet_rule_evaluation' => [
                    'matched_rules' => [[
                        'name' => 'cold_weather_total_under_watch',
                        'action' => 'lean',
                        'market' => 'total',
                    ]],
                ],
            ],
        ],
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh(['prediction', 'homeTeam', 'awayTeam']));
    $total = collect($recommendations)->firstWhere('type', 'total');

    expect($total)->not->toBeNull()
        ->and($total['recommendation'])->toBe('Bet Under')
        ->and($total['total_signal_action'])->toBe('lean')
        ->and($total['grade'])->toBe('Watchlist')
        ->and($total['is_playable'])->toBeFalse();
});
