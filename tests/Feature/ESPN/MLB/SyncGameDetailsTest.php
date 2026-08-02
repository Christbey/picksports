<?php

use App\Actions\ESPN\MLB\SyncGameDetails;
use App\Actions\ESPN\MLB\SyncTeamStats;
use App\Jobs\ESPN\MLB\FetchGameDetails;
use App\Models\MLB\Game;
use App\Models\MLB\Play;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Team;
use App\Models\MLB\TeamStat;
use App\Services\ESPN\MLB\EspnService;
use Illuminate\Support\Facades\Queue;
use Mockery as m;

use function Pest\Laravel\artisan;

uses()->group('espn', 'mlb');

afterEach(function () {
    m::close();
});

it('updates inning half and count state from mlb game details', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '10']);
    $awayTeam = Team::factory()->create(['espn_id' => '20']);

    $game = Game::factory()->create([
        'espn_event_id' => '401999001',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'inning' => 1,
        'inning_half' => null,
        'balls' => null,
        'strikes' => null,
        'outs' => null,
        'home_score' => 0,
        'away_score' => 0,
    ]);

    $gameData = [
        'header' => [
            'competitions' => [[
                'status' => [
                    'period' => 5,
                    'displayClock' => 'Top 5th',
                    'type' => [
                        'name' => 'STATUS_IN_PROGRESS',
                        'shortDetail' => 'Top 5th',
                    ],
                ],
                'situation' => [
                    'balls' => 2,
                    'strikes' => 1,
                    'outs' => 1,
                ],
                'competitors' => [
                    [
                        'homeAway' => 'home',
                        'team' => ['id' => '10', 'abbreviation' => 'HOM'],
                        'score' => '3',
                        'linescores' => [['displayValue' => '0']],
                        'probables' => [[
                            'name' => 'probableStartingPitcher',
                            'playerId' => '7001',
                        ]],
                    ],
                    [
                        'homeAway' => 'away',
                        'team' => ['id' => '20', 'abbreviation' => 'AWY'],
                        'score' => '4',
                        'linescores' => [['displayValue' => '1']],
                        'probables' => [[
                            'name' => 'probableStartingPitcher',
                            'athlete' => ['id' => '7002'],
                        ]],
                    ],
                ],
            ]],
        ],
        'boxscore' => [
            'players' => [
                [
                    'team' => ['id' => '20', 'abbreviation' => 'AWY'],
                    'statistics' => [[
                        'type' => 'pitching',
                        'athletes' => [
                            ['starter' => true, 'athlete' => ['id' => '7102']],
                            ['starter' => false, 'athlete' => ['id' => '7199']],
                        ],
                    ]],
                ],
                [
                    'team' => ['id' => '10', 'abbreviation' => 'HOM'],
                    'statistics' => [[
                        'type' => 'pitching',
                        'athletes' => [
                            ['starter' => true, 'athlete' => ['id' => '7101']],
                        ],
                    ]],
                ],
            ],
        ],
    ];

    $espnService = m::mock(EspnService::class);
    $espnService->shouldReceive('getGame')->once()->with('401999001')->andReturn($gameData);

    $syncPlayerStats = new class
    {
        public function execute(...$args): int
        {
            return 0;
        }
    };

    $syncTeamStats = new class
    {
        public function execute(...$args): int
        {
            return 0;
        }
    };

    $syncPlays = new class
    {
        public function execute(...$args): int
        {
            return 0;
        }
    };

    $result = (new SyncGameDetails($espnService, $syncPlayerStats, $syncTeamStats, $syncPlays))
        ->execute('401999001');

    $game->refresh();

    expect($result)->toMatchArray([
        'plays' => 0,
        'player_stats' => 0,
        'team_stats' => 0,
        'game_updated' => true,
    ]);

    expect($game->status)->toBe('STATUS_IN_PROGRESS')
        ->and($game->inning)->toBe(5)
        ->and($game->inning_half)->toBe('top')
        ->and($game->inning_state)->toBe('top')
        ->and($game->balls)->toBe(2)
        ->and($game->strikes)->toBe(1)
        ->and($game->outs)->toBe(1)
        ->and($game->probable_home_pitcher_espn_id)->toBe('7001')
        ->and($game->probable_away_pitcher_espn_id)->toBe('7002')
        ->and($game->actual_home_pitcher_espn_id)->toBe('7101')
        ->and($game->actual_away_pitcher_espn_id)->toBe('7102')
        ->and($game->resolvedStartingPitcherEspnId('home'))->toBe('7101')
        ->and($game->startingPitcherSource('home'))->toBe('espn_boxscore_confirmed')
        ->and(data_get($game->starting_pitcher_confirmation_metadata, 'away.source'))->toBe('espn_boxscore')
        ->and($game->starting_pitchers_confirmed_at)->not->toBeNull()
        ->and($game->home_score)->toBe(3)
        ->and($game->away_score)->toBe(4)
        ->and($game->home_linescores)->toBe(['0'])
        ->and($game->away_linescores)->toBe(['1']);
});

it('reconciles missing final scores after normal team stat ingestion', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '30']);
    $awayTeam = Team::factory()->create(['espn_id' => '40']);
    $game = Game::factory()->create([
        'espn_event_id' => '401999002',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => null,
        'away_score' => null,
    ]);

    $gameData = [
        'header' => [
            'competitions' => [[
                'status' => ['type' => ['name' => 'STATUS_FINAL']],
                'competitors' => [
                    ['homeAway' => 'home'],
                    ['homeAway' => 'away'],
                ],
            ]],
        ],
    ];

    $espnService = m::mock(EspnService::class);
    $espnService->shouldReceive('getGame')->once()->with($game->espn_event_id)->andReturn($gameData);

    $syncTeamStats = new class
    {
        public function execute(array $gameData, Game $game): int
        {
            TeamStat::factory()->create([
                'game_id' => $game->id,
                'team_id' => $game->home_team_id,
                'team_type' => 'home',
                'runs' => 6,
            ]);
            TeamStat::factory()->create([
                'game_id' => $game->id,
                'team_id' => $game->away_team_id,
                'team_type' => 'away',
                'runs' => 3,
            ]);

            return 2;
        }
    };

    $noOpSync = new class
    {
        public function execute(...$args): int
        {
            return 0;
        }
    };

    $result = (new SyncGameDetails($espnService, $noOpSync, $syncTeamStats, $noOpSync))
        ->execute($game->espn_event_id);

    expect($result['score_reconciliation'])
        ->toMatchArray([
            'status' => 'updated',
            'reason' => 'filled_missing_final_score_from_team_stats',
            'home_score_after' => 6,
            'away_score_after' => 3,
        ])
        ->and($game->fresh()->home_score)->toBe(6)
        ->and($game->fresh()->away_score)->toBe(3);
});

it('does not overwrite score conflicts during normal final game sync', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '50']);
    $awayTeam = Team::factory()->create(['espn_id' => '60']);
    $game = Game::factory()->create([
        'espn_event_id' => '401999003',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 5,
        'away_score' => 2,
    ]);

    $gameData = [
        'header' => [
            'competitions' => [[
                'status' => ['type' => ['name' => 'STATUS_FINAL']],
                'competitors' => [
                    ['homeAway' => 'home', 'score' => '4'],
                    ['homeAway' => 'away', 'score' => '2'],
                ],
            ]],
        ],
    ];

    $espnService = m::mock(EspnService::class);
    $espnService->shouldReceive('getGame')->once()->with($game->espn_event_id)->andReturn($gameData);

    $syncTeamStats = new class
    {
        public function execute(array $gameData, Game $game): int
        {
            TeamStat::factory()->create([
                'game_id' => $game->id,
                'team_id' => $game->home_team_id,
                'team_type' => 'home',
                'runs' => 4,
            ]);
            TeamStat::factory()->create([
                'game_id' => $game->id,
                'team_id' => $game->away_team_id,
                'team_type' => 'away',
                'runs' => 2,
            ]);

            return 2;
        }
    };

    $noOpSync = new class
    {
        public function execute(...$args): int
        {
            return 0;
        }
    };

    $result = (new SyncGameDetails($espnService, $noOpSync, $syncTeamStats, $noOpSync))
        ->execute($game->espn_event_id);

    expect($result['score_reconciliation'])
        ->toMatchArray([
            'status' => 'conflict',
            'reason' => 'game_score_conflicts_with_team_stats_runs',
        ])
        ->and($game->fresh()->home_score)->toBe(5)
        ->and($game->fresh()->away_score)->toBe(2);
});

it('normalizes mlb team innings pitched from baseball decimal notation', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '10']);
    $awayTeam = Team::factory()->create(['espn_id' => '20']);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
    ]);

    $synced = (new SyncTeamStats)->execute([
        'boxscore' => [
            'teams' => [[
                'team' => ['id' => '10'],
                'statistics' => [[
                    'name' => 'pitching',
                    'stats' => [
                        ['name' => 'innings', 'displayValue' => '8.1'],
                        ['name' => 'hits', 'displayValue' => '8'],
                        ['name' => 'earnedRuns', 'displayValue' => '3'],
                    ],
                ]],
            ]],
        ],
    ], $game);

    $stat = TeamStat::query()->where('game_id', $game->id)->where('team_id', $homeTeam->id)->first();

    expect($synced)->toBe(1)
        ->and($stat)->not->toBeNull()
        ->and(abs(((float) $stat->innings_pitched) - (8 + (1 / 3))))->toBeLessThan(0.0001);
});

it('stores official mlb team obp inputs from boxscore team stats', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '10']);
    $awayTeam = Team::factory()->create(['espn_id' => '20']);

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
    ]);

    (new SyncTeamStats)->execute([
        'boxscore' => [
            'teams' => [[
                'team' => ['id' => '10'],
                'statistics' => [[
                    'name' => 'batting',
                    'stats' => [
                        ['name' => 'hits', 'displayValue' => '9'],
                        ['name' => 'walks', 'displayValue' => '4'],
                        ['name' => 'hitByPitch', 'displayValue' => '2'],
                        ['name' => 'sacrificeFlies', 'displayValue' => '1'],
                    ],
                ]],
            ]],
        ],
    ], $game);

    $stat = TeamStat::query()->where('game_id', $game->id)->where('team_id', $homeTeam->id)->first();

    expect($stat)->not->toBeNull()
        ->and($stat->hit_by_pitch)->toBe(2)
        ->and($stat->sacrifice_flies)->toBe(1);
});

it('dispatches final mlb games missing player stats even when linescores exist', function () {
    Queue::fake();

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $gameWithStats = Game::factory()->create([
        'espn_event_id' => '401999101',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_linescores' => [['value' => 1]],
        'away_linescores' => [['value' => 0]],
    ]);

    $player = Player::factory()->create(['team_id' => $homeTeam->id]);
    PlayerStat::factory()->create([
        'player_id' => $player->id,
        'game_id' => $gameWithStats->id,
        'team_id' => $homeTeam->id,
    ]);
    TeamStat::factory()->create([
        'team_id' => $homeTeam->id,
        'game_id' => $gameWithStats->id,
        'team_type' => 'home',
    ]);
    Play::query()->create([
        'game_id' => $gameWithStats->id,
        'espn_play_id' => '401999101-1',
        'sequence_number' => 1,
        'inning' => 9,
        'inning_half' => 'bottom',
        'play_text' => 'Game over',
    ]);

    $missingStatsGame = Game::factory()->create([
        'espn_event_id' => '401999102',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_linescores' => [['value' => 2]],
        'away_linescores' => [['value' => 1]],
    ]);

    Game::factory()->create([
        'espn_event_id' => '401999103',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'home_linescores' => null,
    ]);

    artisan('espn:sync-mlb-game-details')->assertSuccessful();

    Queue::assertPushed(FetchGameDetails::class, 1);
    Queue::assertPushed(
        FetchGameDetails::class,
        fn (FetchGameDetails $job) => $job->eventId === $missingStatsGame->espn_event_id
    );
});

it('dispatches final mlb games missing inning line scores even when other details exist', function () {
    Queue::fake();

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $game = Game::factory()->create([
        'espn_event_id' => '401999104',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 4,
        'away_score' => 2,
        'home_linescores' => null,
        'away_linescores' => null,
    ]);
    $player = Player::factory()->create(['team_id' => $homeTeam->id]);

    PlayerStat::factory()->create([
        'player_id' => $player->id,
        'game_id' => $game->id,
        'team_id' => $homeTeam->id,
    ]);
    TeamStat::factory()->create([
        'team_id' => $homeTeam->id,
        'game_id' => $game->id,
        'team_type' => 'home',
    ]);
    Play::query()->create([
        'game_id' => $game->id,
        'espn_play_id' => '401999104-1',
        'sequence_number' => 1,
        'inning' => 9,
        'inning_half' => 'bottom',
        'play_text' => 'Game over',
    ]);

    artisan('espn:sync-mlb-game-details')->assertSuccessful();

    Queue::assertPushed(FetchGameDetails::class, 1);
    Queue::assertPushed(
        FetchGameDetails::class,
        fn (FetchGameDetails $job) => $job->eventId === $game->espn_event_id
    );
});

it('can dispatch mlb game detail jobs to a requested queue', function () {
    Queue::fake();

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'espn_event_id' => '401999201',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
    ]);

    artisan('espn:sync-mlb-game-details --queue=sync')->assertSuccessful();

    Queue::assertPushedOn(
        'sync',
        FetchGameDetails::class,
        fn (FetchGameDetails $job) => $job->eventId === $game->espn_event_id
    );
});

it('can run an mlb game detail job synchronously without dispatching to the queue', function () {
    Queue::fake();

    artisan('espn:sync-mlb-game-details', ['eventId' => '401999202', '--sync' => true])
        ->expectsOutput('Running MLB game details sync job for event 401999202...')
        ->expectsOutput('MLB game details sync job completed successfully.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('can refresh final mlb games that already have player stats', function () {
    Queue::fake();

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $homeTeam->id]);

    $game = Game::factory()->create([
        'espn_event_id' => '401999201',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
    ]);

    PlayerStat::factory()->create([
        'player_id' => $player->id,
        'game_id' => $game->id,
        'team_id' => $homeTeam->id,
    ]);

    artisan('espn:sync-mlb-game-details', ['--refresh-existing' => true])->assertSuccessful();

    Queue::assertPushed(FetchGameDetails::class, 1);
    Queue::assertPushed(
        FetchGameDetails::class,
        fn (FetchGameDetails $job) => $job->eventId === $game->espn_event_id
    );
});
