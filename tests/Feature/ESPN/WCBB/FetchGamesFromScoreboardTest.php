<?php

use App\Actions\WCBB\UpdateLivePrediction;
use App\Jobs\ESPN\WCBB\FetchGamesFromScoreboard;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use Illuminate\Support\Facades\Http;

uses()->group('espn', 'wcbb');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['espn_id' => '1']);
    $this->awayTeam = Team::factory()->create(['espn_id' => '2']);

    $predictionAction = \Mockery::mock(UpdateLivePrediction::class);
    $predictionAction->shouldReceive('execute')->once()->andReturnNull();
    $this->app->instance(UpdateLivePrediction::class, $predictionAction);
});

afterEach(function () {
    \Mockery::close();
});

it('syncs WCBB games from the scoreboard job without constructor type errors', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/womens-college-basketball/scoreboard*dates=20260130*' => Http::response([
            'events' => [
                [
                    'id' => '401999001',
                    'uid' => 's:40~l:50~e:401999001',
                    'date' => '2026-01-30T23:30Z',
                    'name' => 'Away Team at Home Team',
                    'shortName' => 'AWAY @ HOME',
                    'season' => ['year' => 2026, 'type' => 2],
                    'week' => ['number' => 15],
                    'competitions' => [
                        [
                            'id' => '401999001',
                            'competitors' => [
                                [
                                    'team' => ['id' => '1'],
                                    'homeAway' => 'home',
                                    'score' => '78',
                                ],
                                [
                                    'team' => ['id' => '2'],
                                    'homeAway' => 'away',
                                    'score' => '65',
                                ],
                            ],
                            'status' => ['type' => ['name' => 'STATUS_FINAL']],
                            'venue' => [
                                'fullName' => 'Test Arena',
                                'address' => ['city' => 'Chicago', 'state' => 'IL'],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    (new FetchGamesFromScoreboard('20260130'))->handle();

    $game = Game::query()->where('espn_event_id', '401999001')->first();

    expect($game)->not->toBeNull()
        ->home_team_id->toBe($this->homeTeam->id)
        ->away_team_id->toBe($this->awayTeam->id)
        ->home_score->toBe(78)
        ->away_score->toBe(65);
});
