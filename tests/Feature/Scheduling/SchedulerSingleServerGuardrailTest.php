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
