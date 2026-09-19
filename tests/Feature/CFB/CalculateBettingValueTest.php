<?php

use App\Actions\Sports\CalculateBettingValue;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;

it('suppresses cfb betting value when both elo ratings are still default', function () {
    $home = Team::factory()->create(['school' => 'Home', 'mascot' => 'Hawks', 'elo_rating' => 1500]);
    $away = Team::factory()->create(['school' => 'Away', 'mascot' => 'Bears', 'elo_rating' => 1500]);
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_SCHEDULED',
        'odds_data' => cfbBettingValueOdds(),
    ]);
    Prediction::factory()->create([
        'game_id' => $game->id,
        'home_elo' => 1500,
        'away_elo' => 1500,
        'home_fpi' => 5,
        'away_fpi' => -5,
        'predicted_spread' => 3.5,
        'predicted_total' => 52,
        'win_probability' => 0.62,
        'confidence_score' => 62,
    ]);

    $recommendations = app(CalculateBettingValue::class)
        ->execute($game->fresh(['prediction', 'homeTeam', 'awayTeam']), 'cfb');

    expect($recommendations)->toBeNull();
});

it('keeps cfb betting value available when elo inputs are differentiated', function () {
    $home = Team::factory()->create(['school' => 'Home', 'mascot' => 'Hawks', 'elo_rating' => 1550]);
    $away = Team::factory()->create(['school' => 'Away', 'mascot' => 'Bears', 'elo_rating' => 1450]);
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_SCHEDULED',
        'odds_data' => cfbBettingValueOdds(),
    ]);
    Prediction::factory()->create([
        'game_id' => $game->id,
        'home_elo' => 1550,
        'away_elo' => 1450,
        'home_fpi' => null,
        'away_fpi' => null,
        'predicted_spread' => 3.5,
        'predicted_total' => 52,
        'win_probability' => 0.62,
        'confidence_score' => 62,
    ]);

    $recommendations = app(CalculateBettingValue::class)
        ->execute($game->fresh(['prediction', 'homeTeam', 'awayTeam']), 'cfb');

    expect(collect($recommendations)->firstWhere('type', 'spread'))
        ->not->toBeNull()
        ->and(collect($recommendations)->firstWhere('type', 'spread')['recommendation'])
        ->toBe('Bet Away Bears +10.0');
});

/** @return array<string, mixed> */
function cfbBettingValueOdds(): array
{
    return [
        'bookmakers' => [[
            'markets' => [[
                'key' => 'spreads',
                'outcomes' => [
                    ['name' => 'Home Hawks', 'point' => -10, 'price' => -110],
                    ['name' => 'Away Bears', 'point' => 10, 'price' => -110],
                ],
            ]],
        ]],
    ];
}
