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

    artisan('healthcheck:run --sport=nba')->assertSuccessful();

    $types = [
        'heartbeat_sync',
        'heartbeat_live_scoreboard',
        'heartbeat_prediction_pipeline',
        'heartbeat_model_pipeline',
        'heartbeat_odds',
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
