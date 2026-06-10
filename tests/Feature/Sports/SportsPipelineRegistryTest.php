<?php

use App\Services\Sports\SportsPipelineRegistry;

uses()->group('sports');

it('bootstraps cfb teams synchronously before current week sync', function () {
    $registry = app(SportsPipelineRegistry::class);
    $context = $registry->context(date: '2026-06-10', season: 2026);

    $steps = $registry->pipelineSteps('cfb', 'sync', $context);

    expect($steps[0])
        ->label->toBe('Sync teams')
        ->command->toBe('espn:sync-cfb-teams')
        ->arguments->toBe(['--sync' => true])
        ->and($steps[1])
        ->label->toBe('Sync current week')
        ->command->toBe('espn:sync-cfb-current');
});

it('keeps wcbb regular odds enabled without unsupported futures odds', function () {
    $registry = app(SportsPipelineRegistry::class);
    $context = $registry->context(date: '2026-06-10', season: 2026);

    $commands = collect($registry->pipelineSteps('wcbb', 'sync', $context))
        ->pluck('command')
        ->all();

    expect($commands)
        ->toContain('wcbb:sync-odds')
        ->not->toContain('sports:sync-futures-odds');
});
