<?php

use App\Actions\OddsApi\MLB\SyncOddsForGames;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Mockery as m;

uses()->group('mlb', 'odds');

afterEach(function () {
    m::close();
});

it('matches regular season mlb odds events when local games use legacy season type labels', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'San Francisco',
        'name' => 'Giants',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Yankees',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => 'Regular Season',
        'game_date' => now()->addDays(2)->toDateString(),
        'game_time' => '19:05:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_api_event_id' => null,
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $oddsService = m::mock(OddsApiService::class);
    $oddsService->shouldReceive('getOdds')
        ->once()
        ->with('baseball_mlb')
        ->andReturn([
            [
                'id' => 'odds-evt-1',
                'home_team' => 'San Francisco Giants',
                'away_team' => 'New York Yankees',
                'commence_time' => now()->addDays(2)->setTime(19, 5)->toIso8601String(),
                'bookmakers' => [
                    [
                        'key' => 'draftkings',
                        'markets' => [
                            [
                                'key' => 'h2h',
                                'outcomes' => [
                                    ['name' => 'San Francisco Giants', 'price' => -120],
                                    ['name' => 'New York Yankees', 'price' => 100],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    $oddsService->shouldReceive('fuzzyMatchTeams')
        ->once()
        ->andReturn(true);
    $oddsService->shouldReceive('extractOddsData')
        ->once()
        ->andReturn([
            'home_team' => 'San Francisco Giants',
            'away_team' => 'New York Yankees',
            'bookmakers' => [
                ['key' => 'draftkings', 'markets' => [['key' => 'h2h']]],
            ],
        ]);

    $action = new SyncOddsForGames($oddsService, app(SportsViewCache::class));
    $updated = $action->execute(7);

    $game->refresh();

    expect($updated)->toBe(1)
        ->and($game->odds_api_event_id)->toBe('odds-evt-1')
        ->and($game->odds_updated_at)->not->toBeNull()
        ->and(data_get($game->odds_data, 'bookmakers.0.key'))->toBe('draftkings');
});
