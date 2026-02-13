<?php

use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses()->group('espn', 'nfl');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['espn_id' => '22']);
    $this->awayTeam = Team::factory()->create(['espn_id' => '15']);

    $this->game = Game::factory()->create([
        'espn_event_id' => '401631718',
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
    ]);
});

it('syncs football plays from ESPN API', function () {
    Http::fake([
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/events/401631718/competitions/401631718/plays*' => Http::response([
            'items' => [
                [
                    'id' => '4016317181',
                    'type' => ['text' => 'Rush', 'id' => '5'],
                    'text' => 'P.Mahomes rush to the left for 5 yards',
                    'start' => [
                        'down' => 1,
                        'distance' => 10,
                        'yardsToEndzone' => 75,
                    ],
                    'statYardage' => 5,
                    'scoringPlay' => false,
                    'clock' => ['displayValue' => '14:30'],
                    'period' => ['number' => 1],
                    'homeScore' => 0,
                    'awayScore' => 0,
                ],
                [
                    'id' => '4016317182',
                    'type' => ['text' => 'Pass', 'id' => '24'],
                    'text' => 'P.Mahomes pass complete to T.Kelce for 15 yards, TOUCHDOWN',
                    'start' => [
                        'down' => 2,
                        'distance' => 5,
                        'yardsToEndzone' => 70,
                    ],
                    'statYardage' => 15,
                    'scoringPlay' => true,
                    'clock' => ['displayValue' => '14:10'],
                    'period' => ['number' => 1],
                    'homeScore' => 6,
                    'awayScore' => 0,
                ],
                [
                    'id' => '4016317183',
                    'type' => ['text' => 'Penalty', 'id' => '24'],
                    'text' => 'False Start on Kansas City',
                    'start' => [
                        'down' => 1,
                        'distance' => 10,
                    ],
                    'scoringPlay' => false,
                    'clock' => ['displayValue' => '13:45'],
                    'period' => ['number' => 1],
                    'homeScore' => 6,
                    'awayScore' => 0,
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nfl-plays', ['eventId' => '401631718'])
        ->expectsOutput('Dispatching NFL plays sync job for event 401631718...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(3);

    // Assert rushing play
    $rushPlay = Play::where('espn_play_id', '4016317181')->first();
    expect($rushPlay)->not->toBeNull()
        ->game_id->toBe($this->game->id)
        ->sequence_number->toBe(1)
        ->play_type->toBe('Rush')
        ->play_text->toBe('P.Mahomes rush to the left for 5 yards')
        ->down->toBe(1)
        ->distance->toBe(10)
        ->yards_to_endzone->toBe(75)
        ->yards_gained->toBe(5)
        ->is_scoring_play->toBe(false)
        ->clock->toBe('14:30')
        ->period->toBe(1)
        ->home_score->toBe(0)
        ->away_score->toBe(0);

    // Assert touchdown play
    $touchdownPlay = Play::where('espn_play_id', '4016317182')->first();
    expect($touchdownPlay)->not->toBeNull()
        ->play_type->toBe('Pass')
        ->is_scoring_play->toBe(true)
        ->yards_gained->toBe(15)
        ->home_score->toBe(6);

    // Assert penalty play
    $penaltyPlay = Play::where('espn_play_id', '4016317183')->first();
    expect($penaltyPlay)->not->toBeNull()
        ->play_type->toBe('Penalty')
        ->is_penalty->toBe(true);
});

it('handles empty plays response', function () {
    Http::fake([
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/events/401631718/competitions/401631718/plays*' => Http::response([
            'items' => [],
        ]),
    ]);

    artisan('espn:sync-nfl-plays', ['eventId' => '401631718'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(0);
});

it('handles missing items key in response', function () {
    Http::fake([
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/events/401631718/competitions/401631718/plays*' => Http::response([
            'event' => ['id' => '401631718'],
        ]),
    ]);

    artisan('espn:sync-nfl-plays', ['eventId' => '401631718'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(0);
});

it('updates existing plays instead of creating duplicates', function () {
    Play::factory()->create([
        'espn_play_id' => '4016317181',
        'game_id' => $this->game->id,
        'play_text' => 'Old play description',
    ]);

    Http::fake([
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/events/401631718/competitions/401631718/plays*' => Http::response([
            'items' => [
                [
                    'id' => '4016317181',
                    'type' => ['text' => 'Rush'],
                    'text' => 'Updated play description',
                    'start' => ['down' => 1, 'distance' => 10],
                    'scoringPlay' => false,
                    'clock' => ['displayValue' => '14:30'],
                    'period' => ['number' => 1],
                    'homeScore' => 0,
                    'awayScore' => 0,
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nfl-plays', ['eventId' => '401631718'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(1);
    expect(Play::first()->play_text)->toBe('Updated play description');
});

it('skips plays without an id', function () {
    Http::fake([
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/events/401631718/competitions/401631718/plays*' => Http::response([
            'items' => [
                [
                    // Missing 'id' field
                    'type' => ['text' => 'Invalid'],
                    'text' => 'Invalid play',
                ],
                [
                    'id' => '4016317181',
                    'type' => ['text' => 'Rush'],
                    'text' => 'Valid play',
                    'start' => ['down' => 1, 'distance' => 10],
                    'scoringPlay' => false,
                    'clock' => ['displayValue' => '14:30'],
                    'period' => ['number' => 1],
                    'homeScore' => 0,
                    'awayScore' => 0,
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nfl-plays', ['eventId' => '401631718'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(1);
    expect(Play::first()->play_text)->toBe('Valid play');
});

it('handles plays with optional fields missing', function () {
    Http::fake([
        '*sports.core.api.espn.com/v2/sports/football/leagues/nfl/events/401631718/competitions/401631718/plays*' => Http::response([
            'items' => [
                [
                    'id' => '4016317181',
                    'type' => ['text' => 'Kickoff'],
                    'text' => 'Kickoff play',
                    // Missing start/end details, yards, etc.
                    'scoringPlay' => false,
                    'clock' => ['displayValue' => '15:00'],
                    'period' => ['number' => 1],
                    'homeScore' => 0,
                    'awayScore' => 0,
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-nfl-plays', ['eventId' => '401631718'])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(1);

    $play = Play::first();
    expect($play->play_type)->toBe('Kickoff')
        ->and($play->down)->toBeNull()
        ->and($play->yards_gained)->toBeNull();
});
