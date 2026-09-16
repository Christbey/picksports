<?php

use App\Actions\Validation\Checks\PipelineOrderCheck;
use App\Models\CommandHeartbeat;
use App\Models\NFL\Game;
use App\Models\NFL\Team;

beforeEach(function () {
    $this->travelTo('2026-09-16 14:00:00');
    Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026,
        'season_type' => 2,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-17',
        'game_time' => '23:15:00',
    ]);
});

afterEach(fn () => $this->travelBack());

function nflDependencyHeartbeat(string $command, string $time): void
{
    CommandHeartbeat::query()->create([
        'sport' => 'nfl', 'command' => $command, 'status' => 'success',
        'source' => 'test', 'ran_at' => $time,
    ]);
}

test('unchanged detail polling and unrelated game odds do not invalidate NFL derived outputs', function () {
    nflDependencyHeartbeat('nfl:calculate-team-metrics --season=2026', '2026-09-16 09:30:00');
    nflDependencyHeartbeat('nfl:sync-game-weather', '2026-09-16 09:45:00');
    nflDependencyHeartbeat('nfl:generate-predictions --season=2026', '2026-09-16 10:00:00');
    nflDependencyHeartbeat('espn:sync-nfl-game-details', '2026-09-16 13:50:00');
    nflDependencyHeartbeat('nfl:sync-odds', '2026-09-16 13:55:00');

    $result = app(PipelineOrderCheck::class)->run('nfl', config('validation.sports.nfl'));

    expect($result['status'])->toBe('passing')
        ->and($result['metadata']['violations'])->toBe([]);
});

test('new NFL inputs remain visible during the bounded refresh window and fail after it', function () {
    nflDependencyHeartbeat('nfl:calculate-team-metrics --season=2026', '2026-09-16 09:30:00');
    nflDependencyHeartbeat('nfl:generate-predictions --season=2026', '2026-09-16 12:20:00');
    nflDependencyHeartbeat('nfl:sync-game-weather', '2026-09-16 13:45:00');

    $check = app(PipelineOrderCheck::class);
    $result = $check->run('nfl', config('validation.sports.nfl'));
    expect($result['status'])->toBe('warning')
        ->and(data_get($result, 'metadata.violations.0.label'))->toBe('weather before predictions');

    $this->travelTo('2026-09-16 15:01:00');
    expect($check->run('nfl', config('validation.sports.nfl'))['status'])->toBe('failing');

    nflDependencyHeartbeat('nfl:generate-predictions --season=2026', '2026-09-16 15:02:00');
    $this->travelTo('2026-09-16 15:03:00');
    expect($check->run('nfl', config('validation.sports.nfl'))['status'])->toBe('passing');
});

test('repeated weather polling cannot extend grace while NFL prediction generation is broken', function () {
    nflDependencyHeartbeat('nfl:calculate-team-metrics --season=2026', '2026-09-15 09:30:00');
    nflDependencyHeartbeat('nfl:generate-predictions --season=2026', '2026-09-15 12:20:00');
    nflDependencyHeartbeat('nfl:sync-game-weather', '2026-09-16 12:00:00');
    nflDependencyHeartbeat('nfl:sync-game-weather', '2026-09-16 13:45:00');

    $result = app(PipelineOrderCheck::class)->run('nfl', config('validation.sports.nfl'));

    expect($result['status'])->toBe('failing')
        ->and(data_get($result, 'metadata.violations.0.first_unconsumed_upstream_at'))->toBe('2026-09-16 12:00:00');
});
