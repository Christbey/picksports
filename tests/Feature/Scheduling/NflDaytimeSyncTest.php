<?php

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;

it('admits NFL scoreboard and details sync only inside its configured daytime window', function (string $time, bool $admitted) {
    Carbon::setTestNow(Carbon::parse('2026-09-13 '.$time, 'America/Chicago'));
    try {
        // Laravel captures between() at registration, as a fresh schedule:run process does.
        Illuminate\Support\Facades\Schedule::swap(new Schedule('America/Chicago'));
        require base_path('routes/console.php');
        $events = collect(app(Schedule::class)->events())->keyBy('description');
        foreach (['NFL: Live Scoreboard Sync', 'NFL: Sync Game Details'] as $name) {
            $event = $events->get($name);
            expect($event)->not->toBeNull()->and($event->filtersPass(app()))->toBe($admitted);
        }
    } finally {
        Carbon::setTestNow();
    }
})->with([
    ['06:30:00', true],
    ['12:05:00', true],
    ['15:30:00', true],
    ['20:00:00', true],
    ['02:01:00', false],
    ['05:59:00', false],
]);

it('refreshes confirmed game quarterbacks before the daily NFL model pipeline', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-30 08:15:00', 'America/Chicago'));
    try {
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'NFL: Sync Confirmed Game Quarterbacks');
        expect($event)->not->toBeNull()->and($event->expression)->toBe('15 8 * * *')
            ->and($event->command)->toContain('nfl:sync-game-quarterbacks --season=2026 --apply')
            ->and($event->filtersPass(app()))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('refreshes independent matchup plays before daytime forecasts', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-30 08:05:00', 'America/Chicago'));
    try {
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'NFL: Sync Matchup Play-by-Play');
        expect($event)->not->toBeNull()->and($event->expression)->toBe('5 8 * * *')
            ->and($event->command)->toContain('nfl:sync-nflverse-pbp --season=2026')
            ->and($event->filtersPass(app()))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('refreshes charted matchup inputs after the base play import', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-30 08:10:00', 'America/Chicago'));
    try {
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'NFL: Sync Matchup Charting');
        expect($event)->not->toBeNull()->and($event->expression)->toBe('10 8 * * *')
            ->and($event->command)->toContain('nfl:sync-matchup-charting --season=2026')
            ->and($event->filtersPass(app()))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('refreshes NFL depth charts daily before personnel-dependent forecasts', function (string $date) {
    Carbon::setTestNow(Carbon::parse($date.' 07:45:00', 'America/Chicago'));
    try {
        $events = collect(app(Schedule::class)->events())->where('description', 'NFL: Sync Depth Charts');
        expect($events)->toHaveCount(1);
        $event = $events->first();
        expect($event->expression)->toBe('45 7 * * *')
            ->and($event->command)->toContain('espn:sync-nfl-depth-charts --season=2026 --sync')
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->filtersPass(app()))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
})->with(['2026-09-28', '2026-10-01', '2026-10-04']);
