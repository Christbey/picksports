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
        ->label->toBe('Bootstrap CFBD mappings when empty')
        ->command->toBe('cfbd:populate-team-mappings')
        ->arguments->toBe(['--if-empty' => true])
        ->and($steps[2])
        ->label->toBe('Bootstrap schedules when empty')
        ->command->toBe('espn:sync-cfb-schedules')
        ->arguments->toBe([
            '--season' => 2026,
            '--if-empty' => true,
        ])
        ->and($steps[3])
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

it('generates MLB daily picks after the canonical prediction pipeline', function () {
    $registry = app(SportsPipelineRegistry::class);
    $context = $registry->context(date: '2026-06-20', season: 2026);

    $steps = collect($registry->pipelineSteps('mlb', 'predict', $context));
    $dailyPicks = $steps->firstWhere('command', 'mlb:generate-daily-picks');

    expect($steps->pluck('command')->all())
        ->toContain('mlb:generate-predictions')
        ->toContain('mlb:generate-daily-picks')
        ->and($steps->search(fn (array $step): bool => $step['command'] === 'mlb:generate-daily-picks'))
        ->toBeGreaterThan($steps->search(fn (array $step): bool => $step['command'] === 'mlb:generate-predictions'))
        ->and($dailyPicks['arguments'])
        ->toMatchArray([
            '--date' => '2026-06-20',
            '--season' => 2026,
        ]);
});

it('uses the canonical-only NFL pregame pipeline and its shared eight day horizon', function () {
    $registry = app(SportsPipelineRegistry::class);
    $context = $registry->context(date: '2026-09-06', season: 2026);

    $predictionStep = collect($registry->pipelineSteps('nfl', 'predict', $context))
        ->firstWhere('command', 'nfl:run-pregame-pipeline');

    expect($predictionStep['arguments'])->toBe([
        '--season' => 2026,
        '--date' => '2026-09-06',
        '--days-forward' => 8,
    ]);
});

it('retires CFB automatic legacy calibration only with the matching generation cutover', function (bool $pipeline, bool $reads, bool $retired, string $mode) {
    config(['prediction_lifecycle.canonical_pipeline.cfb' => $pipeline, 'prediction_lifecycle.canonical_reads.cfb' => $reads]);
    $registry = app(SportsPipelineRegistry::class);
    $steps = collect($registry->pipelineSteps('cfb', $mode, $registry->context(date: '2026-10-30', season: 2026, week: 9)));
    $commands = $steps->pluck('command')->all();
    expect(in_array('cfb:update-adaptive-calibration', $commands, true))->toBe(! $retired)
        ->and(in_array('cfb:generate-canonical-predictions', $commands, true))->toBe($retired)
        ->and(in_array('cfb:generate-predictions', $commands, true))->toBe(! $retired)
        ->and($commands)->toContain('cfb:grade-predictions', 'cfb:import-fpi', 'cfb:calculate-team-metrics')
        ->and($steps->firstWhere('command', 'cfb:import-fpi')['arguments'])->toMatchArray(['--season' => 2026, '--week' => 9, '--require-complete' => true]);
})->with([
    'compatibility predict' => [false, false, false, 'predict'],
    'pipeline-only predict' => [true, false, false, 'predict'],
    'reads-only predict' => [false, true, false, 'predict'],
    'canonical predict' => [true, true, true, 'predict'],
    'compatibility full' => [false, false, false, 'full'],
    'pipeline-only full' => [true, false, false, 'full'],
    'reads-only full' => [false, true, false, 'full'],
    'canonical full' => [true, true, true, 'full'],
]);
