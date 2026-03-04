<?php

use App\Models\WNBA\Team;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses()->group('espn', 'wnba');

it('backfills conference and division from standings when WNBA team payload omits them', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/wnba/teams*' => Http::response([
            'sports' => [[
                'leagues' => [[
                    'teams' => [
                        [
                            'team' => [
                                'id' => '101',
                                'abbreviation' => 'NY',
                                'location' => 'New York',
                                'name' => 'Liberty',
                            ],
                        ],
                        [
                            'team' => [
                                'id' => '102',
                                'abbreviation' => 'LV',
                                'location' => 'Las Vegas',
                                'name' => 'Aces',
                            ],
                        ],
                    ],
                ]],
            ]],
        ]),
        '*site.api.espn.com/apis/site/v2/sports/basketball/wnba/teams/101*' => Http::response([
            'team' => [
                'id' => '101',
                'abbreviation' => 'NY',
                'location' => 'New York',
                'name' => 'Liberty',
            ],
        ]),
        '*site.api.espn.com/apis/site/v2/sports/basketball/wnba/teams/102*' => Http::response([
            'team' => [
                'id' => '102',
                'abbreviation' => 'LV',
                'location' => 'Las Vegas',
                'name' => 'Aces',
            ],
        ]),
        '*site.api.espn.com/apis/site/v2/sports/basketball/wnba/standings*' => Http::response([
            'sports' => [[
                'leagues' => [[
                    'name' => 'WNBA',
                    'groups' => [
                        [
                            'name' => 'Eastern',
                            'standings' => [
                                'entries' => [
                                    ['team' => ['id' => '101']],
                                ],
                            ],
                        ],
                        [
                            'name' => 'Western',
                            'standings' => [
                                'entries' => [
                                    ['team' => ['id' => '102']],
                                ],
                            ],
                        ],
                    ],
                ]],
            ]],
        ]),
    ]);

    artisan('espn:sync-wnba-teams')
        ->expectsOutput('Dispatching WNBA teams sync job...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    $ny = Team::where('espn_id', '101')->first();
    $lv = Team::where('espn_id', '102')->first();

    expect($ny)->not->toBeNull()
        ->conference->toBe('Eastern')
        ->division->toBe('Eastern');

    expect($lv)->not->toBeNull()
        ->conference->toBe('Western')
        ->division->toBe('Western');
});
