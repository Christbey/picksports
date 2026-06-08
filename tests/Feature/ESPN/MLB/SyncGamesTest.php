<?php

use App\Actions\ESPN\MLB\SyncGames;
use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Actions\MLB\UpdateLivePrediction;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Support\Carbon;
use Mockery as m;

uses()->group('espn', 'mlb');

afterEach(function () {
    m::close();
});

it('stores probable pitcher ids from mlb schedule sync', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '10']);
    $awayTeam = Team::factory()->create(['espn_id' => '20']);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'mlb';

        public function getGames(int $season, int $seasonType, int $week): ?array
        {
            return [
                'items' => [[
                    'id' => '401999101',
                    'uid' => 's:1~l:10~e:401999101',
                    'date' => '2026-03-25T18:05:00Z',
                    'name' => 'Texas Rangers at Seattle Mariners',
                    'shortName' => 'TEX @ SEA',
                    'season' => ['year' => $season, 'type' => $seasonType],
                    'week' => ['number' => $week],
                    'competitions' => [[
                        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
                        'competitors' => [
                            [
                                'homeAway' => 'home',
                                'team' => ['id' => '10'],
                                'probables' => [[
                                    'name' => 'probableStartingPitcher',
                                    'playerId' => '8001',
                                ]],
                            ],
                            [
                                'homeAway' => 'away',
                                'team' => ['id' => '20'],
                                'probables' => [[
                                    'name' => 'probableStartingPitcher',
                                    'athlete' => ['id' => '8002'],
                                ]],
                            ],
                        ],
                    ]],
                ]],
            ];
        }
    };

    $synced = (new SyncGames($service))->execute(2026, 2, 1);

    expect($synced)->toBe(1);

    $game = Game::query()->where('espn_event_id', '401999101')->first();

    expect($game)->not->toBeNull()
        ->and($game->home_team_id)->toBe($homeTeam->id)
        ->and($game->away_team_id)->toBe($awayTeam->id)
        ->and($game->probable_home_pitcher_espn_id)->toBe('8001')
        ->and($game->probable_away_pitcher_espn_id)->toBe('8002');
});

it('stores mlb west coast night games on the local venue date', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '10']);
    $awayTeam = Team::factory()->create(['espn_id' => '20']);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'mlb';

        public function getGames(int $season, int $seasonType, int $week): ?array
        {
            return [
                'items' => [[
                    'id' => '401814702',
                    'uid' => 's:1~l:10~e:401814702',
                    'date' => '2026-03-26T00:05:00Z',
                    'name' => 'New York Yankees at San Francisco Giants',
                    'shortName' => 'NYY @ SF',
                    'season' => ['year' => $season, 'type' => $seasonType],
                    'week' => ['number' => $week],
                    'competitions' => [[
                        'venue' => [
                            'address' => [
                                'city' => 'San Francisco',
                                'state' => 'CA',
                            ],
                        ],
                        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
                        'competitors' => [
                            [
                                'homeAway' => 'home',
                                'team' => ['id' => '10'],
                            ],
                            [
                                'homeAway' => 'away',
                                'team' => ['id' => '20'],
                            ],
                        ],
                    ]],
                ]],
            ];
        }
    };

    $synced = (new SyncGames($service))->execute(2026, 2, 12);

    expect($synced)->toBe(1);

    $game = Game::query()->where('espn_event_id', '401814702')->first();

    expect($game)->not->toBeNull()
        ->and($game->game_date?->format('Y-m-d'))->toBe('2026-03-25')
        ->and($game->game_time)->toBe('17:05:00');
});

it('updates orphaned scheduled mlb games from summary using local venue date', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '10']);
    $awayTeam = Team::factory()->create(['espn_id' => '20']);

    $game = Game::factory()->create([
        'espn_event_id' => '401814799',
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'game_date' => '2026-06-01',
        'game_time' => '02:10:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'mlb';

        public function getScoreboard(?string $date = null): ?array
        {
            return ['events' => []];
        }

        public function getGame(string $eventId): ?array
        {
            return [
                'header' => [
                    'date' => '2026-06-01T02:10:00Z',
                    'competitions' => [[
                        'date' => '2026-06-01T02:10:00Z',
                        'venue' => [
                            'address' => [
                                'city' => 'San Francisco',
                                'state' => 'CA',
                            ],
                        ],
                        'status' => [
                            'type' => ['name' => 'STATUS_FINAL'],
                            'period' => 9,
                            'displayClock' => 'Final',
                        ],
                        'competitors' => [
                            [
                                'homeAway' => 'home',
                                'score' => '4',
                                'team' => ['id' => '10'],
                            ],
                            [
                                'homeAway' => 'away',
                                'score' => '2',
                                'team' => ['id' => '20'],
                            ],
                        ],
                    ]],
                ],
            ];
        }
    };

    $predictionAction = m::mock(UpdateLivePrediction::class);
    $predictionAction->shouldReceive('execute')->never();

    $synced = (new SyncGamesFromScoreboard($service, $predictionAction))->execute('20260601');

    expect($synced)->toBe(1);

    $game->refresh();

    expect($game->status)->toBe('STATUS_FINAL')
        ->and($game->home_score)->toBe(4)
        ->and($game->away_score)->toBe(2)
        ->and($game->game_date?->toDateString())->toBe('2026-05-31')
        ->and($game->game_time)->toBe('19:10:00');
});

it('refreshes the game row timestamp when scoreboard confirms unchanged mlb games', function () {
    Carbon::setTestNow('2026-06-07 12:00:00');

    $homeTeam = Team::factory()->create(['espn_id' => '10']);
    $awayTeam = Team::factory()->create(['espn_id' => '20']);

    $game = Game::factory()->create([
        'espn_event_id' => '401814900',
        'espn_uid' => 's:1~l:10~e:401814900',
        'season' => 2026,
        'week' => 12,
        'season_type' => (string) config('mlb.season.types.regular'),
        'game_date' => '2026-06-09',
        'game_time' => '19:10:00',
        'name' => 'Texas Rangers at Seattle Mariners',
        'short_name' => 'TEX @ SEA',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 0,
        'away_score' => 0,
        'updated_at' => now()->subDay(),
    ]);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'mlb';

        public function getScoreboard(?string $date = null): ?array
        {
            return [
                'events' => [[
                    'id' => '401814900',
                    'uid' => 's:1~l:10~e:401814900',
                    'date' => '2026-06-10T00:10:00Z',
                    'name' => 'Texas Rangers at Seattle Mariners',
                    'shortName' => 'TEX @ SEA',
                    'season' => ['year' => 2026, 'type' => (int) config('mlb.season.types.regular')],
                    'week' => ['number' => 12],
                    'status' => [
                        'type' => ['name' => 'STATUS_SCHEDULED'],
                        'period' => 0,
                        'displayClock' => '',
                    ],
                    'competitions' => [[
                        'venue' => [
                            'address' => [
                                'city' => 'Seattle',
                                'state' => 'WA',
                            ],
                        ],
                        'competitors' => [
                            [
                                'homeAway' => 'home',
                                'score' => '0',
                                'team' => ['id' => '10'],
                            ],
                            [
                                'homeAway' => 'away',
                                'score' => '0',
                                'team' => ['id' => '20'],
                            ],
                        ],
                    ]],
                ]],
            ];
        }
    };

    $predictionAction = m::mock(UpdateLivePrediction::class);
    $predictionAction->shouldReceive('execute')->once();

    $synced = (new SyncGamesFromScoreboard($service, $predictionAction))->execute('20260609');

    expect($synced)->toBe(1);

    $game->refresh();

    expect($game->updated_at?->toDateTimeString())->toBe(now()->toDateTimeString());

    Carbon::setTestNow();
});

it('normalizes mislabeled pre-opener mlb games to spring training during sync', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '10']);
    $awayTeam = Team::factory()->create(['espn_id' => '20']);

    Game::factory()->create([
        'espn_event_id' => '401814702',
        'season' => 2026,
        'week' => 12,
        'season_type' => (string) config('mlb.season.types.regular'),
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-25',
        'game_time' => '17:05:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'mlb';

        public function getGames(int $season, int $seasonType, int $week): ?array
        {
            return [
                'items' => [[
                    'id' => '401833330',
                    'uid' => 's:1~l:10~e:401833330',
                    'date' => '2026-03-24T19:40:00Z',
                    'name' => 'Cleveland Guardians at Arizona Diamondbacks',
                    'shortName' => 'CLE @ ARI',
                    'season' => ['year' => $season, 'type' => 2],
                    'week' => ['number' => 1],
                    'competitions' => [[
                        'status' => ['type' => ['name' => 'STATUS_FINAL']],
                        'competitors' => [
                            ['homeAway' => 'home', 'team' => ['id' => '10']],
                            ['homeAway' => 'away', 'team' => ['id' => '20']],
                        ],
                    ]],
                ]],
            ];
        }
    };

    $synced = (new SyncGames($service))->execute(2026, 2, 1);

    expect($synced)->toBe(1);

    $game = Game::query()->where('espn_event_id', '401833330')->first();

    expect($game)->not->toBeNull()
        ->and((string) $game->season_type)->toBe((string) config('mlb.season.types.spring_training'));
});
