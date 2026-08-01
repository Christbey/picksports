<?php

use App\Actions\ESPN\WNBA\SyncPlayerStats;
use App\Models\WNBA\Game;
use App\Models\WNBA\Player;
use App\Models\WNBA\PlayerStat;
use App\Models\WNBA\Team;

uses()->group('espn', 'wnba');

it('creates missing players from boxscore athletes before syncing stats', function () {
    $team = Team::factory()->create(['espn_id' => '12']);
    $awayTeam = Team::factory()->create(['espn_id' => '34']);

    $game = Game::factory()->create([
        'espn_event_id' => '401856974',
        'home_team_id' => $team->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
    ]);

    $gameData = [
        'boxscore' => [
            'players' => [
                [
                    'team' => ['id' => '12'],
                    'statistics' => [
                        [
                            'athletes' => [
                                [
                                    'athlete' => [
                                        'id' => '900001',
                                        'firstName' => 'Alyssa',
                                        'lastName' => 'Thomas',
                                        'displayName' => 'Alyssa Thomas',
                                        'jersey' => '25',
                                        'position' => ['abbreviation' => 'F'],
                                    ],
                                    'stats' => ['31', '18', '7-13', '1-2', '3-4', '9', '6', '2', '1', '0', '2', '7', '3'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $synced = app(SyncPlayerStats::class)->execute($gameData, $game);

    $player = Player::query()->where('espn_id', '900001')->first();
    $stat = PlayerStat::query()->where('game_id', $game->id)->first();

    expect($synced)->toBe(1)
        ->and($player)->not->toBeNull()
        ->and($player->team_id)->toBe($team->id)
        ->and($player->full_name)->toBe('Alyssa Thomas')
        ->and($stat)->not->toBeNull()
        ->and($stat->player_id)->toBe($player->id)
        ->and($stat->team_id)->toBe($team->id)
        ->and($stat->points)->toBe(18)
        ->and($stat->field_goals_made)->toBe(7)
        ->and($stat->field_goals_attempted)->toBe(13)
        ->and($stat->rebounds_total)->toBe(9)
        ->and($stat->assists)->toBe(6);
});

it('updates duplicate wnba player game stats from repeated boxscore athletes', function () {
    $team = Team::factory()->create(['espn_id' => '12']);
    $awayTeam = Team::factory()->create(['espn_id' => '34']);

    $game = Game::factory()->create([
        'espn_event_id' => '401856974',
        'home_team_id' => $team->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
    ]);

    $athlete = [
        'athlete' => [
            'id' => '900001',
            'firstName' => 'Alyssa',
            'lastName' => 'Thomas',
            'displayName' => 'Alyssa Thomas',
        ],
        'stats' => ['31', '18', '7-13', '1-2', '3-4', '9', '6', '2', '1', '0', '2', '7', '3'],
    ];

    $gameData = [
        'boxscore' => [
            'players' => [[
                'team' => ['id' => '12'],
                'statistics' => [[
                    'athletes' => [
                        $athlete,
                        [...$athlete, 'stats' => ['32', '20', '8-13', '1-2', '3-4', '9', '6', '2', '1', '0', '2', '7', '3']],
                    ],
                ]],
            ]],
        ],
    ];

    $synced = app(SyncPlayerStats::class)->execute($gameData, $game);

    $player = Player::query()->where('espn_id', '900001')->firstOrFail();
    $stat = PlayerStat::query()->where('game_id', $game->id)->where('player_id', $player->id)->firstOrFail();

    expect($synced)->toBe(2)
        ->and(PlayerStat::query()->where('game_id', $game->id)->where('player_id', $player->id)->count())->toBe(1)
        ->and($stat->points)->toBe(20)
        ->and($stat->field_goals_made)->toBe(8);
});
