<?php

use App\Actions\OddsApi\NBA\SyncHistoricalOddsForGames;
use App\Models\GameOddsSnapshot;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Mockery as m;

uses()->group('nba', 'odds');

afterEach(function () {
    m::close();
});

it('records a historical nba odds snapshot without overwriting current odds by default', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Knicks',
        'abbreviation' => 'NYK',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Cleveland',
        'name' => 'Cavaliers',
        'abbreviation' => 'CLE',
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => 2,
        'game_date' => '2025-12-25',
        'game_time' => '17:10:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_api_event_id' => null,
        'odds_data' => ['preserved' => true],
        'odds_updated_at' => now(),
    ]);

    $historicalEvent = [
        'id' => 'f557c9173a46a3c7c87afb4bd68f6644',
        'sport_key' => 'basketball_nba',
        'commence_time' => '2025-12-25T17:10:00Z',
        'home_team' => 'New York Knicks',
        'away_team' => 'Cleveland Cavaliers',
        'bookmakers' => [
            [
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [
                    ['key' => 'h2h', 'outcomes' => []],
                    ['key' => 'spreads', 'outcomes' => []],
                    ['key' => 'totals', 'outcomes' => []],
                ],
            ],
        ],
    ];

    $extractedOdds = [
        'event_id' => $historicalEvent['id'],
        'commence_time' => $historicalEvent['commence_time'],
        'home_team' => 'New York Knicks',
        'away_team' => 'Cleveland Cavaliers',
        'bookmakers' => [
            [
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [
                    ['key' => 'h2h', 'outcomes' => []],
                    ['key' => 'spreads', 'outcomes' => []],
                    ['key' => 'totals', 'outcomes' => []],
                ],
            ],
        ],
        'market_context' => [
            'bookmaker' => 'draftkings',
            'available_markets' => ['h2h', 'spreads', 'totals'],
            'has_h2h' => true,
            'has_spreads' => true,
            'has_totals' => true,
        ],
    ];

    $oddsService = m::mock(OddsApiService::class);
    $oddsService->shouldReceive('getHistoricalOdds')
        ->once()
        ->with('basketball_nba', '2025-12-24T23:10:00Z')
        ->andReturn([
            'timestamp' => '2025-12-24T17:05:37Z',
            'data' => [$historicalEvent],
        ]);
    $oddsService->shouldReceive('fuzzyMatchTeams')
        ->once()
        ->andReturn(true);
    $oddsService->shouldReceive('extractOddsData')
        ->once()
        ->with($historicalEvent)
        ->andReturn($extractedOdds);

    $action = new SyncHistoricalOddsForGames($oddsService, app(SportsViewCache::class));
    $result = $action->executeHistorical(hoursBefore: 24, season: 2025);

    $game->refresh();
    $snapshot = GameOddsSnapshot::query()->first();

    expect($result['processed_games'])->toBe(1)
        ->and($result['matched_games'])->toBe(1)
        ->and($result['created_snapshots'])->toBe(1)
        ->and($result['hydrated_current_games'])->toBe(0)
        ->and($game->odds_data)->toBe(['preserved' => true])
        ->and($game->odds_api_event_id)->toBe($historicalEvent['id'])
        ->and($snapshot)->not->toBeNull()
        ->and($snapshot->sport)->toBe('nba')
        ->and($snapshot->game_table)->toBe('nba_games')
        ->and($snapshot->game_id)->toBe($game->id)
        ->and($snapshot->commence_time?->utc()->toIso8601String())->toBe('2025-12-25T17:10:00+00:00')
        ->and($snapshot->captured_at?->utc()->toIso8601String())->toBe('2025-12-24T17:05:37+00:00');
});

it('can hydrate current odds from a historical snapshot when requested', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Knicks',
        'abbreviation' => 'NYK',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Cleveland',
        'name' => 'Cavaliers',
        'abbreviation' => 'CLE',
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => 2,
        'game_date' => '2025-12-25',
        'game_time' => '17:10:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_api_event_id' => null,
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $historicalEvent = [
        'id' => 'f557c9173a46a3c7c87afb4bd68f6644',
        'sport_key' => 'basketball_nba',
        'commence_time' => '2025-12-25T17:10:00Z',
        'home_team' => 'New York Knicks',
        'away_team' => 'Cleveland Cavaliers',
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
        'event_id' => $historicalEvent['id'],
        'commence_time' => $historicalEvent['commence_time'],
        'home_team' => 'New York Knicks',
        'away_team' => 'Cleveland Cavaliers',
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
    $oddsService->shouldReceive('getHistoricalOdds')
        ->once()
        ->with('basketball_nba', '2025-12-24T23:10:00Z')
        ->andReturn([
            'timestamp' => '2025-12-24T17:05:37Z',
            'data' => [$historicalEvent],
        ]);
    $oddsService->shouldReceive('fuzzyMatchTeams')
        ->once()
        ->andReturn(true);
    $oddsService->shouldReceive('extractOddsData')
        ->once()
        ->with($historicalEvent)
        ->andReturn($extractedOdds);

    $action = new SyncHistoricalOddsForGames($oddsService, app(SportsViewCache::class));
    $result = $action->executeHistorical(hoursBefore: 24, season: 2025, hydrateCurrentWhenEmpty: true);

    $game->refresh();

    expect($result['hydrated_current_games'])->toBe(1)
        ->and($game->odds_data)->toBe($extractedOdds)
        ->and($game->odds_updated_at)->not->toBeNull();
});
