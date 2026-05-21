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
