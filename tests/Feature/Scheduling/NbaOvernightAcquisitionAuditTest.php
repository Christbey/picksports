<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('exposes the existing NBA injury acquisition window without executing scheduled work', function (string $time, bool $admitted) {
    $this->travelTo(CarbonImmutable::parse($time));
    Http::preventStrayRequests();
    Queue::fake();
    // Laravel captures between()'s clock when the schedule is registered.
    // Rebuild at the simulated minute, as a fresh schedule:run process does.
    Illuminate\Support\Facades\Schedule::swap(new Schedule('America/Chicago'));
    require base_path('routes/console.php');
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'NBA: Sync Injuries');
    expect($event->expression)->toBe('2,32 * * * *')->and($event->timezone)->toBe('America/Chicago')
        ->and($event->onOneServer)->toBeTrue()->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->isDue(app()) && $event->filtersPass(app()))->toBe($admitted);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    $this->travelBack();
})->with([
    'last evening dispatch' => ['2026-11-21T22:32:00-06:00', true],
    'evening gap begins' => ['2026-11-21T23:02:00-06:00', false],
    'after midnight' => ['2026-11-22T00:02:00-06:00', false],
    'overnight' => ['2026-11-22T03:32:00-06:00', false],
    'before morning dispatch' => ['2026-11-22T07:32:00-06:00', false],
    'first morning dispatch' => ['2026-11-22T08:02:00-06:00', true],
    'spring DST before jump' => ['2026-03-08T01:32:00-06:00', false],
    'spring DST after jump' => ['2026-03-08T03:02:00-05:00', false],
    'fall DST first hour' => ['2026-11-01T01:32:00-05:00', false],
    'fall DST repeated hour' => ['2026-11-01T01:32:00-06:00', false],
]);
