<?php

use App\Models\NBA\Game;
use App\Models\NBA\Play;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;
use App\Models\NBA\TeamStat;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses()->group('espn', 'nba');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['espn_id' => '1']);
    $this->awayTeam = Team::factory()->create(['espn_id' => '2']);

    $this->homePlayer = Player::factory()->create([
        'espn_id' => '1966',
        'team_id' => $this->homeTeam->id,
    ]);

    $this->awayPlayer = Player::factory()->create([
        'espn_id' => '3945274',
        'team_id' => $this->awayTeam->id,
    ]);

    $this->game = Game::factory()->create([
        'espn_event_id' => '401585601',
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
    ]);
});

it('syncs basketball plays, player stats, and team stats from ESPN API', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/summary?event=401585601*' => Http::response([
            'id' => '401585601',
            'plays' => [
                [
                    'id' => '4015856011',
                    'type' => ['text' => 'Made Shot'],
                    'text' => 'T.Young makes 3pt jumper',
                    'scoringPlay' => true,
                    'scoreValue' => 3,
                    'shootingPlay' => true,
                    'clock' => ['displayValue' => '11:45'],
                    'period' => ['number' => 1],
                    'homeScore' => 3,
                    'awayScore' => 0,
                ],
                [
                    'id' => '4015856012',
                    'type' => ['text' => 'Turnover', 'id' => '10'],
                    'text' => 'T.Young bad pass turnover',
                    'scoringPlay' => false,
                    'clock' => ['displayValue' => '10:30'],
                    'period' => ['number' => 1],
                    'homeScore' => 3,
                    'awayScore' => 0,
                ],
            ],
            'boxscore' => [
                'players' => [
                    [
                        'team' => ['id' => '1'],
                        'statistics' => [
                            [
                                'athletes' => [
                                    [
                                        'athlete' => ['id' => '1966'],
                                        'stats' => ['27', '11', '3-10', '0-3', '5-6', '3', '5', '6', '1', '0', '1', '2', '3'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'team' => ['id' => '2'],
                        'statistics' => [
                            [
                                'athletes' => [
                                    [
                                        'athlete' => ['id' => '3945274'],
                                        'stats' => ['30', '29', '12-20', '3-8', '2-6', '5', '6', '2', '0', '0', '0', '5', '1'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'teams' => [
                    [
                        'team' => ['id' => '1'],
                        'homeAway' => 'home',
                        'statistics' => [
                            ['name' => 'fieldGoalsMade-fieldGoalsAttempted', 'displayValue' => '39-81'],
                            ['name' => 'threePointFieldGoalsMade-threePointFieldGoalsAttempted', 'displayValue' => '9-32'],
                            ['name' => 'freeThrowsMade-freeThrowsAttempted', 'displayValue' => '18-22'],
                            ['name' => 'totalRebounds', 'displayValue' => '43'],
                            ['name' => 'offensiveRebounds', 'displayValue' => '10'],
                            ['name' => 'defensiveRebounds', 'displayValue' => '33'],
                            ['name' => 'assists', 'displayValue' => '24'],
                            ['name' => 'turnovers', 'displayValue' => '15'],
                            ['name' => 'steals', 'displayValue' => '8'],
                            ['name' => 'blocks', 'displayValue' => '5'],
                            ['name' => 'fouls', 'displayValue' => '20'],
                            ['name' => 'points', 'displayValue' => '105'],
                        ],
                    ],
                    [
                        'team' => ['id' => '2'],
                        'homeAway' => 'away',
                        'statistics' => [
                            ['name' => 'fieldGoalsMade-fieldGoalsAttempted', 'displayValue' => '42-88'],
                            ['name' => 'threePointFieldGoalsMade-threePointFieldGoalsAttempted', 'displayValue' => '12-35'],
                            ['name' => 'freeThrowsMade-freeThrowsAttempted', 'displayValue' => '16-20'],
                            ['name' => 'totalRebounds', 'displayValue' => '47'],
                            ['name' => 'offensiveRebounds', 'displayValue' => '12'],
                            ['name' => 'defensiveRebounds', 'displayValue' => '35'],
                            ['name' => 'assists', 'displayValue' => '28'],
                            ['name' => 'turnovers', 'displayValue' => '12'],
                            ['name' => 'steals', 'displayValue' => '7'],
                            ['name' => 'blocks', 'displayValue' => '4'],
                            ['name' => 'fouls', 'displayValue' => '18'],
                            ['name' => 'points', 'displayValue' => '112'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-game-details', ['eventId' => '401585601'])
        ->expectsOutput('Dispatching NBA game details sync job for event 401585601...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    // Test plays
    expect(Play::count())->toBe(2);

    $shotPlay = Play::where('espn_play_id', '4015856011')->first();
    expect($shotPlay)->not->toBeNull()
        ->game_id->toBe($this->game->id)
        ->sequence_number->toBe(1)
        ->play_type->toBe('Made Shot')
        ->play_text->toBe('T.Young makes 3pt jumper')
        ->score_value->toBe(3)
        ->clock->toBe('11:45')
        ->period->toBe(1)
        ->home_score->toBe(3)
        ->away_score->toBe(0);

    $turnoverPlay = Play::where('espn_play_id', '4015856012')->first();
    expect($turnoverPlay)->not->toBeNull()
        ->play_type->toBe('Turnover')
        ->is_turnover->toBe(true);

    // Test player stats
    expect(PlayerStat::count())->toBe(2);

    $homePlayerStat = PlayerStat::where('player_id', $this->homePlayer->id)->first();
    expect($homePlayerStat)->not->toBeNull()
        ->game_id->toBe($this->game->id)
        ->team_id->toBe($this->homeTeam->id)
        ->minutes_played->toBe('27')
        ->points->toBe(11)
        ->field_goals_made->toBe(3)
        ->field_goals_attempted->toBe(10)
        ->three_point_made->toBe(0)
        ->three_point_attempted->toBe(3)
        ->free_throws_made->toBe(5)
        ->free_throws_attempted->toBe(6)
        ->rebounds_total->toBe(3)
        ->assists->toBe(5)
        ->turnovers->toBe(6)
        ->steals->toBe(1)
        ->blocks->toBe(0);

    $awayPlayerStat = PlayerStat::where('player_id', $this->awayPlayer->id)->first();
    expect($awayPlayerStat)->not->toBeNull()
        ->points->toBe(29)
        ->field_goals_made->toBe(12)
        ->field_goals_attempted->toBe(20);

    // Test team stats
    expect(TeamStat::count())->toBe(2);

    $homeTeamStat = TeamStat::where('team_id', $this->homeTeam->id)->first();
    expect($homeTeamStat)->not->toBeNull()
        ->game_id->toBe($this->game->id)
        ->team_type->toBe('home')
        ->field_goals_made->toBe(39)
        ->field_goals_attempted->toBe(81)
        ->three_point_made->toBe(9)
        ->three_point_attempted->toBe(32)
        ->free_throws_made->toBe(18)
        ->free_throws_attempted->toBe(22)
        ->rebounds->toBe(43)
        ->offensive_rebounds->toBe(10)
        ->defensive_rebounds->toBe(33)
        ->assists->toBe(24)
        ->turnovers->toBe(15)
        ->steals->toBe(8)
        ->blocks->toBe(5)
        ->fouls->toBe(20)
        ->points->toBe(105);

    $awayTeamStat = TeamStat::where('team_id', $this->awayTeam->id)->first();
    expect($awayTeamStat)->not->toBeNull()
        ->team_type->toBe('away')
        ->field_goals_made->toBe(42)
        ->field_goals_attempted->toBe(88)
        ->points->toBe(112);
});

it('handles empty plays response', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/summary?event=401585601*' => Http::response([
            'id' => '401585601',
            'plays' => [],
            'boxscore' => ['players' => [], 'teams' => []],
        ]),
    ]);

    artisan('espn:sync-nba-game-details', ['eventId' => '401585601'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(0);
    expect(PlayerStat::count())->toBe(0);
    expect(TeamStat::count())->toBe(0);
});

it('updates existing plays instead of creating duplicates', function () {
    Play::factory()->create([
        'espn_play_id' => '4015856011',
        'game_id' => $this->game->id,
        'play_text' => 'Old play description',
    ]);

    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/summary?event=401585601*' => Http::response([
            'id' => '401585601',
            'plays' => [
                [
                    'id' => '4015856011',
                    'type' => ['text' => 'Made Shot'],
                    'text' => 'Updated play description',
                    'scoringPlay' => true,
                    'clock' => ['displayValue' => '11:45'],
                    'period' => ['number' => 1],
                    'homeScore' => 3,
                    'awayScore' => 0,
                ],
            ],
            'boxscore' => ['players' => [], 'teams' => []],
        ]),
    ]);

    artisan('espn:sync-nba-game-details', ['eventId' => '401585601'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(1);
    expect(Play::first()->play_text)->toBe('Updated play description');
});

it('skips plays without an id', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/summary?event=401585601*' => Http::response([
            'id' => '401585601',
            'plays' => [
                [
                    // Missing 'id' field
                    'type' => ['text' => 'Invalid'],
                    'text' => 'Invalid play',
                ],
                [
                    'id' => '4015856011',
                    'type' => ['text' => 'Made Shot'],
                    'text' => 'Valid play',
                    'scoringPlay' => true,
                    'clock' => ['displayValue' => '11:45'],
                    'period' => ['number' => 1],
                    'homeScore' => 3,
                    'awayScore' => 0,
                ],
            ],
            'boxscore' => ['players' => [], 'teams' => []],
        ]),
    ]);

    artisan('espn:sync-nba-game-details', ['eventId' => '401585601'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(1);
    expect(Play::first()->play_text)->toBe('Valid play');
});
