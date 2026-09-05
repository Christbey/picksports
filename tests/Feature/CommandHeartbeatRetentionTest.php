<?php

use App\Models\CommandHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

test('command heartbeat pruning respects configured retention and supports a pretend run', function () {
    $this->travelTo('2026-08-12 12:00:00');
    config()->set('retention.command_heartbeats_days', 30);

    $expired = CommandHeartbeat::query()->create([
        'command' => 'mlb:expired-heartbeat',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subDays(31),
    ]);
    $retained = CommandHeartbeat::query()->create([
        'command' => 'mlb:retained-heartbeat',
        'status' => 'success',
        'source' => 'schedule',
        'ran_at' => now()->subDays(29),
    ]);

    $pretendExitCode = Artisan::call('model:prune', [
        '--model' => [CommandHeartbeat::class],
        '--pretend' => true,
    ]);
    $pretendOutput = Artisan::output();

    expect($pretendExitCode)->toBe(0)
        ->and($pretendOutput)->toContain('1 [App\\Models\\CommandHeartbeat] records will be pruned')
        ->and(CommandHeartbeat::query()->count())->toBe(2)
        ->and(Schema::hasIndex('command_heartbeats', ['ran_at']))->toBeTrue();

    $pruneExitCode = Artisan::call('model:prune', [
        '--model' => [CommandHeartbeat::class],
    ]);

    expect($pruneExitCode)->toBe(0)
        ->and(CommandHeartbeat::query()->find($expired->id))->toBeNull()
        ->and(CommandHeartbeat::query()->find($retained->id))->not->toBeNull();

    $this->travelBack();
});
