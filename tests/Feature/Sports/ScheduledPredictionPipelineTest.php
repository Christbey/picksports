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

it('schedules shadow decisions and settlement feedback after nba and nfl predictions', function () {
    $commands = collect(app(Schedule::class)->events())
        ->pluck('command')
        ->filter()
        ->values();

    foreach (['nba', 'nfl'] as $sport) {
        expect($commands->contains(
            fn (string $command): bool => str_contains($command, "sports:record-shadow-bet-decisions --sport={$sport}")
        ))->toBeTrue("Missing shadow decision schedule for {$sport}");
        expect($commands->contains(
            fn (string $command): bool => str_contains($command, "sports:settle-bet-decisions --sport={$sport}")
        ))->toBeTrue("Missing settlement feedback schedule for {$sport}");
    }
});
