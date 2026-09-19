<?php

use App\Actions\ESPN\CFB\SyncGamesFromSchedule;
use App\Services\ESPN\CFB\EspnService;

test('opponent history command resolves the CFB-specific schedule client', function () {
    $action = app(SyncGamesFromSchedule::class);
    $service = new ReflectionProperty($action, 'espnService');
    expect($service->getValue($action))->toBeInstanceOf(EspnService::class);
    $this->artisan('cfb:sync-opponent-history', ['--season' => 2026])->assertSuccessful();
});

test('opponent history command rejects an unbounded refresh', function () {
    $this->artisan('cfb:sync-opponent-history', ['--days' => 999])->assertFailed();
});
