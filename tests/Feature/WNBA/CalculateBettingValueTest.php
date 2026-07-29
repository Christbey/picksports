<?php

use App\Actions\WNBA\CalculateBettingValue;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\Team;
use App\Services\BettingRecommendations\GameBettingRecommendationService;

it('calculates wnba spread total and moneyline value with wnba team matching', function () {
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
        'odds_updated_at' => now(),
        'odds_data' => [
            'home_team' => 'Las Vegas Aces',
            'away_team' => 'New York Liberty',
            'bookmakers' => [[
                'key' => 'draftkings',
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
                    [
                        'key' => 'h2h',
                        'outcomes' => [
                            ['name' => 'Las Vegas Aces', 'price' => -110],
                            ['name' => 'New York Liberty', 'price' => -105],
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
        'confidence_score' => 70.5,
        'model_metadata' => [
            'market_blend' => ['applied' => true],
        ],
    ]);

    $recommendations = app(CalculateBettingValue::class)->execute($game->fresh(['prediction', 'homeTeam', 'awayTeam']));

    expect($recommendations)->toBeArray()
        ->and(collect($recommendations)->pluck('type')->all())->toContain('spread', 'total', 'moneyline');

    $spread = collect($recommendations)->firstWhere('type', 'spread');
    $total = collect($recommendations)->firstWhere('type', 'total');
    $moneyline = collect($recommendations)->firstWhere('type', 'moneyline');

    expect($spread['recommendation'])->toBe('Bet Las Vegas Aces -3.5')
        ->and($spread['bet_team'])->toBe('Las Vegas Aces')
        ->and($spread['edge'])->toBe(2.7)
        ->and($total['recommendation'])->toBe('Bet Over')
        ->and($total['edge'])->toBe(4.8)
        ->and($moneyline['recommendation'])->toBe('Bet Las Vegas Aces ML')
        ->and($moneyline['edge'])->toBeGreaterThan(10)
        ->and($moneyline['is_playable'])->toBeTrue();
});

it('routes wnba game betting recommendations through the wnba calculator', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Los Angeles',
        'name' => 'Sparks',
        'abbreviation' => 'LA',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Seattle',
        'name' => 'Storm',
        'abbreviation' => 'SEA',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'week' => 10,
        'status' => 'STATUS_SCHEDULED',
        'odds_updated_at' => now(),
        'odds_data' => [
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'LA Sparks', 'price' => 120],
                        ['name' => 'Seattle Storm', 'price' => -140],
                    ],
                ]],
            ]],
        ],
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.2,
        'predicted_total' => 158.0,
        'win_probability' => 0.58,
        'confidence_score' => 67.0,
        'model_metadata' => [
            'market_blend' => ['applied' => true],
        ],
    ]);

    $recommendations = app(GameBettingRecommendationService::class)
        ->forGame($game->fresh(['prediction', 'homeTeam', 'awayTeam']), 'wnba');

    expect($recommendations)->toHaveCount(1)
        ->and($recommendations[0]['type'])->toBe('moneyline')
        ->and($recommendations[0]['recommendation'])->toBe('Bet Los Angeles Sparks ML');
});
