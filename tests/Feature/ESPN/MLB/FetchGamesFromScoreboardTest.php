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
