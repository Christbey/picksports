<?php

use App\Actions\OddsApi\CFB\SyncOddsForGames;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\GameOddsSnapshot;
use App\Models\OddsApiTeamMapping;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Carbon\CarbonInterface;
use Mockery as m;

uses()->group('cfb', 'odds');

afterEach(function () {
    m::close();
});

it('matches cfb odds using school and full school mascot names', function () {
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

it('does not assign another school matchup based on mascot or abbreviation fragments', function (
    string $homeSchool, string $homeMascot, string $homeAbbreviation,
    string $awaySchool, string $awayMascot, string $awayAbbreviation,
    string $providerHome, string $providerAway,
) {
    $start = now('UTC')->addDay()->setTime(17, 0);
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create(['school' => $homeSchool, 'mascot' => $homeMascot, 'abbreviation' => $homeAbbreviation])->id,
        'away_team_id' => Team::factory()->create(['school' => $awaySchool, 'mascot' => $awayMascot, 'abbreviation' => $awayAbbreviation])->id,
        'game_date' => $start->toDateString(), 'game_time' => $start->format('H:i:s'),
        'status' => 'STATUS_SCHEDULED', 'odds_api_event_id' => null, 'odds_data' => null,
    ]);
    $service = m::mock(OddsApiService::class)->makePartial();
    $service->shouldReceive('getOdds')->once()->with('americanfootball_ncaaf')
        ->andReturn([cfbOddsEvent('unrelated-event', $providerHome, $providerAway, $start)]);
    expect((new SyncOddsForGames($service, app(SportsViewCache::class)))->execute(7))->toBe(0)
        ->and($game->fresh()->odds_api_event_id)->toBeNull()
        ->and(GameOddsSnapshot::query()->count())->toBe(0);
})->with([
    'shared mascot and mascot substring' => ['New Hampshire', 'Wildcats', 'UNH', 'Monmouth', 'Hawks', 'MONM', 'Kansas State Wildcats', 'Kansas Jayhawks'],
    'missing school identity' => ['', 'Wildcats', 'UNH', '', 'Hawks', 'MONM', 'Kansas State Wildcats', 'Kansas Jayhawks'],
    'abbreviation substring' => ['Texas', 'Longhorns', 'UT', 'Oklahoma', 'Sooners', 'OU', 'Utah Utes', 'Houston Cougars'],
]);

it('selects the correct cfb schools even when a mascot collision is scanned first', function (bool $manualMappings) {
    $start = now('UTC')->addDay()->setTime(17, 0);
    $wrong = Game::factory()->create([
        'home_team_id' => Team::factory()->create(['school' => 'New Hampshire', 'mascot' => 'Wildcats', 'abbreviation' => 'UNH'])->id,
        'away_team_id' => Team::factory()->create(['school' => 'Monmouth', 'mascot' => 'Hawks', 'abbreviation' => 'MONM'])->id,
        'game_date' => $start->toDateString(), 'game_time' => $start->format('H:i:s'),
        'status' => 'STATUS_SCHEDULED', 'odds_api_event_id' => null, 'odds_data' => null,
    ]);
    $correct = Game::factory()->create([
        'home_team_id' => Team::factory()->create(['school' => 'Kansas State', 'mascot' => 'Wildcats', 'abbreviation' => 'KSU'])->id,
        'away_team_id' => Team::factory()->create(['school' => 'Kansas', 'mascot' => 'Jayhawks', 'abbreviation' => 'KU'])->id,
        'game_date' => $start->toDateString(), 'game_time' => $start->format('H:i:s'),
        'status' => 'STATUS_SCHEDULED', 'odds_api_event_id' => null, 'odds_data' => null,
    ]);
    if ($manualMappings) {
        foreach (['Kansas State Wildcats', 'Kansas Jayhawks'] as $name) {
            OddsApiTeamMapping::query()->create([
                'sport' => 'americanfootball_ncaaf', 'odds_api_team_name' => $name, 'espn_team_name' => $name,
            ]);
        }
    }
    $service = m::mock(OddsApiService::class)->makePartial();
    $service->shouldReceive('getOdds')->once()->with('americanfootball_ncaaf')
        ->andReturn([cfbOddsEvent('kansas-rivalry', 'Kansas State Wildcats', 'Kansas Jayhawks', $start)]);
    expect((new SyncOddsForGames($service, app(SportsViewCache::class)))->execute(7))->toBe(1)
        ->and($wrong->fresh()->odds_api_event_id)->toBeNull()
        ->and($correct->fresh()->odds_api_event_id)->toBe('kansas-rivalry')
        ->and(GameOddsSnapshot::query()->value('game_id'))->toBe($correct->id);
})->with(['fuzzy matching' => false, 'explicit provider aliases' => true]);

it('preserves full school aliases without changing stored provider labels', function (string $school, string $mascot, string $providerHome) {
    $start = now('UTC')->addDay()->setTime(17, 0);
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create(['school' => $school, 'mascot' => $mascot])->id,
        'away_team_id' => Team::factory()->create(['school' => 'New Mexico', 'mascot' => 'Lobos'])->id,
        'game_date' => $start->toDateString(), 'game_time' => $start->format('H:i:s'),
        'status' => 'STATUS_SCHEDULED', 'odds_api_event_id' => null, 'odds_data' => null,
    ]);
    $service = m::mock(OddsApiService::class)->makePartial();
    $service->shouldReceive('getOdds')->once()->with('americanfootball_ncaaf')
        ->andReturn([cfbOddsEvent('school-alias', $providerHome, 'New Mexico Lobos', $start)]);
    expect((new SyncOddsForGames($service, app(SportsViewCache::class)))->execute(7))->toBe(1)
        ->and($game->fresh()->odds_api_event_id)->toBe('school-alias')
        ->and(data_get($game->fresh()->odds_data, 'home_team'))->toBe($providerHome);
})->with([
    'App State' => ['App State', 'Mountaineers', 'Appalachian State Mountaineers'],
    'UMass' => ['Massachusetts', 'Minutemen', 'UMass Minutemen'],
    'Hawaii' => ["Hawai'i", 'Rainbow Warriors', 'Hawaii Rainbow Warriors'],
    'San Jose' => ['San José State', 'Spartans', 'San Jose State Spartans'],
]);
