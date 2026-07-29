<?php

use App\Models\CommandHeartbeat;
use App\Models\Healthcheck;
use Illuminate\Support\Carbon;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Healthcheck::query()->delete();
    CommandHeartbeat::query()->delete();
});

it('records failing heartbeat checks when no command heartbeats exist', function () {
    artisan('healthcheck:run --sport=nba')->assertFailed();

    $syncCheck = Healthcheck::query()
        ->where('sport', 'nba')
        ->where('check_type', 'heartbeat_sync')
        ->latest('id')
        ->first();

    expect($syncCheck)->not->toBeNull()
        ->status->toBe('failing')
        ->message->toContain('No successful sync pipeline heartbeat');
});

it('records passing heartbeat checks when pipelines are fresh', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 15, 20, 0, 0));

    Healthcheck::query()->create([
        'sport' => 'nba',
        'check_type' => 'heartbeat_sync',
        'status' => 'failing',
        'message' => 'Failure from an earlier healthcheck invocation.',
        'metadata' => [],
        'checked_at' => now(),
    ]);

    CommandHeartbeat::query()->create([
        'sport' => 'nba',
        'command' => 'espn:sync-nba-current',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subMinutes(20),
    ]);

    CommandHeartbeat::query()->create([
        'sport' => 'nba',
        'command' => 'espn:sync-nba-games-scoreboard 20260215',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subMinutes(5),
    ]);

    CommandHeartbeat::query()->create([
        'sport' => 'nba',
        'command' => 'nba:generate-predictions --season=2026',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subHours(2),
    ]);

    CommandHeartbeat::query()->create([
        'sport' => 'nba',
        'command' => 'nba:calculate-elo --season=2026',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subHours(3),
    ]);

    CommandHeartbeat::query()->create([
        'sport' => 'nba',
        'command' => 'nba:sync-odds',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subHours(1),
    ]);

    CommandHeartbeat::query()->create([
        'sport' => 'nba',
        'command' => 'nba:sync-player-props',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subHours(1),
    ]);

    artisan('healthcheck:run --sport=nba')->assertSuccessful();

    $types = [
        'heartbeat_sync',
        'heartbeat_live_scoreboard',
        'heartbeat_prediction_pipeline',
        'heartbeat_model_pipeline',
        'heartbeat_odds',
        'heartbeat_player_props',
    ];

    foreach ($types as $type) {
        $check = Healthcheck::query()
            ->where('sport', 'nba')
            ->where('check_type', $type)
            ->latest('id')
            ->first();

        expect($check)->not->toBeNull();
        expect($check->status)->toBe('passing');
    }

    Carbon::setTestNow();
});

it('treats july nfl heartbeats as in-season and reports positive stale ages', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 29, 18, 0, 0));

    CommandHeartbeat::query()->create([
        'sport' => 'nfl',
        'command' => 'espn:sync-nfl-current',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subMinutes(400),
    ]);

    artisan('healthcheck:run --sport=nfl')->assertFailed();

    $syncCheck = Healthcheck::query()
        ->where('sport', 'nfl')
        ->where('check_type', 'heartbeat_sync')
        ->latest('id')
        ->first();

    expect($syncCheck)->not->toBeNull()
        ->status->toBe('failing')
        ->and(data_get($syncCheck->metadata, 'age_minutes'))->toBe(400);

    Carbon::setTestNow();
});
