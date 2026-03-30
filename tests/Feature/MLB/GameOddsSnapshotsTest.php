<?php

use App\Actions\OddsApi\MLB\SyncOddsForGames;
use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Mockery as m;

uses()->group('mlb', 'odds');

afterEach(function () {
    m::close();
});

it('records a historical odds snapshot while keeping latest odds on the game', function () {
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

    $extractedOdds = [
        'event_id' => 'odds-evt-1',
        'commence_time' => now()->addDays(2)->setTime(19, 5)->toIso8601String(),
        'home_team' => 'San Francisco Giants',
        'away_team' => 'New York Yankees',
        'bookmakers' => [
            [
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [
                    ['key' => 'h2h', 'outcomes' => []],
                    ['key' => 'spreads', 'outcomes' => []],
                ],
            ],
        ],
        'market_context' => [
            'bookmaker' => 'draftkings',
            'available_markets' => ['h2h', 'spreads'],
            'has_h2h' => true,
            'has_spreads' => true,
            'has_totals' => false,
        ],
    ];

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
                        'title' => 'DraftKings',
                        'markets' => [
                            ['key' => 'h2h', 'outcomes' => []],
                            ['key' => 'spreads', 'outcomes' => []],
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
        ->andReturn($extractedOdds);

    $action = new SyncOddsForGames($oddsService, app(SportsViewCache::class));
    $updated = $action->execute(7);

    $game->refresh();
    $snapshot = GameOddsSnapshot::query()->first();

    expect($updated)->toBe(1)
        ->and($game->odds_api_event_id)->toBe('odds-evt-1')
        ->and($game->odds_updated_at)->not->toBeNull()
        ->and($snapshot)->not->toBeNull()
        ->and($snapshot->sport)->toBe('mlb')
        ->and($snapshot->game_table)->toBe('mlb_games')
        ->and($snapshot->game_id)->toBe($game->id)
        ->and($snapshot->bookmaker_key)->toBe('draftkings')
        ->and(data_get($snapshot->odds_data, 'market_context.has_spreads'))->toBeTrue();
});

it('does not create duplicate snapshots when the odds payload has not changed', function () {
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

    $event = [
        'id' => 'odds-evt-1',
        'home_team' => 'San Francisco Giants',
        'away_team' => 'New York Yankees',
        'commence_time' => now()->addDays(2)->setTime(19, 5)->toIso8601String(),
        'bookmakers' => [
            [
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [
                    ['key' => 'h2h', 'outcomes' => []],
                ],
            ],
        ],
    ];

    $extractedOdds = [
        'event_id' => 'odds-evt-1',
        'commence_time' => $event['commence_time'],
        'home_team' => 'San Francisco Giants',
        'away_team' => 'New York Yankees',
        'bookmakers' => [
            [
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [
                    ['key' => 'h2h', 'outcomes' => []],
                ],
            ],
        ],
        'market_context' => [
            'bookmaker' => 'draftkings',
            'available_markets' => ['h2h'],
            'has_h2h' => true,
            'has_spreads' => false,
            'has_totals' => false,
        ],
    ];

    $oddsService = m::mock(OddsApiService::class);
    $oddsService->shouldReceive('getOdds')
        ->twice()
        ->with('baseball_mlb')
        ->andReturn([$event]);
    $oddsService->shouldReceive('mappedEspnTeamName')
        ->times(4)
        ->andReturnNull();
    $oddsService->shouldReceive('fuzzyMatchTeams')
        ->twice()
        ->andReturn(true);
    $oddsService->shouldReceive('extractOddsData')
        ->twice()
        ->andReturn($extractedOdds);

    $action = new SyncOddsForGames($oddsService, app(SportsViewCache::class));
    $action->execute(7);
    $action->execute(7);

    expect(GameOddsSnapshot::query()->count())->toBe(1);
});
