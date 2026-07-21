<?php

use App\Models\NFL\Coach;
use App\Models\NFL\Team;
use App\Models\NFL\TeamCoachSeason;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses()->group('espn', 'nfl');

it('syncs nfl coach season assignments from ESPN season coaches', function () {
    Team::factory()->create([
        'espn_id' => '22',
        'abbreviation' => 'KC',
        'location' => 'Kansas City',
        'name' => 'Chiefs',
    ]);

    Http::fake([
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2026/coaches/9001*' => Http::response([
            '$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2026/coaches/9001?lang=en&region=us',
            'id' => '9001',
            'uid' => 's:20~l:28~co:9001',
            'firstName' => 'Andy',
            'lastName' => 'Reid',
            'displayName' => 'Andy Reid',
            'shortName' => 'A. Reid',
            'experience' => 27,
            'team' => [
                '$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2026/teams/22?lang=en&region=us',
            ],
            'careerRecords' => [
                [
                    '$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/nfl/coaches/9001/record/2?lang=en&region=us',
                ],
            ],
            'records' => [
                [
                    'record' => [
                        '$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2026/types/2/coaches/9001/record?lang=en&region=us',
                    ],
                ],
            ],
        ]),
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2026/types/2/coaches/9001/record*' => Http::response([
            'summary' => '12-5-0',
            'displayValue' => '12-5-0',
            'stats' => [
                ['type' => 'wins', 'value' => 12],
                ['type' => 'losses', 'value' => 5],
                ['type' => 'ties', 'value' => 0],
            ],
        ]),
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2026/coaches*' => Http::response([
            'count' => 1,
            'pageIndex' => 1,
            'pageSize' => 50,
            'pageCount' => 1,
            'items' => [
                [
                    '$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2026/coaches/9001?lang=en&region=us',
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nfl-coaches', ['--season' => 2026])
        ->assertExitCode(0);

    $coach = Coach::query()->where('espn_id', '9001')->firstOrFail();
    $season = TeamCoachSeason::query()->with(['coach', 'team'])->firstOrFail();

    expect($coach->display_name)->toBe('Andy Reid')
        ->and($season->season)->toBe(2026)
        ->and($season->role)->toBe('head_coach')
        ->and($season->coach->espn_id)->toBe('9001')
        ->and($season->team->abbreviation)->toBe('KC')
        ->and($season->regular_season_record['wins'])->toBe(12)
        ->and($season->regular_season_record['losses'])->toBe(5)
        ->and($season->regular_season_record['ties'])->toBe(0);
});

it('uses exact head coach cards from official roster fallback', function () {
    config()->set('nfl.season.default', 2026);
    config()->set('espn.leagues.nfl.official_coach_roster_urls', [
        'ARI' => 'https://cardinals.test/team/coaches-roster/',
    ]);

    Team::factory()->create([
        'espn_id' => '22',
        'abbreviation' => 'ARI',
        'location' => 'Arizona',
        'name' => 'Cardinals',
    ]);

    Http::fake([
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2026/coaches*' => Http::response([
            'count' => 0,
            'pageIndex' => 1,
            'pageSize' => 50,
            'pageCount' => 1,
            'items' => [],
        ]),
        'https://cardinals.test/team/coaches-roster/' => Http::response('
            <div class="d3-o-media-object__body">
                <h5 class="d3-o-media-object__roofline">Assistant Head Coach/Passing Game Coordinator</h5>
                <h3 class="d3-o-media-object__title">Mike LaFleur</h3>
            </div>
            <div class="d3-o-media-object__body">
                <h5 class="d3-o-media-object__roofline">Head Coach</h5>
                <h3 class="d3-o-media-object__title">Jonathan Gannon</h3>
            </div>
        '),
    ]);

    artisan('espn:sync-nfl-coaches', ['--season' => 2026])
        ->assertExitCode(0);

    $season = TeamCoachSeason::query()->with(['coach', 'team'])->firstOrFail();

    expect($season->team->abbreviation)->toBe('ARI')
        ->and($season->coach->display_name)->toBe('Jonathan Gannon')
        ->and($season->source_ref)->toBe('https://cardinals.test/team/coaches-roster/');
});
