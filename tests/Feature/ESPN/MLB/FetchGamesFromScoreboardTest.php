<?php

use App\Actions\MLB\UpdateLivePrediction;
use App\Jobs\ESPN\MLB\FetchGamesFromScoreboard;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use Illuminate\Support\Facades\Http;

uses()->group('espn', 'mlb');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['espn_id' => '26']);
    $this->awayTeam = Team::factory()->create(['espn_id' => '21']);

    $predictionAction = Mockery::mock(UpdateLivePrediction::class);
    $predictionAction->shouldReceive('execute')->once()->andReturnNull();
    $this->app->instance(UpdateLivePrediction::class, $predictionAction);
});

afterEach(function () {
    Mockery::close();
});

it('stores probable pitcher ids from the mlb scoreboard job', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/baseball/mlb/scoreboard*dates=20260403*' => Http::response([
            'events' => [[
                'id' => '401814795',
                'uid' => 's:1~l:10~e:401814795',
                'date' => '2026-04-03T17:05Z',
                'name' => 'New York Mets at San Francisco Giants',
                'shortName' => 'NYM @ SF',
                'season' => ['year' => 2026, 'type' => 2],
                'competitions' => [[
                    'id' => '401814795',
                    'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
                    'venue' => [
                        'fullName' => 'Oracle Park',
                        'address' => ['city' => 'San Francisco', 'state' => 'CA'],
                    ],
                    'competitors' => [
                        [
                            'homeAway' => 'home',
                            'team' => ['id' => '26'],
                            'probables' => [[
                                'name' => 'probableStartingPitcher',
                                'playerId' => '34973',
                            ]],
                        ],
                        [
                            'homeAway' => 'away',
                            'team' => ['id' => '21'],
                            'probables' => [[
                                'name' => 'probableStartingPitcher',
                                'athlete' => ['id' => '4433874'],
                            ]],
                        ],
                    ],
                ]],
            ]],
        ]),
    ]);

    (new FetchGamesFromScoreboard('20260403'))->handle();

    $game = Game::query()->where('espn_event_id', '401814795')->first();

    expect($game)->not->toBeNull()
        ->and($game->home_team_id)->toBe($this->homeTeam->id)
        ->and($game->away_team_id)->toBe($this->awayTeam->id)
        ->and($game->probable_home_pitcher_espn_id)->toBe('34973')
        ->and($game->probable_away_pitcher_espn_id)->toBe('4433874');
});

it('does not erase existing probable pitchers when a scoreboard payload omits them', function () {
    $game = Game::factory()->create([
        'espn_event_id' => '401814799',
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'probable_home_pitcher_espn_id' => 'existing-home',
        'probable_away_pitcher_espn_id' => 'existing-away',
    ]);

    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/baseball/mlb/scoreboard*dates=20260404*' => Http::response([
            'events' => [[
                'id' => '401814799',
                'date' => '2026-04-04T17:05Z',
                'name' => 'Away at Home',
                'shortName' => 'AWY @ HME',
                'season' => ['year' => 2026, 'type' => 2],
                'competitions' => [[
                    'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
                    'competitors' => [
                        ['homeAway' => 'home', 'team' => ['id' => '26']],
                        ['homeAway' => 'away', 'team' => ['id' => '21']],
                    ],
                ]],
            ]],
        ]),
    ]);

    (new FetchGamesFromScoreboard('20260404'))->handle();

    expect($game->refresh()->probable_home_pitcher_espn_id)->toBe('existing-home')
        ->and($game->probable_away_pitcher_espn_id)->toBe('existing-away');
});

it('persists live inning and inning state from the mlb scoreboard job', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/baseball/mlb/scoreboard*dates=20260403*' => Http::response([
            'events' => [[
                'id' => '401814796',
                'uid' => 's:1~l:10~e:401814796',
                'date' => '2026-04-03T17:05Z',
                'name' => 'New York Mets at San Francisco Giants',
                'shortName' => 'NYM @ SF',
                'season' => ['year' => 2026, 'type' => 2],
                'competitions' => [[
                    'id' => '401814796',
                    'status' => [
                        'type' => ['name' => 'STATUS_IN_PROGRESS'],
                        'period' => 5,
                        'displayClock' => 'Top 5th',
                    ],
                    'competitors' => [
                        ['homeAway' => 'home', 'team' => ['id' => '26']],
                        ['homeAway' => 'away', 'team' => ['id' => '21']],
                    ],
                ]],
            ]],
        ]),
    ]);

    (new FetchGamesFromScoreboard('20260403'))->handle();

    $game = Game::query()->where('espn_event_id', '401814796')->first();

    expect($game)->not->toBeNull()
        ->and($game->inning)->toBe(5)
        ->and($game->inning_half)->toBe('Top 5th')
        ->and($game->inning_state)->toBe('Top 5th');
});

it('stores west coast night scoreboard games on the local venue date', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/baseball/mlb/scoreboard*dates=20260601*' => Http::response([
            'events' => [[
                'id' => '401814797',
                'uid' => 's:1~l:10~e:401814797',
                'date' => '2026-06-01T02:10:00Z',
                'name' => 'New York Mets at San Francisco Giants',
                'shortName' => 'NYM @ SF',
                'season' => ['year' => 2026, 'type' => 2],
                'competitions' => [[
                    'id' => '401814797',
                    'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
                    'venue' => [
                        'fullName' => 'Oracle Park',
                        'address' => ['city' => 'San Francisco', 'state' => 'CA'],
                    ],
                    'competitors' => [
                        ['homeAway' => 'home', 'team' => ['id' => '26']],
                        ['homeAway' => 'away', 'team' => ['id' => '21']],
                    ],
                ]],
            ]],
        ]),
    ]);

    (new FetchGamesFromScoreboard('20260601'))->handle();

    $game = Game::query()->where('espn_event_id', '401814797')->first();

    expect($game)->not->toBeNull()
        ->and($game->game_date?->toDateString())->toBe('2026-05-31')
        ->and($game->game_time)->toBe('19:10:00');
});
