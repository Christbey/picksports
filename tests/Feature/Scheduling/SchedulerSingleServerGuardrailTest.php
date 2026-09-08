<?php

use Illuminate\Console\Scheduling\Schedule;

uses()->group('scheduling');

it('runs every scheduled command on only one server', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events)->not->toBeEmpty();

    $unguardedEvents = $events
        ->reject(fn ($event): bool => $event->onOneServer === true)
        ->map(fn ($event): string => $event->description ?? (string) $event->command)
        ->values();

    expect($unguardedEvents)->toBeEmpty();
});

it('prunes retained command heartbeats once daily on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => $event->description === 'Maintenance: Prune Command Heartbeats'
    );

    expect($event)->not->toBeNull()
        ->and((string) $event?->command)->toContain('model:prune --model=App\\Models\\CommandHeartbeat')
        ->and($event?->expression)->toBe('25 3 * * *')
        ->and($event?->onOneServer)->toBeTrue()
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->runInBackground)->toBeTrue();
});

it('runs odds refreshes in the foreground with bounded overlap locks', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_ends_with((string) $event->description, 'Sync Odds'))
        ->values();

    expect($events)->toHaveCount(7);

    foreach ($events as $event) {
        expect($event->onOneServer)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(60)
            ->and($event->runInBackground)->toBeFalse();
    }
});

it('bounds nfl pipeline locks so a terminated cloud child cannot suppress a full day', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    $expectedLockMinutes = [
        'NFL: Sync Current Week' => 120,
        'NFL: Live Scoreboard Sync' => 10,
        'NFL: Sync Game Details' => 25,
        'NFL: Generate Predictions' => 120,
        'NFL: Sync Player Props' => 120,
        'NFL: Sync Injuries' => 25,
    ];

    foreach ($expectedLockMinutes as $name => $minutes) {
        $event = $events->get($name);

        expect($event)->not->toBeNull()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe($minutes);
    }
});

it('batches nfl web research and context analysis across the upcoming slate', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    $research = $events->get('NFL: Research Sourced Game Context');
    $analysis = $events->get('NFL: Context-Aware AI Prediction Analysis');

    expect((string) $research?->command)
        ->toContain('nfl:research-game-context')
        ->toContain('--days-forward=7')
        ->toContain('--limit=4')
        ->toContain('--retry-rate-limit=2')
        ->and($research?->expression)->toBe('35 8,11,14,17,20 * * *')
        ->and($research?->expiresAt)->toBe(60)
        ->and((string) $analysis?->command)
        ->toContain('sports:ai-daily-predictions')
        ->toContain('--days-forward=7')
        ->toContain('--limit=4')
        ->not->toContain('--force')
        ->and($analysis?->expression)->toBe('50 8,11,14,17,20 * * *')
        ->and($analysis?->expiresAt)->toBe(60);
});
