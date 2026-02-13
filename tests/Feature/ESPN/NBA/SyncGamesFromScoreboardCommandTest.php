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

it('syncs games from ESPN scoreboard API for a specific date', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/scoreboard?dates=20260130*' => Http::response([
            'events' => [
                [
                    'id' => '401585601',
                    'uid' => 's:40~l:46~e:401585601',
                    'date' => '2026-01-30T23:30Z',
                    'name' => 'Atlanta Hawks at Boston Celtics',
                    'shortName' => 'ATL @ BOS',
                    'season' => ['year' => 2026, 'type' => 2],
                    'week' => ['number' => 15],
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

    artisan('espn:sync-nba-games-scoreboard', ['date' => '20260130'])
        ->expectsOutput('Dispatching NBA games scoreboard sync job for date 20260130...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Game::count())->toBe(1);

    $game = Game::where('espn_event_id', '401585601')->first();
    expect($game)->not->toBeNull()
        ->season->toBe(2026)
        ->week->toBe(15)
        ->home_team_id->toBe($this->homeTeam->id)
        ->away_team_id->toBe($this->awayTeam->id)
        ->home_score->toBe(110)
        ->away_score->toBe(100);
});

it('syncs games for today when no date is provided', function () {
    $today = date('Ymd');

    Http::fake([
        "*site.api.espn.com/apis/site/v2/sports/basketball/nba/scoreboard?dates={$today}*" => Http::response([
            'events' => [
                [
                    'id' => '401585602',
                    'uid' => 's:40~l:46~e:401585602',
                    'date' => date('Y-m-d').'T23:30Z',
                    'name' => 'Game Today',
                    'shortName' => 'ATL @ BOS',
                    'season' => ['year' => 2026, 'type' => 2],
                    'week' => ['number' => 15],
                    'competitions' => [
                        [
                            'competitors' => [
                                ['team' => ['id' => '1'], 'homeAway' => 'home', 'score' => 95],
                                ['team' => ['id' => '2'], 'homeAway' => 'away', 'score' => 90],
                            ],
                            'status' => ['type' => ['name' => 'STATUS_FINAL']],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-games-scoreboard')
        ->expectsOutput("Dispatching NBA games scoreboard sync job for date {$today}...")
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Game::count())->toBe(1);
    expect(Game::first()->espn_event_id)->toBe('401585602');
});

it('handles empty scoreboard response', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/scoreboard?dates=20260130*' => Http::response([
            'events' => [],
        ]),
    ]);

    artisan('espn:sync-nba-games-scoreboard', ['date' => '20260130'])
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
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/scoreboard?dates=20260130*' => Http::response([
            'events' => [
                [
                    'id' => '401585601',
                    'uid' => 's:40~l:46~e:401585601',
                    'date' => '2026-01-30T23:30Z',
                    'name' => 'Game Name',
                    'shortName' => 'ATL @ BOS',
                    'season' => ['year' => 2026, 'type' => 2],
                    'week' => ['number' => 15],
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

    artisan('espn:sync-nba-games-scoreboard', ['date' => '20260130'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Game::count())->toBe(1);
    expect(Game::first()->home_score)->toBe(110);
    expect(Game::first()->away_score)->toBe(100);
});

it('skips games without teams in database', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/scoreboard?dates=20260130*' => Http::response([
            'events' => [
                [
                    'id' => '401585601',
                    'uid' => 's:40~l:46~e:401585601',
                    'date' => '2026-01-30T23:30Z',
                    'name' => 'Game Name',
                    'shortName' => 'ATL @ BOS',
                    'season' => ['year' => 2026, 'type' => 2],
                    'week' => ['number' => 15],
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

    artisan('espn:sync-nba-games-scoreboard', ['date' => '20260130'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Game::count())->toBe(0);
});
