<?php

use App\Actions\OddsApi\MLB\SyncOddsForGames;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\OddsApiTeamMapping;
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
    $oddsService->shouldReceive('mappedEspnTeamName')
        ->twice()
        ->andReturnNull();
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

it('matches mlb odds events using manual odds api to espn team mappings before fuzzy matching', function () {
    OddsApiTeamMapping::query()->create([
        'sport' => 'baseball_mlb',
        'odds_api_team_name' => 'Arizona Diamondbacks',
        'espn_team_name' => 'Diamondbacks',
    ]);
    OddsApiTeamMapping::query()->create([
        'sport' => 'baseball_mlb',
        'odds_api_team_name' => 'Seattle Mariners',
        'espn_team_name' => 'Mariners',
    ]);

    $homeTeam = Team::factory()->create([
        'location' => 'Seattle',
        'name' => 'Mariners',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Arizona',
        'name' => 'Diamondbacks',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'game_date' => now()->addDays(3)->toDateString(),
        'game_time' => '21:10:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_api_event_id' => null,
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $oddsService = m::mock(OddsApiService::class)->makePartial();
    $oddsService->shouldReceive('getOdds')
        ->once()
        ->with('baseball_mlb')
        ->andReturn([
            [
                'id' => 'odds-evt-2',
                'home_team' => 'Seattle Mariners',
                'away_team' => 'Arizona Diamondbacks',
                'commence_time' => now()->addDays(3)->setTime(21, 10)->toIso8601String(),
                'bookmakers' => [
                    [
                        'key' => 'draftkings',
                        'markets' => [
                            [
                                'key' => 'h2h',
                                'outcomes' => [
                                    ['name' => 'Seattle Mariners', 'price' => -125],
                                    ['name' => 'Arizona Diamondbacks', 'price' => 105],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    $oddsService->shouldReceive('extractOddsData')
        ->once()
        ->andReturn([
            'home_team' => 'Seattle Mariners',
            'away_team' => 'Arizona Diamondbacks',
            'bookmakers' => [
                ['key' => 'draftkings', 'markets' => [['key' => 'h2h']]],
            ],
        ]);
    $oddsService->shouldNotReceive('fuzzyMatchTeams');

    $action = new SyncOddsForGames($oddsService, app(SportsViewCache::class));
    $updated = $action->execute(7);

    $game->refresh();

    expect($updated)->toBe(1)
        ->and($game->odds_api_event_id)->toBe('odds-evt-2')
        ->and($game->odds_updated_at)->not->toBeNull();
});

it('matches mlb odds events when odds commence date and stored utc game date span different calendar days', function () {
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
        'season_type' => '2',
        'game_date' => now()->addDays(2)->toDateString(),
        'game_time' => '00:05:00',
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
                'id' => 'odds-evt-3',
                'home_team' => 'San Francisco Giants',
                'away_team' => 'New York Yankees',
                'commence_time' => now()->addDays(1)->setTime(19, 5)->setTimezone('America/Chicago')->toIso8601String(),
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
    $oddsService->shouldReceive('mappedEspnTeamName')
        ->twice()
        ->andReturnNull();
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
        ->and($game->odds_api_event_id)->toBe('odds-evt-3')
        ->and($game->odds_updated_at)->not->toBeNull();
});
