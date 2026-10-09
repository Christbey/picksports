<?php

use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;

beforeEach(function () {
    $this->travelTo('2026-10-09 17:00:00');
    $home = Team::factory()->create(['division' => 'FBS']);
    $away = Team::factory()->create(['division' => 'FBS']);
    $this->game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'espn_event_id' => '401856808', 'season' => 2026, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-10-10', 'game_time' => '04:00:00', 'home_score' => null, 'away_score' => null]);
    $this->payload = ['header' => ['id' => '401856808', 'season' => ['year' => 2026],
        'competitions' => [['date' => '2026-10-11T02:30Z', 'dateValid' => true,
            'status' => ['type' => ['name' => 'STATUS_SCHEDULED', 'detail' => 'Sat, October 10th at 10:30 PM EDT']]]]],
        'gameInfo' => ['venue' => ['fullName' => 'Mountain America Stadium', 'address' => ['city' => 'Tempe', 'state' => 'AZ']]]];
});

afterEach(function () {
    $this->travelBack();
});

it('replaces imported placeholders with verified kickoff times and updates the canonical event', function () {
    $this->mock(EspnService::class)->shouldReceive('getGame')->once()->with('401856808')->andReturn($this->payload);
    expect(app(SyncGameMetadata::class)->execute($this->game))->toBeTrue();
    $game = $this->game->fresh();
    expect($game->game_date->toDateString())->toBe('2026-10-11')->and($game->game_time)->toBe('02:30:00')
        ->and($game->venue_city)->toBe('Tempe')->and($game->home_score)->toBeNull()->and($game->away_score)->toBeNull()
        ->and($game->sportEvent->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-11 02:30:00');
});

it('refreshes verified source age even when provider metadata is unchanged', function () {
    $this->mock(EspnService::class)->shouldReceive('getGame')->twice()->andReturn($this->payload);
    $sync = app(SyncGameMetadata::class);
    $sync->execute($this->game);
    $this->travel(9)->hours();
    $sync->execute($this->game);
    expect($this->game->fresh()->updated_at->equalTo(now()))->toBeTrue();
});

it('refuses wrong identities, wrong seasons, unconfirmed times and non pregame summaries', function (string $field, mixed $value) {
    data_set($this->payload, $field, $value);
    $this->mock(EspnService::class)->shouldReceive('getGame')->once()->andReturn($this->payload);
    $before = $this->game->fresh()->getRawOriginal();
    expect(fn () => app(SyncGameMetadata::class)->execute($this->game))->toThrow(RuntimeException::class);
    expect($this->game->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['header.id', 'another-event'], ['header.season.year', 2025],
    ['header.competitions.0.timeValid', false], ['header.competitions.0.status.type.detail', '10/10 - TBD'],
    ['header.competitions.0.status.type.name', 'STATUS_FINAL'],
]);

it('runs standalone without requesting plays or box scores', function () {
    $this->mock(EspnService::class)->shouldReceive('getGame')->once()->with('401856808')->andReturn($this->payload);
    $this->artisan('espn:sync-cfb-pregame-metadata', ['--season' => 2026, '--game-id' => $this->game->id])
        ->expectsOutput('CFB pregame metadata: verified 1, failed 0, deferred 0.')->assertSuccessful();
});

it('refuses a summary whose team identity disagrees with the stored matchup', function () {
    data_set($this->payload, 'header.competitions.0.competitors', [
        ['homeAway' => 'home', 'team' => ['id' => 'wrong-home-team']],
    ]);
    $this->mock(EspnService::class)->shouldReceive('getGame')->once()->andReturn($this->payload);
    expect(fn () => app(SyncGameMetadata::class)->execute($this->game))
        ->toThrow(RuntimeException::class, 'team identity');
    expect($this->game->fresh()->game_time)->toBe('04:00:00');
});
