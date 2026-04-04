<?php

use App\Actions\OddsApi\MLB\SyncHistoricalOddsForGames;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Illuminate\Support\Carbon;
use Mockery as m;

uses()->group('mlb', 'odds');

afterEach(function () {
    Carbon::setTestNow();
    m::close();
});

it('queries only the requested historical snapshot offset', function () {
    config()->set('app.timezone', 'UTC');
    Carbon::setTestNow('2025-04-05 12:00:00 UTC');

    $homeTeam = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Cubs',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'St. Louis',
        'name' => 'Cardinals',
    ]);

    Game::factory()->create([
        'season' => 2025,
        'season_type' => 2,
        'game_date' => '2025-04-02',
        'game_time' => '19:05:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'odds_api_event_id' => null,
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $oddsService = m::mock(OddsApiService::class);
    $oddsService->shouldReceive('getHistoricalOdds')
        ->once()
        ->with('baseball_mlb', '2025-04-01T19:05:00Z')
        ->andReturn(['data' => []]);

    $action = new SyncHistoricalOddsForGames($oddsService, app(SportsViewCache::class));
    $result = $action->executeHistorical(hoursBefore: 24, season: 2025);

    expect($result['processed_games'])->toBe(1)
        ->and($result['matched_games'])->toBe(0)
        ->and($result['created_snapshots'])->toBe(0)
        ->and($result['hydrated_current_games'])->toBe(0)
        ->and($result['unmatched_games'])->toHaveCount(1);
});
