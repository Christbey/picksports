<?php

use App\Models\NBA\Player;
use App\Models\NBA\Team;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses()->group('espn', 'nba');

beforeEach(function () {
    $this->team = Team::factory()->create(['espn_id' => '1']);
});

it('syncs players from ESPN roster API with flat array', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/1/roster*' => Http::response([
            'athletes' => [
                [
                    'id' => '3112335',
                    'firstName' => 'Trae',
                    'lastName' => 'Young',
                    'fullName' => 'Trae Young',
                    'displayName' => 'Trae Young',
                    'jersey' => '11',
                    'position' => ['abbreviation' => 'PG'],
                    'height' => '6\'1"',
                    'weight' => 164,
                    'age' => 25,
                    'experience' => ['years' => 5],
                    'college' => ['name' => 'Oklahoma'],
                    'birthPlace' => ['city' => 'Lubbock', 'state' => 'TX'],
                    'headshot' => ['href' => 'https://example.com/young.png'],
                ],
                [
                    'id' => '4066636',
                    'firstName' => 'De\'Andre',
                    'lastName' => 'Hunter',
                    'fullName' => 'De\'Andre Hunter',
                    'displayName' => 'De\'Andre Hunter',
                    'jersey' => '12',
                    'position' => ['abbreviation' => 'SF'],
                    'height' => '6\'8"',
                    'weight' => 225,
                    'age' => 26,
                    'experience' => ['years' => 4],
                    'college' => ['name' => 'Virginia'],
                    'birthPlace' => ['city' => 'Philadelphia', 'state' => 'PA'],
                    'headshot' => ['href' => 'https://example.com/hunter.png'],
                ],
                [
                    'id' => '3138156',
                    'firstName' => 'Clint',
                    'lastName' => 'Capela',
                    'fullName' => 'Clint Capela',
                    'displayName' => 'Clint Capela',
                    'jersey' => '15',
                    'position' => ['abbreviation' => 'C'],
                    'height' => '6\'10"',
                    'weight' => 240,
                    'age' => 29,
                    'experience' => ['years' => 9],
                    'birthPlace' => ['city' => 'Geneva', 'country' => 'Switzerland'],
                    'headshot' => ['href' => 'https://example.com/capela.png'],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-players', ['teamEspnId' => '1'])
        ->expectsOutput('Dispatching NBA players sync job for team 1...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Player::count())->toBe(3);

    $guard = Player::where('espn_id', '3112335')->first();
    expect($guard)->not->toBeNull()
        ->first_name->toBe('Trae')
        ->last_name->toBe('Young')
        ->jersey_number->toBe('11')
        ->position->toBe('PG')
        ->team_id->toBe($this->team->id);

    $forward = Player::where('espn_id', '4066636')->first();
    expect($forward)->not->toBeNull()
        ->position->toBe('SF');

    $center = Player::where('espn_id', '3138156')->first();
    expect($center)->not->toBeNull()
        ->position->toBe('C');
});

it('handles empty roster response', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/1/roster*' => Http::response([
            'athletes' => [],
        ]),
    ]);

    artisan('espn:sync-nba-players', ['teamEspnId' => '1'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Player::count())->toBe(0);
});

it('handles missing athletes key in response', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/1/roster*' => Http::response([
            'team' => ['id' => '1', 'name' => 'Hawks'],
        ]),
    ]);

    artisan('espn:sync-nba-players', ['teamEspnId' => '1'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Player::count())->toBe(0);
});

it('updates existing players instead of creating duplicates', function () {
    Player::factory()->create([
        'espn_id' => '3112335',
        'team_id' => $this->team->id,
        'first_name' => 'Old First',
        'last_name' => 'Old Last',
    ]);

    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/1/roster*' => Http::response([
            'athletes' => [
                [
                    'id' => '3112335',
                    'firstName' => 'Trae',
                    'lastName' => 'Young',
                    'fullName' => 'Trae Young',
                    'jersey' => '11',
                    'position' => ['abbreviation' => 'PG'],
                    'height' => '6\'1"',
                    'weight' => 164,
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-players', ['teamEspnId' => '1'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Player::count())->toBe(1);
    expect(Player::first()->first_name)->toBe('Trae');
    expect(Player::first()->last_name)->toBe('Young');
});

it('syncs all teams when no team espn id provided', function () {
    $team2 = Team::factory()->create(['espn_id' => '2']);

    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/1/roster*' => Http::response([
            'athletes' => [
                [
                    'id' => '1001',
                    'firstName' => 'Player',
                    'lastName' => 'One',
                    'fullName' => 'Player One',
                    'position' => ['abbreviation' => 'PG'],
                ],
            ],
        ]),
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/2/roster*' => Http::response([
            'athletes' => [
                [
                    'id' => '2001',
                    'firstName' => 'Player',
                    'lastName' => 'Two',
                    'fullName' => 'Player Two',
                    'position' => ['abbreviation' => 'SF'],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-players')
        ->expectsOutput('Dispatching NBA players sync job for all teams...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Player::count())->toBe(2);
});

it('skips players without an id', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/nba/teams/1/roster*' => Http::response([
            'athletes' => [
                [
                    // Missing 'id' field
                    'firstName' => 'Invalid',
                    'lastName' => 'Player',
                ],
                [
                    'id' => '3112335',
                    'firstName' => 'Trae',
                    'lastName' => 'Young',
                    'fullName' => 'Trae Young',
                    'position' => ['abbreviation' => 'PG'],
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nba-players', ['teamEspnId' => '1'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Player::count())->toBe(1);
    expect(Player::first()->first_name)->toBe('Trae');
});
