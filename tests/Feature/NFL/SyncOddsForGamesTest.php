<?php

use App\Actions\OddsApi\NFL\SyncOddsForGames;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Carbon\CarbonImmutable;
use Mockery as m;

uses()->group('nfl', 'odds');

afterEach(function () {
    m::close();
});

it('matches an nfl event on the local final day when its stored utc date is the next day', function () {
    $this->travelTo('2026-09-06 20:00:00');

    $homeTeam = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Giants',
        'abbreviation' => 'NYG',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Dallas',
        'name' => 'Cowboys',
        'abbreviation' => 'DAL',
    ]);

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'week' => 1,
        'game_date' => '2026-09-14',
        'game_time' => '00:20:00',
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
        ->with('americanfootball_nfl')
        ->andReturn([
            [
                'id' => 'nfl-dal-nyg',
                'home_team' => 'New York Giants',
                'away_team' => 'Dallas Cowboys',
                'commence_time' => CarbonImmutable::parse('2026-09-14 00:20:00', 'UTC')->toIso8601String(),
                'bookmakers' => [
                    [
                        'key' => 'draftkings',
                        'markets' => [
                            [
                                'key' => 'h2h',
                                'outcomes' => [
                                    ['name' => 'New York Giants', 'price' => 110],
                                    ['name' => 'Dallas Cowboys', 'price' => -130],
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
            'home_team' => 'New York Giants',
            'away_team' => 'Dallas Cowboys',
            'bookmakers' => [
                ['key' => 'draftkings', 'markets' => [['key' => 'h2h']]],
            ],
        ]);

    $updated = (new SyncOddsForGames($oddsService, app(SportsViewCache::class)))->execute(7);

    $game->refresh();

    expect($updated)->toBe(1)
        ->and($game->odds_api_event_id)->toBe('nfl-dal-nyg')
        ->and($game->odds_updated_at)->not->toBeNull();
});
