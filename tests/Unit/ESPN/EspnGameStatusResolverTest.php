<?php

use App\Support\EspnGameStatusResolver;
use Tests\TestCase;

uses(TestCase::class);

it('does not let schedule finalize an existing non-final game', function () {
    $resolver = new EspnGameStatusResolver;

    expect($resolver->resolveForUpdate('STATUS_SCHEDULED', 'STATUS_FINAL', 'schedule', 'cbb'))
        ->toBe('STATUS_SCHEDULED');
});

it('allows scoreboard to upgrade a game to final', function () {
    $resolver = new EspnGameStatusResolver;

    expect($resolver->resolveForUpdate('STATUS_SCHEDULED', 'STATUS_FINAL', 'scoreboard', 'cbb'))
        ->toBe('STATUS_FINAL');
});

it('does not downgrade stronger statuses from weaker schedule updates', function () {
    $resolver = new EspnGameStatusResolver;

    expect($resolver->resolveForUpdate('STATUS_IN_PROGRESS', 'STATUS_SCHEDULED', 'schedule', 'cfb'))
        ->toBe('STATUS_IN_PROGRESS');
});

it('preserves final status once stored', function () {
    $resolver = new EspnGameStatusResolver;

    expect($resolver->resolveForUpdate('STATUS_FINAL', 'STATUS_SCHEDULED', 'summary', 'nba'))
        ->toBe('STATUS_FINAL');
});

test('college football can resume play after halftime and end of period', function () {
    $resolver = app(EspnGameStatusResolver::class);
    foreach (['STATUS_HALFTIME', 'STATUS_END_PERIOD'] as $status) {
        expect($resolver->resolveForUpdate($status, 'STATUS_IN_PROGRESS', 'summary', 'cfb'))->toBe('STATUS_IN_PROGRESS');
    }
    expect($resolver->resolveForUpdate('STATUS_FINAL', 'STATUS_IN_PROGRESS', 'summary', 'cfb'))->toBe('STATUS_FINAL');
});
