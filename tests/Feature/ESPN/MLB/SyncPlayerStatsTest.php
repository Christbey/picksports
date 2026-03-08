<?php

use App\Actions\ESPN\MLB\SyncPlayerStats;
use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Team;

uses()->group('espn', 'mlb');

it('maps pitching stats by label and normalizes innings pitched', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '10']);
    $awayTeam = Team::factory()->create(['espn_id' => '20']);

    $pitcher = Player::factory()->pitcher()->create([
        'espn_id' => '9001',
        'team_id' => $homeTeam->id,
    ]);

    $game = Game::factory()->create([
        'espn_event_id' => '401833110',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    PlayerStat::factory()->create([
        'player_id' => $pitcher->id,
        'game_id' => $game->id,
        'team_id' => $homeTeam->id,
        'stat_type' => 'pitching',
        'innings_pitched' => 1.0,
    ]);

    $gameData = [
        'boxscore' => [
            'players' => [
                [
                    'team' => ['id' => '10'],
                    'statistics' => [
                        [
                            'type' => 'pitching',
                            'labels' => ['ERA', 'IP', 'SO', 'H', 'R', 'ER', 'BB', 'HR', 'P'],
                            'athletes' => [
                                [
                                    'athlete' => ['id' => '9001'],
                                    'stats' => ['2.70', '5.1', '7', '4', '2', '2', '1', '1', '88'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $synced = app(SyncPlayerStats::class)->execute($gameData, $game);

    expect($synced)->toBe(1);
    expect(PlayerStat::query()->where('game_id', $game->id)->count())->toBe(1);

    $stat = PlayerStat::query()->where('game_id', $game->id)->first();

    expect($stat)->not->toBeNull()
        ->player_id->toBe($pitcher->id)
        ->team_id->toBe($homeTeam->id)
        ->stat_type->toBe('pitching')
        ->hits_allowed->toBe(4)
        ->runs_allowed->toBe(2)
        ->earned_runs->toBe(2)
        ->walks_allowed->toBe(1)
        ->strikeouts_pitched->toBe(7)
        ->home_runs_allowed->toBe(1)
        ->pitches_thrown->toBe(88)
        ->pitch_count->toBe(88)
        ->era->toBe(2.7);

    expect(abs(((float) $stat->innings_pitched) - (5 + (1 / 3))))->toBeLessThan(0.0001);
});

it('normalizes numeric innings pitched values from decimal outs notation', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '11']);
    $awayTeam = Team::factory()->create(['espn_id' => '21']);

    $pitcher = Player::factory()->pitcher()->create([
        'espn_id' => '9002',
        'team_id' => $homeTeam->id,
    ]);

    $game = Game::factory()->create([
        'espn_event_id' => '401833111',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $gameData = [
        'boxscore' => [
            'players' => [
                [
                    'team' => ['id' => '11'],
                    'statistics' => [
                        [
                            'type' => 'pitching',
                            'labels' => ['IP', 'H', 'R', 'ER', 'BB', 'SO', 'HR', 'ERA', 'P'],
                            'athletes' => [
                                [
                                    'athlete' => ['id' => '9002'],
                                    'stats' => [6.2, 5, 1, 1, 2, 9, 0, 1.45, 102],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    app(SyncPlayerStats::class)->execute($gameData, $game);

    $stat = PlayerStat::query()
        ->where('game_id', $game->id)
        ->where('player_id', $pitcher->id)
        ->first();

    expect($stat)->not->toBeNull();
    expect(abs(((float) $stat->innings_pitched) - (6 + (2 / 3))))->toBeLessThan(0.0001);
});

it('parses batting stats correctly when h-ab is present and labels are incomplete', function () {
    $homeTeam = Team::factory()->create(['espn_id' => '12']);
    $awayTeam = Team::factory()->create(['espn_id' => '22']);

    $batter = Player::factory()->create([
        'espn_id' => '9003',
        'team_id' => $homeTeam->id,
    ]);

    $game = Game::factory()->create([
        'espn_event_id' => '401833112',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $gameData = [
        'boxscore' => [
            'players' => [
                [
                    'team' => ['id' => '12'],
                    'statistics' => [
                        [
                            'type' => 'batting',
                            // Missing AB/AVG labels; starts with H-AB and includes #P.
                            'labels' => ['H-AB', 'R', 'H', 'RBI', 'HR', 'BB', 'K', '#P', 'OBP', 'SLG'],
                            'athletes' => [
                                [
                                    'athlete' => ['id' => '9003'],
                                    'stats' => ['2-4', '1', '2', '3', '1', '0', '1', '18', '.280', '.600'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    app(SyncPlayerStats::class)->execute($gameData, $game);

    $stat = PlayerStat::query()
        ->where('game_id', $game->id)
        ->where('player_id', $batter->id)
        ->first();

    expect($stat)->not->toBeNull()
        ->at_bats->toBe(4)
        ->hits->toBe(2)
        ->rbis->toBe(3)
        ->home_runs->toBe(1)
        ->walks->toBe(0)
        ->strikeouts->toBe(1)
        ->batting_average->toBeNull()
        ->on_base_percentage->toBe(0.28)
        ->slugging_percentage->toBe(0.6);
});
