<?php

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses()->group('espn', 'cbb');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create(['espn_id' => '130']);
    $this->awayTeam = Team::factory()->create(['espn_id' => '41']);

    $this->game = Game::factory()->create([
        'espn_event_id' => '401823525',
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 79,
        'away_score' => 73,
    ]);
});

it('syncs CBB plays from the complete core plays endpoint', function () {
    Http::fake([
        '*site.api.espn.com/apis/site/v2/sports/basketball/mens-college-basketball/summary?event=401823525*' => Http::response([
            'header' => [
                'competitions' => [
                    ['id' => '401823525'],
                ],
            ],
            'plays' => [
                [
                    'id' => 'summary-truncated-play',
                    'type' => ['text' => 'Made Shot'],
                    'text' => 'This summary payload should not be used for CBB play sync.',
                    'clock' => ['displayValue' => '19:59'],
                    'period' => ['number' => 1],
                    'homeScore' => 2,
                    'awayScore' => 0,
                ],
            ],
        ]),
        '*sports.core.api.espn.com/v2/sports/basketball/leagues/mens-college-basketball/events/401823525/competitions/401823525/plays?limit=300*' => Http::response([
            'items' => [
                [
                    'id' => '4018235251',
                    'type' => ['text' => 'Made Shot'],
                    'text' => 'Home makes layup',
                    'scoringPlay' => true,
                    'scoreValue' => 2,
                    'clock' => ['displayValue' => '19:30'],
                    'period' => ['number' => 1],
                    'homeScore' => 2,
                    'awayScore' => 0,
                ],
                [
                    'id' => '4018235252',
                    'type' => ['text' => 'Made Shot'],
                    'text' => 'Away makes jumper',
                    'scoringPlay' => true,
                    'scoreValue' => 2,
                    'clock' => ['displayValue' => '00:04'],
                    'period' => ['number' => 2],
                    'homeScore' => 79,
                    'awayScore' => 73,
                ],
            ],
        ]),
    ]);

    artisan('espn:sync-cbb-plays', ['eventId' => '401823525'])
        ->expectsOutput('Dispatching CBB plays sync job for event 401823525...')
        ->assertSuccessful();

    $this->artisan('queue:work', ['--stop-when-empty' => true]);

    expect(Play::count())->toBe(2);
    expect(Play::where('espn_play_id', 'summary-truncated-play')->exists())->toBeFalse();

    $lastPlay = Play::orderByDesc('sequence_number')->first();
    expect($lastPlay)->not->toBeNull()
        ->game_id->toBe($this->game->id)
        ->espn_play_id->toBe('4018235252')
        ->period->toBe(2)
        ->home_score->toBe(79)
        ->away_score->toBe(73);
});
