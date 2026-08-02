<?php

use Illuminate\Console\Scheduling\Schedule;

uses()->group('sports');

it('registers every configured sport prediction pipeline stage', function () {
    $commands = collect(app(Schedule::class)->events())
        ->pluck('command')
        ->filter()
        ->values();

    foreach (['nba', 'nfl', 'mlb', 'cbb', 'wcbb', 'wnba', 'cfb'] as $sport) {
        foreach (['grade-predictions', 'calculate-elo', 'calculate-team-metrics', 'generate-predictions'] as $stage) {
            expect($commands->contains(
                fn (string $command): bool => str_contains($command, "{$sport}:{$stage} --season=")
            ))->toBeTrue("Missing scheduled command for {$sport}:{$stage}");
        }
    }
});

it('schedules shadow decisions and settlement feedback after nba nfl and mlb predictions', function () {
    $events = collect(app(Schedule::class)->events());
    $commands = $events->pluck('command')->filter()->values();

    foreach (['nba', 'nfl', 'mlb'] as $sport) {
        expect($commands->contains(
            fn (string $command): bool => str_contains($command, "sports:record-shadow-bet-decisions --sport={$sport}")
        ))->toBeTrue("Missing shadow decision schedule for {$sport}");
        expect($commands->contains(
            fn (string $command): bool => str_contains($command, "sports:settle-bet-decisions --sport={$sport}")
        ))->toBeTrue("Missing settlement feedback schedule for {$sport}");
    }

    expect($commands->contains(
        fn (string $command): bool => str_contains($command, 'mlb:backtest-pick-candidates --season=')
    ))->toBeTrue('Missing MLB pick-candidate grading schedule');

    $mlbPickGrading = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'mlb:backtest-pick-candidates --season=')
    );
    $mlbSettlement = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'sports:settle-bet-decisions --sport=mlb')
    );
    $mlbInference = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'mlb:run-tabular-shadow --skip-decisions')
    );
    $mlbPeriodInference = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'mlb:run-period-shadow --skip-decisions')
    );
    $mlbShadow = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'sports:record-shadow-bet-decisions --sport=mlb')
    );

    expect($mlbPickGrading?->expression)->toBe('40 4 * * *')
        ->and($mlbSettlement?->expression)->toBe('50 4 * * *')
        ->and($mlbInference?->expression)->toBe('10 6 * * *')
        ->and($mlbInference?->withoutOverlapping)->toBeTrue()
        ->and($mlbInference?->runInBackground)->toBeTrue()
        ->and($mlbPeriodInference?->expression)->toBe('12 6 * * *')
        ->and($mlbPeriodInference?->withoutOverlapping)->toBeTrue()
        ->and($mlbPeriodInference?->runInBackground)->toBeTrue()
        ->and($mlbShadow?->expression)->toBe('15 6 * * *');

    $reflection = new ReflectionObject($mlbInference);
    $filters = $reflection->getProperty('filters');
    $afterCallbacks = $reflection->getProperty('afterCallbacks');

    expect($filters->getValue($mlbInference))->not->toBeEmpty()
        ->and($afterCallbacks->getValue($mlbInference))->toHaveCount(2);
});

it('refreshes MLB snapshots shadow inference and decisions after every odds sync window', function () {
    $events = collect(app(Schedule::class)->events());

    $predictionRefresh = $events->first(
        fn ($event): bool => $event->description === 'MLB: Refresh Predictions After Odds Sync'
    );
    $shadowRefresh = $events->first(
        fn ($event): bool => $event->description === 'MLB: Refresh Tabular Shadow After Odds Sync'
    );
    $periodRefresh = $events->first(
        fn ($event): bool => $event->description === 'MLB: Refresh F3/F5 Shadow After Odds Sync'
    );
    $decisionRefresh = $events->first(
        fn ($event): bool => $event->description === 'MLB: Record Refreshed Shadow Decisions'
    );

    expect((string) $predictionRefresh?->command)
        ->toContain('mlb:generate-predictions --season=')
        ->and($predictionRefresh?->expression)->toBe('30 8,12,16,20 * * *')
        ->and((string) $shadowRefresh?->command)->toContain('mlb:run-tabular-shadow --skip-decisions')
        ->and($shadowRefresh?->expression)->toBe('50 8,12,16,20 * * *')
        ->and((string) $periodRefresh?->command)->toContain('mlb:run-period-shadow --skip-decisions')
        ->and($periodRefresh?->expression)->toBe('55 8,12,16,20 * * *')
        ->and((string) $decisionRefresh?->command)->toContain('sports:record-shadow-bet-decisions --sport=mlb')
        ->and($decisionRefresh?->expression)->toBe('58 8,12,16,20 * * *');

    foreach ([$predictionRefresh, $shadowRefresh, $periodRefresh, $decisionRefresh] as $event) {
        expect($event)->not->toBeNull()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->runInBackground)->toBeTrue();

        $reflection = new ReflectionObject($event);
        $filters = $reflection->getProperty('filters');
        $afterCallbacks = $reflection->getProperty('afterCallbacks');

        expect($filters->getValue($event))->not->toBeEmpty()
            ->and($afterCallbacks->getValue($event))->toHaveCount(2);
    }
});

it('schedules bounded single-server weekly model training for MLB and NFL', function () {
    $events = collect(app(Schedule::class)->events());
    $mlb = $events->first(
        fn ($event): bool => $event->description === 'MLB: Weekly Model Challenger Training'
    );
    $nfl = $events->first(
        fn ($event): bool => $event->description === 'NFL: Weekly Model Challenger Training'
    );
    $mlbPeriod = $events->first(
        fn ($event): bool => $event->description === 'MLB: Weekly F3/F5 Model Training'
    );

    expect($mlb)->not->toBeNull()
        ->and((string) $mlb?->command)->toContain('sports:train-weekly-model-challenger mlb')
        ->and($mlb?->expression)->toBe('40 6 * * 1')
        ->and($mlb?->timezone)->toBe('America/Chicago')
        ->and($mlb?->withoutOverlapping)->toBeTrue()
        ->and($mlb?->expiresAt)->toBe(360)
        ->and($mlb?->onOneServer)->toBeTrue()
        ->and($mlb?->runInBackground)->toBeTrue()
        ->and($mlbPeriod)->not->toBeNull()
        ->and((string) $mlbPeriod?->command)->toContain('mlb:train-period-models --from-season=2021')
        ->and($mlbPeriod?->expression)->toBe('20 7 * * 1')
        ->and($mlbPeriod?->timezone)->toBe('America/Chicago')
        ->and($mlbPeriod?->withoutOverlapping)->toBeTrue()
        ->and($mlbPeriod?->expiresAt)->toBe(360)
        ->and($mlbPeriod?->onOneServer)->toBeTrue()
        ->and($mlbPeriod?->runInBackground)->toBeTrue()
        ->and($nfl)->not->toBeNull()
        ->and((string) $nfl?->command)->toContain('sports:train-weekly-model-challenger nfl')
        ->and($nfl?->expression)->toBe('40 12 * * 2')
        ->and($nfl?->timezone)->toBe('America/Chicago')
        ->and($nfl?->withoutOverlapping)->toBeTrue()
        ->and($nfl?->expiresAt)->toBe(360)
        ->and($nfl?->onOneServer)->toBeTrue()
        ->and($nfl?->runInBackground)->toBeTrue();
});
