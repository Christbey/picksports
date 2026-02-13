<?php

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses()->group('espn', 'nba');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['espn_id' => '1']);
    $this->awayTeam = Team::factory()->create(['espn_id' => '2']);
});

it('syncs games from ESPN API', function () {
    Http::fake([
        '*sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/2024/types/2/weeks/1*' => Http::response([
            'items' => [
                [
                    'id' => '401585601',
                    'uid' => 's:40~l:46~e:401585601',
                    'date' => '2024-10-24T23:30Z',
                    'name' => 'Atlanta Hawks at Boston Celtics',
                    'shortName' => 'ATL @ BOS',
                    'season' => ['year' => 2024, 'type' => 2],
                    'week' => ['number' => 1],
                    'competitions' => [
                        [
                            'id' => '401585601',
                            'competitors' => [
                                [
                                    'team' => ['id' => '1'],
                                    'homeAway' => 'home',
                                    'score' => 110,
                                ],
                                [
                                    'team' => ['id' => '2'],
                                    'homeAway' => 'away',
                                    'score' => 100,
                                ],
                            ],
                            'status' => ['type' => ['name' => 'STATUS_FINAL']],
                            'venue' => [
                                'fullName' => 'TD Garden',
                                'address' => ['city' => 'Boston', 'state' => 'MA'],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-games', ['season' => 2024, 'week' => 1])
        ->expectsOutput('Dispatching NBA games sync job for Season 2024, Week 1...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Game::count())->toBe(1);

    $game = Game::where('espn_event_id', '401585601')->first();
    expect($game)->not->toBeNull()
        ->season->toBe(2024)
        ->week->toBe(1)
        ->home_team_id->toBe($this->homeTeam->id)
        ->away_team_id->toBe($this->awayTeam->id)
        ->home_score->toBe(110)
        ->away_score->toBe(100);
});

it('handles empty games response', function () {
    Http::fake([
        '*sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/2024/types/2/weeks/1*' => Http::response([
            'items' => [],
        ]),
    ]);

    artisan('espn:sync-nba-games', ['season' => 2024, 'week' => 1])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Game::count())->toBe(0);
});

it('updates existing games instead of creating duplicates', function () {
    Game::factory()->create([
        'espn_event_id' => '401585601',
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'home_score' => 50,
        'away_score' => 50,
    ]);

    Http::fake([
        '*sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/2024/types/2/weeks/1*' => Http::response([
            'items' => [
                [
                    'id' => '401585601',
                    'uid' => 's:40~l:46~e:401585601',
                    'date' => '2024-10-24T23:30Z',
                    'name' => 'Game Name',
                    'shortName' => 'ATL @ BOS',
                    'season' => ['year' => 2024, 'type' => 2],
                    'week' => ['number' => 1],
                    'competitions' => [
                        [
                            'competitors' => [
                                ['team' => ['id' => '1'], 'homeAway' => 'home', 'score' => 110],
                                ['team' => ['id' => '2'], 'homeAway' => 'away', 'score' => 100],
                            ],
                            'status' => ['type' => ['name' => 'STATUS_FINAL']],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-games', ['season' => 2024, 'week' => 1])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Game::count())->toBe(1);
    expect(Game::first()->home_score)->toBe(110);
    expect(Game::first()->away_score)->toBe(100);
});

it('skips games without teams in database', function () {
    Http::fake([
        '*sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/2024/types/2/weeks/1*' => Http::response([
            'items' => [
                [
                    'id' => '401585601',
                    'uid' => 's:40~l:46~e:401585601',
                    'date' => '2024-10-24T23:30Z',
                    'name' => 'Game Name',
                    'shortName' => 'ATL @ BOS',
                    'season' => ['year' => 2024, 'type' => 2],
                    'week' => ['number' => 1],
                    'competitions' => [
                        [
                            'competitors' => [
                                ['team' => ['id' => '999'], 'homeAway' => 'home', 'score' => 110],
                                ['team' => ['id' => '998'], 'homeAway' => 'away', 'score' => 100],
                            ],
                            'status' => ['type' => ['name' => 'STATUS_FINAL']],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-games', ['season' => 2024, 'week' => 1])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Game::count())->toBe(0);
});
