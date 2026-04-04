<?php

use App\Actions\ScoresAndOdds\MLB\SyncHistoricalOddsForGames;
use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\OddsApi\OddsApiService;
use App\Services\ScoresAndOdds\MLB\HistoricalOddsScraper;
use App\Support\SportsViewCache;
use Mockery as m;

uses()->group('mlb', 'odds');

afterEach(function () {
    m::close();
});

it('hydrates historical mlb odds from scores and odds into snapshots and game odds data', function () {
    config()->set('app.timezone', 'UTC');

    $homeTeam = Team::factory()->create([
        'location' => 'Milwaukee',
        'name' => 'Brewers',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Cincinnati',
        'name' => 'Reds',
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => 2,
        'game_date' => '2025-04-03',
        'game_time' => '23:40:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $scraper = m::mock(HistoricalOddsScraper::class);
    $scraper->shouldReceive('fetchDate')
        ->once()
        ->with('2025-04-03')
        ->andReturn([
            [
                'id' => '7528700',
                'commence_time' => '2025-04-03T23:40:00Z',
                'home_team' => 'Brewers',
                'away_team' => 'Reds',
            ],
        ]);
    $scraper->shouldReceive('fetchEventDetails')
        ->once()
        ->with('7528700')
        ->andReturn([
            'id' => '7528700',
            'commence_time' => '2025-04-03T23:40:00Z',
            'home_team' => 'Brewers',
            'away_team' => 'Reds',
            'odds_data' => [
                'event_id' => 'scoresandodds:7528700',
                'commence_time' => '2025-04-03T23:40:00Z',
                'home_team' => 'Brewers',
                'away_team' => 'Reds',
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
        ->and($snapshot->source)->toBe('scores_and_odds')
        ->and(data_get($game->odds_data, 'bookmakers.0.key'))->toBe('draftkings')
        ->and($game->odds_updated_at)->not->toBeNull();
});
