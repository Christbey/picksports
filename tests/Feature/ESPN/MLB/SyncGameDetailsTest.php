<?php

use App\Actions\ESPN\MLB\SyncGameDetails;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\ESPN\MLB\EspnService;
use Mockery as m;

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
                        'score' => '3',
                        'linescores' => [['displayValue' => '0']],
                        'probables' => [[
                            'name' => 'probableStartingPitcher',
                            'playerId' => '7001',
                        ]],
                    ],
                    [
                        'homeAway' => 'away',
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
        ->and($game->home_score)->toBe(3)
        ->and($game->away_score)->toBe(4);
});
