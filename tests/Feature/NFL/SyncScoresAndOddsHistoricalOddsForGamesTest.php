<?php

use App\Actions\ScoresAndOdds\NFL\SyncHistoricalOddsForGames;
use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\OddsApi\OddsApiService;
use App\Services\ScoresAndOdds\NFL\HistoricalOddsScraper;
use App\Support\SportsViewCache;
use Mockery as m;

uses()->group('nfl', 'odds');

afterEach(function () {
    m::close();
});

it('hydrates historical nfl odds from scores and odds into snapshots and game odds data', function () {
    config()->set('app.timezone', 'UTC');

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
        'season' => 2025,
        'season_type' => 2,
        'week' => 1,
        'game_date' => '2025-09-07',
        'game_time' => '20:25:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $scraper = m::mock(HistoricalOddsScraper::class);
    $scraper->shouldReceive('fetchDate')
        ->once()
        ->with('2025-09-07')
        ->andReturn([
            [
                'id' => '1234567',
                'commence_time' => '2025-09-07T20:25:00Z',
                'home_team' => 'Chiefs',
                'away_team' => 'Broncos',
            ],
        ]);
    $scraper->shouldReceive('fetchEventDetails')
        ->once()
        ->with('1234567')
        ->andReturn([
            'id' => '1234567',
            'commence_time' => '2025-09-07T20:25:00Z',
            'home_team' => 'Chiefs',
            'away_team' => 'Broncos',
            'odds_data' => [
                'event_id' => 'scoresandodds:1234567',
                'commence_time' => '2025-09-07T20:25:00Z',
                'home_team' => 'Chiefs',
                'away_team' => 'Broncos',
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
            ],
        ]);

    $action = new SyncHistoricalOddsForGames(
        $scraper,
        app(OddsApiService::class),
        app(SportsViewCache::class),
        app(GameOddsSnapshotRecorder::class),
    );

    $result = $action->execute(season: 2025, hydrateCurrentWhenEmpty: true);

    $game->refresh();
    $snapshot = GameOddsSnapshot::query()->first();

    expect($result['processed_games'])->toBe(1)
        ->and($result['matched_games'])->toBe(1)
        ->and($result['created_snapshots'])->toBe(1)
        ->and($result['hydrated_current_games'])->toBe(1)
        ->and($snapshot)->not->toBeNull()
        ->and($snapshot->sport)->toBe('nfl')
        ->and($snapshot->source)->toBe('scores_and_odds')
        ->and(data_get($game->odds_data, 'bookmakers.0.key'))->toBe('draftkings')
        ->and(data_get($game->odds_data, 'market_context.has_h2h'))->toBeTrue()
        ->and($game->odds_updated_at)->not->toBeNull();
});
