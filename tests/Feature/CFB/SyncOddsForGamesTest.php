<?php

use App\Actions\OddsApi\CFB\SyncOddsForGames;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Carbon\CarbonInterface;
use Mockery as m;

uses()->group('cfb', 'odds');

afterEach(function () {
    m::close();
});

it('matches cfb odds using school mascot and abbreviation names', function () {
    $homeTeam = Team::factory()->create([
        'school' => 'Alabama',
        'mascot' => 'Crimson Tide',
        'abbreviation' => 'ALA',
    ]);
    $awayTeam = Team::factory()->create([
        'school' => 'East Carolina',
        'mascot' => 'Pirates',
        'abbreviation' => 'ECU',
    ]);
    $start = now('UTC')->addDay()->setTime(16, 0);

    $game = Game::factory()->create([
        'season' => 2026,
        'week' => 1,
        'game_date' => $start->toDateString(),
        'game_time' => $start->format('H:i:s'),
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
        ->with('americanfootball_ncaaf')
        ->andReturn([
            cfbOddsEvent(
                id: 'cfb-alabama-ecu',
                home: 'Alabama Crimson Tide',
                away: 'East Carolina Pirates',
                startsAt: $start,
            ),
        ]);

    $updated = (new SyncOddsForGames($oddsService, app(SportsViewCache::class)))->execute(7);

    $game->refresh();

    expect($updated)->toBe(1)
        ->and($game->odds_api_event_id)->toBe('cfb-alabama-ecu')
        ->and(data_get($game->odds_data, 'home_team'))->toBe('Alabama Crimson Tide')
        ->and($game->odds_updated_at)->not->toBeNull();
});

it('treats stored cfb game times as utc when enforcing match tolerance', function () {
    config()->set('app.timezone', 'America/Chicago');

    $homeTeam = Team::factory()->create([
        'school' => 'Alabama',
        'mascot' => 'Crimson Tide',
        'abbreviation' => 'ALA',
    ]);
    $awayTeam = Team::factory()->create([
        'school' => 'East Carolina',
        'mascot' => 'Pirates',
        'abbreviation' => 'ECU',
    ]);
    $gameStart = now('UTC')->addDays(2)->startOfDay()->addHours(20);
    $providerStart = $gameStart->copy()->addHours(10);

    $game = Game::factory()->create([
        'game_date' => $gameStart->toDateString(),
        'game_time' => $gameStart->format('H:i:s'),
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
        ->with('americanfootball_ncaaf')
        ->andReturn([
            cfbOddsEvent(
                id: 'cfb-outside-tolerance',
                home: 'Alabama Crimson Tide',
                away: 'East Carolina Pirates',
                startsAt: $providerStart,
            ),
        ]);

    $updated = (new SyncOddsForGames($oddsService, app(SportsViewCache::class)))->execute(7);

    expect($updated)->toBe(0)
        ->and($game->fresh()->odds_api_event_id)->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function cfbOddsEvent(string $id, string $home, string $away, CarbonInterface $startsAt): array
{
    return [
        'id' => $id,
        'home_team' => $home,
        'away_team' => $away,
        'commence_time' => $startsAt->toIso8601String(),
        'bookmakers' => [
            [
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [
                    [
                        'key' => 'h2h',
                        'outcomes' => [
                            ['name' => $home, 'price' => -120],
                            ['name' => $away, 'price' => 100],
                        ],
                    ],
                ],
            ],
        ],
    ];
}
