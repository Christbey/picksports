<?php

use App\Actions\ESPN\CBB\SyncTeamStats;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['espn_id' => '52']);
    $this->awayTeam = Team::factory()->create(['espn_id' => '150']);
    $this->game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
    ]);
});

test('calculates possessions correctly when not provided by ESPN', function () {
    $gameData = [
        'boxscore' => [
            'teams' => [
                [
                    'team' => ['id' => '52'],
                    'homeAway' => 'home',
                    'statistics' => [
                        ['name' => 'fieldGoalsMade', 'displayValue' => '25'],
                        ['name' => 'fieldGoalsAttempted', 'displayValue' => '60'],
                        ['name' => 'offensiveRebounds', 'displayValue' => '10'],
                        ['name' => 'turnovers', 'displayValue' => '12'],
                        ['name' => 'freeThrowsAttempted', 'displayValue' => '20'],
                    ],
                ],
            ],
        ],
    ];

    $action = new SyncTeamStats;
    $action->execute($gameData, $this->game);

    $teamStat = TeamStat::query()
        ->where('team_id', $this->homeTeam->id)
        ->where('game_id', $this->game->id)
        ->first();

    expect($teamStat)->not->toBeNull();
    expect($teamStat->field_goals_attempted)->toBe(60);
    expect($teamStat->offensive_rebounds)->toBe(10);
    expect($teamStat->turnovers)->toBe(12);
    expect($teamStat->free_throws_attempted)->toBe(20);

    // Possessions = FGA - OREB + TO + (0.4 * FTA)
    // = 60 - 10 + 12 + (0.4 * 20)
    // = 60 - 10 + 12 + 8
    // = 70
    expect($teamStat->possessions)->toBe(70.0);
});

test('uses ESPN provided possessions when available', function () {
    $gameData = [
        'boxscore' => [
            'teams' => [
                [
                    'team' => ['id' => '52'],
                    'homeAway' => 'home',
                    'statistics' => [
                        ['name' => 'fieldGoalsAttempted', 'displayValue' => '60'],
                        ['name' => 'offensiveRebounds', 'displayValue' => '10'],
                        ['name' => 'turnovers', 'displayValue' => '12'],
                        ['name' => 'freeThrowsAttempted', 'displayValue' => '20'],
                        ['name' => 'possessions', 'displayValue' => '72'],
                    ],
                ],
            ],
        ],
    ];

    $action = new SyncTeamStats;
    $action->execute($gameData, $this->game);

    $teamStat = TeamStat::query()
        ->where('team_id', $this->homeTeam->id)
        ->where('game_id', $this->game->id)
        ->first();

    expect($teamStat->possessions)->toBe(72);
});

test('handles edge case with zero stats gracefully', function () {
    $gameData = [
        'boxscore' => [
            'teams' => [
                [
                    'team' => ['id' => '52'],
                    'homeAway' => 'home',
                    'statistics' => [
                        ['name' => 'fieldGoalsAttempted', 'displayValue' => '0'],
                        ['name' => 'offensiveRebounds', 'displayValue' => '0'],
                        ['name' => 'turnovers', 'displayValue' => '0'],
                        ['name' => 'freeThrowsAttempted', 'displayValue' => '0'],
                    ],
                ],
            ],
        ],
    ];

    $action = new SyncTeamStats;
    $action->execute($gameData, $this->game);

    $teamStat = TeamStat::query()
        ->where('team_id', $this->homeTeam->id)
        ->where('game_id', $this->game->id)
        ->first();

    // Possessions = 0 - 0 + 0 + (0.4 * 0) = 0
    expect($teamStat->possessions)->toBe(0.0);
});

test('syncs stats for both home and away teams', function () {
    $gameData = [
        'boxscore' => [
            'teams' => [
                [
                    'team' => ['id' => '52'],
                    'homeAway' => 'home',
                    'statistics' => [
                        ['name' => 'fieldGoalsAttempted', 'displayValue' => '60'],
                        ['name' => 'offensiveRebounds', 'displayValue' => '10'],
                        ['name' => 'turnovers', 'displayValue' => '12'],
                        ['name' => 'freeThrowsAttempted', 'displayValue' => '20'],
                    ],
                ],
                [
                    'team' => ['id' => '150'],
                    'homeAway' => 'away',
                    'statistics' => [
                        ['name' => 'fieldGoalsAttempted', 'displayValue' => '55'],
                        ['name' => 'offensiveRebounds', 'displayValue' => '8'],
                        ['name' => 'turnovers', 'displayValue' => '15'],
                        ['name' => 'freeThrowsAttempted', 'displayValue' => '25'],
                    ],
                ],
            ],
        ],
    ];

    $action = new SyncTeamStats;
    $synced = $action->execute($gameData, $this->game);

    expect($synced)->toBe(2);

    $homeStats = TeamStat::query()
        ->where('team_id', $this->homeTeam->id)
        ->where('game_id', $this->game->id)
        ->first();

    $awayStats = TeamStat::query()
        ->where('team_id', $this->awayTeam->id)
        ->where('game_id', $this->game->id)
        ->first();

    expect($homeStats->possessions)->toBe(70.0); // 60 - 10 + 12 + 8
    expect($awayStats->possessions)->toBe(72.0); // 55 - 8 + 15 + 10
});
