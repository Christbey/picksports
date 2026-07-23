<?php

use App\Console\Commands\NFL\BacktestSpreadsCommand;
use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;

it('reports spread closing line value from nfl odds snapshots', function () {
    $home = Team::factory()->create([
        'abbreviation' => 'HME',
        'location' => 'Home',
        'name' => 'Team',
    ]);
    $away = Team::factory()->create([
        'abbreviation' => 'AWY',
        'location' => 'Away',
        'name' => 'Team',
    ]);

    $game = Game::query()->create([
        'espn_event_id' => 'clv-game',
        'espn_uid' => 'clv-game-uid',
        'season' => 2025,
        'week' => 8,
        'season_type' => 'regular',
        'game_date' => '2025-10-26',
        'game_time' => '12:00:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 27,
        'away_score' => 20,
        'status' => 'STATUS_FINAL',
        'odds_data' => nflBacktestOddsPayload(-3.0),
        'odds_updated_at' => '2025-10-25 12:00:00',
    ]);

    Prediction::factory()->create([
        'game_id' => $game->id,
        'predicted_spread' => 6.0,
        'predicted_total' => 45.0,
        'win_probability' => 0.68,
        'confidence_score' => 68,
    ]);

    GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $game->id,
        'odds_api_event_id' => 'odds-clv-game',
        'bookmaker_key' => 'draftkings',
        'bookmaker_title' => 'DraftKings',
        'source' => 'odds_api',
        'commence_time' => '2025-10-26 12:00:00',
        'captured_at' => '2025-10-25 12:00:00',
        'payload_hash' => hash('sha256', json_encode(nflBacktestOddsPayload(-3.0))),
        'odds_data' => nflBacktestOddsPayload(-3.0),
    ]);

    GameOddsSnapshot::query()->create([
        'sport' => 'nfl',
        'game_table' => 'nfl_games',
        'game_id' => $game->id,
        'odds_api_event_id' => 'odds-clv-game',
        'bookmaker_key' => 'draftkings',
        'bookmaker_title' => 'DraftKings',
        'source' => 'odds_api',
        'commence_time' => '2025-10-26 12:00:00',
        'captured_at' => '2025-10-26 11:55:00',
        'payload_hash' => hash('sha256', json_encode(nflBacktestOddsPayload(-4.0))),
        'odds_data' => nflBacktestOddsPayload(-4.0),
    ]);

    $this->artisan('nfl:backtest-spreads', [
        '--season' => 2025,
        '--limit' => 10,
    ])
        ->expectsOutputToContain('CLV sample')
        ->expectsOutputToContain('+1.00 pts')
        ->expectsOutputToContain('100.0%')
        ->assertSuccessful();
});

it('normalizes nflverse favorite positive spreads before spread backtests', function () {
    $command = app(BacktestSpreadsCommand::class);
    $method = new ReflectionMethod($command, 'homeMarketSpread');
    $method->setAccessible(true);

    $oddsApiPayload = nflBacktestOddsPayload(-7.0);
    $nflversePayload = nflBacktestOddsPayload(7.0, 'nflverse_closing', 'nflverse closing');

    expect($method->invoke($command, $oddsApiPayload, 'Home Team'))->toBe(-7.0)
        ->and($method->invoke($command, $nflversePayload, 'Home Team'))->toBe(-7.0);
});

/**
 * @return array<string,mixed>
 */
function nflBacktestOddsPayload(float $homeSpread, string $bookmakerKey = 'draftkings', string $bookmakerTitle = 'DraftKings'): array
{
    return [
        'event_id' => 'odds-clv-game',
        'commence_time' => '2025-10-26T17:00:00Z',
        'home_team' => 'Home Team',
        'away_team' => 'Away Team',
        'bookmakers' => [[
            'key' => $bookmakerKey,
            'title' => $bookmakerTitle,
            'markets' => [[
                'key' => 'spreads',
                'outcomes' => [
                    ['name' => 'Home Team', 'price' => -110, 'point' => $homeSpread],
                    ['name' => 'Away Team', 'price' => -110, 'point' => -$homeSpread],
                ],
            ]],
        ]],
    ];
}
