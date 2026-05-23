<?php

use App\Actions\OddsApi\NBA\SyncOddsForGames;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Mockery as m;

uses()->group('nba', 'odds');

afterEach(function () {
    m::close();
});

it('matches nba postseason odds events when using the regular nba odds sport key', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Cleveland',
        'name' => 'Cavaliers',
        'abbreviation' => 'CLE',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Knicks',
        'abbreviation' => 'NY',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '3',
        'game_date' => now()->addDay()->toDateString(),
        'game_time' => '19:00:00',
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
        ->with('basketball_nba')
        ->andReturn([
            [
                'id' => 'nba-postseason-odds-1',
                'home_team' => 'Cleveland Cavaliers',
                'away_team' => 'New York Knicks',
                'commence_time' => now()->addDay()->setTime(19, 0)->toIso8601String(),
                'bookmakers' => [
                    [
                        'key' => 'draftkings',
                        'markets' => [
                            [
                                'key' => 'spreads',
                                'outcomes' => [
                                    ['name' => 'Cleveland Cavaliers', 'point' => -2.5, 'price' => -110],
                                    ['name' => 'New York Knicks', 'point' => 2.5, 'price' => -110],
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
        ->andReturnTrue();
    $oddsService->shouldReceive('extractOddsData')
        ->once()
        ->andReturn([
            'home_team' => 'Cleveland Cavaliers',
            'away_team' => 'New York Knicks',
            'bookmakers' => [
                ['key' => 'draftkings', 'markets' => [['key' => 'spreads']]],
            ],
        ]);

    $updated = (new SyncOddsForGames($oddsService, app(SportsViewCache::class)))->execute(7);

    $game->refresh();

    expect($updated)->toBe(1)
        ->and($game->odds_api_event_id)->toBe('nba-postseason-odds-1')
        ->and($game->odds_updated_at)->not->toBeNull()
        ->and(data_get($game->odds_data, 'bookmakers.0.key'))->toBe('draftkings');
});
