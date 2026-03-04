<?php

use App\Models\NBA\Team;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses()->group('espn', 'nba');

it('syncs teams from ESPN API', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams*' => Http::response([
            'sports' => [
                [
                    'leagues' => [
                        [
                            'teams' => [
                                [
                                    'team' => [
                                        'id' => '1',
                                        'abbreviation' => 'ATL',
                                        'location' => 'Atlanta',
                                        'name' => 'Hawks',
                                        'displayName' => 'Atlanta Hawks',
                                        'color' => 'c8102e',
                                        'logos' => [
                                            ['href' => 'https://example.com/atl.png'],
                                        ],
                                        'groups' => [
                                            'id' => '4',
                                            'name' => 'Eastern',
                                        ],
                                    ],
                                ],
                                [
                                    'team' => [
                                        'id' => '2',
                                        'abbreviation' => 'BOS',
                                        'location' => 'Boston',
                                        'name' => 'Celtics',
                                        'displayName' => 'Boston Celtics',
                                        'color' => '007a33',
                                        'logos' => [
                                            ['href' => 'https://example.com/bos.png'],
                                        ],
                                        'groups' => [
                                            'id' => '4',
                                            'name' => 'Eastern',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-teams')
        ->expectsOutput('Dispatching NBA teams sync job...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Team::count())->toBe(2);

    $atl = Team::where('espn_id', '1')->first();
    expect($atl)->not->toBeNull()
        ->abbreviation->toBe('ATL')
        ->location->toBe('Atlanta')
        ->name->toBe('Hawks');

    $bos = Team::where('espn_id', '2')->first();
    expect($bos)->not->toBeNull()
        ->abbreviation->toBe('BOS')
        ->location->toBe('Boston')
        ->name->toBe('Celtics');
});

it('handles empty teams response', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams*' => Http::response([
            'sports' => [
                [
                    'leagues' => [
                        [
                            'teams' => [],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-teams')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Team::count())->toBe(0);
});

it('handles missing teams key in response', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams*' => Http::response([
            'sports' => [
                [
                    'leagues' => [
                        [
                            'name' => 'NBA',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-teams')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Team::count())->toBe(0);
});

it('updates existing teams instead of creating duplicates', function () {
    Team::factory()->create([
        'espn_id' => '1',
        'abbreviation' => 'ATL',
        'location' => 'Old Atlanta',
        'name' => 'Old Hawks',
    ]);

    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams*' => Http::response([
            'sports' => [
                [
                    'leagues' => [
                        [
                            'teams' => [
                                [
                                    'team' => [
                                        'id' => '1',
                                        'abbreviation' => 'ATL',
                                        'location' => 'Atlanta',
                                        'name' => 'Hawks',
                                        'displayName' => 'Atlanta Hawks',
                                        'color' => 'c8102e',
                                        'logos' => [
                                            ['href' => 'https://example.com/atl.png'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-teams')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Team::count())->toBe(1);
    expect(Team::first()->location)->toBe('Atlanta');
    expect(Team::first()->name)->toBe('Hawks');
});

it('skips teams without an id', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams*' => Http::response([
            'sports' => [
                [
                    'leagues' => [
                        [
                            'teams' => [
                                [
                                    'team' => [
                                        // Missing 'id' field
                                        'abbreviation' => 'INVALID',
                                        'location' => 'Invalid',
                                        'name' => 'Team',
                                    ],
                                ],
                                [
                                    'team' => [
                                        'id' => '1',
                                        'abbreviation' => 'ATL',
                                        'location' => 'Atlanta',
                                        'name' => 'Hawks',
                                        'displayName' => 'Atlanta Hawks',
                                        'color' => 'c8102e',
                                        'logos' => [
                                            ['href' => 'https://example.com/atl.png'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-teams')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Team::count())->toBe(1);
    expect(Team::first()->abbreviation)->toBe('ATL');
});

it('backfills conference and division from standings when team payload omits them', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams*' => Http::response([
            'sports' => [
                [
                    'leagues' => [
                        [
                            'teams' => [
                                [
                                    'team' => [
                                        'id' => '1',
                                        'abbreviation' => 'ATL',
                                        'location' => 'Atlanta',
                                        'name' => 'Hawks',
                                    ],
                                ],
                                [
                                    'team' => [
                                        'id' => '2',
                                        'abbreviation' => 'DEN',
                                        'location' => 'Denver',
                                        'name' => 'Nuggets',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/1*' => Http::response([
            'team' => [
                'id' => '1',
                'abbreviation' => 'ATL',
                'location' => 'Atlanta',
                'name' => 'Hawks',
            ],
        ]),
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/2*' => Http::response([
            'team' => [
                'id' => '2',
                'abbreviation' => 'DEN',
                'location' => 'Denver',
                'name' => 'Nuggets',
            ],
        ]),
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/standings*' => Http::response([
            'sports' => [[
                'leagues' => [[
                    'name' => 'NBA',
                    'groups' => [
                        [
                            'name' => 'Eastern',
                            'children' => [
                                [
                                    'name' => 'Southeast',
                                    'standings' => [
                                        'entries' => [
                                            ['team' => ['id' => '1']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name' => 'Western',
                            'children' => [
                                [
                                    'name' => 'Northwest',
                                    'standings' => [
                                        'entries' => [
                                            ['team' => ['id' => '2']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
            ]],
        ]),
    ]);

    artisan('espn:sync-nba-teams')->assertSuccessful();
    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    $atl = Team::where('espn_id', '1')->first();
    $den = Team::where('espn_id', '2')->first();

    expect($atl)->not->toBeNull()
        ->conference->toBe('Eastern')
        ->division->toBe('Southeast');

    expect($den)->not->toBeNull()
        ->conference->toBe('Western')
        ->division->toBe('Northwest');
});
