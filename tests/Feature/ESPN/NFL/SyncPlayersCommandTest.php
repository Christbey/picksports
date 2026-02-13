<?php

use App\Models\NFL\Player;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses()->group('espn', 'nfl');

beforeEach(function () {
    $this->team = Team::factory()->create([
        'espn_id' => '22',
        'abbreviation' => 'KC',
        'location' => 'Kansas City',
        'name' => 'Chiefs',
    ]);
});

it('syncs players from ESPN roster API with nested position groups', function () {
    // Mock the ESPN API response with nested position group structure
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/football/nfl/teams/22/roster*' => Http::response([
            'athletes' => [
                [
                    'position' => 'offense',
                    'items' => [
                        [
                            'id' => '5084939',
                            'firstName' => 'Isaiah',
                            'lastName' => 'Adams',
                            'fullName' => 'Isaiah Adams',
                            'jersey' => '74',
                            'position' => [
                                'abbreviation' => 'G',
                            ],
                            'height' => 76,
                            'weight' => 315,
                            'age' => 25,
                            'experience' => ['years' => 2],
                            'college' => ['name' => 'Illinois'],
                            'status' => ['type' => 'active', 'name' => 'Active'],
                            'headshot' => ['href' => 'https://example.com/headshot.png'],
                        ],
                        [
                            'id' => '2578570',
                            'firstName' => 'Jacoby',
                            'lastName' => 'Brissett',
                            'fullName' => 'Jacoby Brissett',
                            'jersey' => '7',
                            'position' => [
                                'abbreviation' => 'QB',
                            ],
                            'height' => 76,
                            'weight' => 235,
                            'age' => 33,
                            'experience' => ['years' => 10],
                            'college' => ['name' => 'NC State'],
                            'status' => ['type' => 'active', 'name' => 'Active'],
                            'headshot' => ['href' => 'https://example.com/qb.png'],
                        ],
                    ],
                ],
                [
                    'position' => 'defense',
                    'items' => [
                        [
                            'id' => '3912547',
                            'firstName' => 'Chris',
                            'lastName' => 'Jones',
                            'fullName' => 'Chris Jones',
                            'jersey' => '95',
                            'position' => [
                                'abbreviation' => 'DT',
                            ],
                            'height' => 78,
                            'weight' => 310,
                            'age' => 30,
                            'experience' => ['years' => 9],
                            'college' => ['name' => 'Mississippi State'],
                            'status' => ['type' => 'active', 'name' => 'Active'],
                            'headshot' => ['href' => 'https://example.com/dt.png'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nfl-players', ['teamEspnId' => '22'])
        ->expectsOutput('Dispatching NFL players sync job for team 22...')
        ->assertSuccessful();

    // Wait for the queue job to process
    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    // Assert players were created in database
    expect(Player::count())->toBe(3);

    // Assert specific player details
    $quarterback = Player::where('espn_id', '2578570')->first();
    expect($quarterback)->not->toBeNull()
        ->first_name->toBe('Jacoby')
        ->last_name->toBe('Brissett')
        ->full_name->toBe('Jacoby Brissett')
        ->jersey_number->toBe('7')
        ->position->toBe('QB')
        ->height->toBe('76')
        ->weight->toBe(235)
        ->age->toBe(33)
        ->experience->toBe(10)
        ->college->toBe('NC State')
        ->status->toBe('active')
        ->headshot_url->toBe('https://example.com/qb.png')
        ->team_id->toBe($this->team->id);

    // Assert defensive player was synced
    $defensiveTackle = Player::where('espn_id', '3912547')->first();
    expect($defensiveTackle)->not->toBeNull()
        ->position->toBe('DT');
});

it('handles empty roster response', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/football/nfl/teams/22/roster*' => Http::response([
            'athletes' => [],
        ]),
    ]);

    artisan('espn:sync-nfl-players', ['teamEspnId' => '22'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Player::count())->toBe(0);
});

it('handles missing athletes key in response', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/football/nfl/teams/22/roster*' => Http::response([
            'team' => ['id' => '22'],
        ]),
    ]);

    artisan('espn:sync-nfl-players', ['teamEspnId' => '22'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Player::count())->toBe(0);
});

it('updates existing players instead of creating duplicates', function () {
    // Create existing player
    Player::factory()->create([
        'espn_id' => '2578570',
        'team_id' => $this->team->id,
        'first_name' => 'Old',
        'last_name' => 'Name',
    ]);

    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/football/nfl/teams/22/roster*' => Http::response([
            'athletes' => [
                [
                    'position' => 'offense',
                    'items' => [
                        [
                            'id' => '2578570',
                            'firstName' => 'Jacoby',
                            'lastName' => 'Brissett',
                            'fullName' => 'Jacoby Brissett',
                            'jersey' => '7',
                            'position' => ['abbreviation' => 'QB'],
                            'height' => 76,
                            'weight' => 235,
                            'age' => 33,
                            'experience' => ['years' => 10],
                            'college' => ['name' => 'NC State'],
                            'status' => ['type' => 'active', 'name' => 'Active'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nfl-players', ['teamEspnId' => '22'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    // Should still have only 1 player (updated, not duplicated)
    expect(Player::count())->toBe(1);

    $player = Player::first();
    expect($player->first_name)->toBe('Jacoby')
        ->and($player->last_name)->toBe('Brissett');
});

it('syncs all teams when no team espn id provided', function () {
    $team2 = Team::factory()->create([
        'espn_id' => '15',
        'abbreviation' => 'SF',
    ]);

    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/football/nfl/teams/22/roster*' => Http::response([
            'athletes' => [
                [
                    'position' => 'offense',
                    'items' => [
                        [
                            'id' => '1001',
                            'firstName' => 'Player',
                            'lastName' => 'One',
                            'fullName' => 'Player One',
                            'jersey' => '1',
                            'position' => ['abbreviation' => 'QB'],
                        ],
                    ],
                ],
            ],
        ]),
        '*site.api.espn.com/apis/site/v2/sports/football/nfl/teams/15/roster*' => Http::response([
            'athletes' => [
                [
                    'position' => 'offense',
                    'items' => [
                        [
                            'id' => '2002',
                            'firstName' => 'Player',
                            'lastName' => 'Two',
                            'fullName' => 'Player Two',
                            'jersey' => '2',
                            'position' => ['abbreviation' => 'RB'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nfl-players')
        ->expectsOutput('Dispatching NFL players sync job for all teams...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Player::count())->toBe(2);
    expect(Player::where('team_id', $this->team->id)->count())->toBe(1);
    expect(Player::where('team_id', $team2->id)->count())->toBe(1);
});

it('skips players without an id', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/football/nfl/teams/22/roster*' => Http::response([
            'athletes' => [
                [
                    'position' => 'offense',
                    'items' => [
                        [
                            // Missing 'id' field
                            'firstName' => 'Invalid',
                            'lastName' => 'Player',
                        ],
                        [
                            'id' => '2578570',
                            'firstName' => 'Valid',
                            'lastName' => 'Player',
                            'fullName' => 'Valid Player',
                            'jersey' => '7',
                            'position' => ['abbreviation' => 'QB'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nfl-players', ['teamEspnId' => '22'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    // Only the valid player should be saved
    expect(Player::count())->toBe(1);
    expect(Player::first()->first_name)->toBe('Valid');
});
