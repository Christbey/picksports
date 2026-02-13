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
        ->school->toBe('Atlanta')
        ->mascot->toBe('Hawks');

    $bos = Team::where('espn_id', '2')->first();
    expect($bos)->not->toBeNull()
        ->abbreviation->toBe('BOS')
        ->school->toBe('Boston')
        ->mascot->toBe('Celtics');
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
        'school' => 'Old Atlanta',
        'mascot' => 'Old Hawks',
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
    expect(Team::first()->school)->toBe('Atlanta');
    expect(Team::first()->mascot)->toBe('Hawks');
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
