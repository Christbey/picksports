<?php

use App\Models\CommandHeartbeat;
use App\Services\CommandHeartbeatService;

it('clamps heartbeat source labels to the database column size', function () {
    app(CommandHeartbeatService::class)->recordSuccess(
        command: 'espn:sync-mlb-game-details eventId=401815711 --queue=sync',
        sport: 'mlb',
        source: 'operations-sentinel-validation-repair',
    );

    $heartbeat = CommandHeartbeat::query()->firstOrFail();

    expect($heartbeat->source)
        ->toHaveLength(20)
        ->toBe('operations-sentinel-');
});
