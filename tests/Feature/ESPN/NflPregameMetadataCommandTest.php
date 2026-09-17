<?php

use App\Jobs\ESPN\NFL\FetchGameDetails;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->travelTo('2026-09-16 12:00:00'));
afterEach(fn () => $this->travelBack());

function metadataCommandGame(array $attributes = []): Game
{
    return Game::factory()->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-18', 'game_time' => '00:15:00',
        'venue_name' => null, 'venue_city' => null, ...$attributes,
    ]);
}

it('repairs upcoming venues with one summary fetch and no boxscore or play jobs', function () {
    Queue::fake();
    $game = metadataCommandGame(['espn_event_id' => '401872932']);
    metadataCommandGame(['status' => 'STATUS_FINAL']);
    metadataCommandGame(['game_date' => '2026-09-16', 'game_time' => '01:00:00']);
    metadataCommandGame(['game_date' => '2026-10-01']);
    metadataCommandGame(['season_type' => '1']);
    $this->mock(EspnService::class, function ($mock) {
        $mock->shouldReceive('getGame')->once()->with('401872932')->andReturn([
            'header' => ['id' => '401872932'],
            'gameInfo' => ['venue' => ['fullName' => 'Highmark Stadium', 'address' => ['city' => 'Orchard Park', 'state' => 'NY']]],
        ]);
        $mock->shouldNotReceive('getPlays');
    });

    $this->artisan('espn:sync-nfl-pregame-metadata')
        ->expectsOutputToContain('updated 1, unchanged 0, failed 0, deferred 0')->assertSuccessful();

    expect($game->fresh()->venue_city)->toBe('Orchard Park');
    Queue::assertNothingPushed();
});

it('preserves final-only default game-details sweep behavior', function () {
    Queue::fake();
    metadataCommandGame(['espn_event_id' => 'pregame']);
    metadataCommandGame(['espn_event_id' => 'final', 'status' => 'STATUS_FINAL']);

    $this->artisan('espn:sync-nfl-game-details', ['--lookback-days' => 0, '--days-forward' => 8])->assertSuccessful();

    Queue::assertPushed(FetchGameDetails::class, 1);
    Queue::assertPushed(FetchGameDetails::class, fn ($job) => $job->eventId === 'final');
});

it('includes the final business date night game stored on the next UTC date just like weather sync', function () {
    config(['sports.business_timezone' => 'America/Chicago']);
    metadataCommandGame(['espn_event_id' => 'night', 'game_date' => '2026-09-25', 'game_time' => '00:15:00']);
    metadataCommandGame(['espn_event_id' => 'nextday', 'game_date' => '2026-09-25', 'game_time' => '17:00:00']);
    $this->mock(EspnService::class, function ($mock) {
        $mock->shouldReceive('getGame')->once()->with('night')->andReturn([
            'header' => ['id' => 'night'], 'gameInfo' => ['venue' => ['fullName' => 'Stadium', 'address' => ['city' => 'Seattle']]],
        ]);
    });

    $this->artisan('espn:sync-nfl-pregame-metadata', ['--days-forward' => 8])
        ->expectsOutputToContain('updated 1, unchanged 0, failed 0, deferred 0')->assertSuccessful();
});

it('includes postseason metadata and reports provider failure without dropping the rest', function () {
    $first = metadataCommandGame(['espn_event_id' => 'first', 'season_type' => '3']);
    $second = metadataCommandGame(['espn_event_id' => 'second']);
    $this->mock(EspnService::class, function ($mock) {
        $mock->shouldReceive('getGame')->once()->with('first')->andReturnNull();
        $mock->shouldReceive('getGame')->once()->with('second')->andReturn([
            'header' => ['id' => 'second'], 'gameInfo' => ['venue' => ['fullName' => 'Lumen Field', 'address' => ['city' => 'Seattle']]],
        ]);
    });

    $this->artisan('espn:sync-nfl-pregame-metadata')->expectsOutputToContain('updated 1, unchanged 0, failed 1')->assertFailed();
    expect($first->fresh()->venue_name)->toBeNull()->and($second->fresh()->venue_name)->toBe('Lumen Field');
});

it('reports bounded metadata batches as incomplete and prioritizes missing venues next time', function () {
    metadataCommandGame(['espn_event_id' => 'first']);
    metadataCommandGame(['espn_event_id' => 'second']);
    $this->mock(EspnService::class, function ($mock) {
        foreach (['first', 'second'] as $event) {
            $mock->shouldReceive('getGame')->once()->with($event)->andReturn([
                'header' => ['id' => $event], 'gameInfo' => ['venue' => ['fullName' => 'Stadium', 'address' => ['city' => 'Seattle']]],
            ]);
        }
    });

    $this->artisan('espn:sync-nfl-pregame-metadata', ['--limit' => 1])->expectsOutputToContain('deferred 1')->assertFailed();
    $this->artisan('espn:sync-nfl-pregame-metadata', ['--limit' => 1])->expectsOutputToContain('updated 1')->assertFailed();
});

it('schedules pregame metadata before every weather refresh', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    $event = $events->get('NFL: Sync Pregame Metadata');
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('55 5,9,13,17,21 * * *')
        ->and($event->command)->toContain('--days-forward=8 --limit=32')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
