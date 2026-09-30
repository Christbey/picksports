<?php

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;

it('allows NFL scoreboard and details sync during international and noon games', function (string $time) {
    Carbon::setTestNow(Carbon::parse('2026-09-13 '.$time, 'America/Chicago'));
    try {
        $events = collect(app(Schedule::class)->events())->keyBy('description');
        foreach (['NFL: Live Scoreboard Sync', 'NFL: Sync Game Details'] as $name) {
            $event = $events->get($name);
            expect($event)->not->toBeNull()->and($event->filtersPass(app()))->toBeTrue();
        }
    } finally {
        Carbon::setTestNow();
    }
})->with(['06:30:00', '12:05:00', '15:30:00', '20:00:00']);

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
