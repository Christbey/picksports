<?php

use App\Jobs\NBA\RefreshOvernightSources;
use App\Models\CommandHeartbeat;
use App\Services\CommandHeartbeatService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

it('gates only the new NBA overnight bridge and retains NFL schedule definitions', function (string $clock, bool $enabled, bool $due) {
    $this->travelTo(CarbonImmutable::parse($clock));
    Http::preventStrayRequests();
    Queue::fake();
    config(['nba.source_bridge.enabled' => false]);
    ScheduleFacade::swap(new Schedule('America/Chicago'));
    require base_path('routes/console.php');
    $nfl = fn () => collect(app(Schedule::class)->events())->filter(fn ($e) => str_starts_with((string) $e->description, 'NFL:'))
        ->map(fn ($e) => [$e->description, $e->command, $e->expression, $e->timezone, $e->onOneServer, $e->withoutOverlapping, $e->expiresAt])->values()->all();
    $before = $nfl();
    config(['nba.source_bridge.enabled' => $enabled]);
    ScheduleFacade::swap(new Schedule('America/Chicago'));
    require base_path('routes/console.php');
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'NBA: Overnight Source Acquisition');
    expect($event->expression)->toBe('12,42 * * * *')->and($event->timezone)->toBe('America/Chicago')
        ->and($event->onOneServer)->toBeTrue()->and($event->withoutOverlapping)->toBeTrue()->and($event->expiresAt)->toBe(12)
        ->and($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->isDue(app()) && $event->filtersPass(app()))->toBe($due)
        ->and($nfl())->toBe($before);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    $this->travelBack();
})->with([
    'default disabled' => ['2026-11-22T00:12:00-06:00', false, false],
    'first nightly dispatch' => ['2026-11-21T23:12:00-06:00', true, true],
    'midnight' => ['2026-11-22T00:12:00-06:00', true, true],
    'last nightly dispatch' => ['2026-11-22T07:42:00-06:00', true, true],
    'morning boundary' => ['2026-11-22T08:12:00-06:00', true, false],
    'daytime' => ['2026-11-22T12:12:00-06:00', true, false],
    'evening boundary' => ['2026-11-21T22:42:00-06:00', true, false],
    'spring before jump' => ['2026-03-08T01:42:00-06:00', true, true],
    'spring after jump' => ['2026-03-08T03:12:00-05:00', true, true],
    'fall first hour' => ['2026-11-01T01:12:00-05:00', true, true],
    'fall repeated hour' => ['2026-11-01T01:12:00-06:00', true, true],
    'off season' => ['2026-08-22T00:12:00-05:00', true, false],
]);

it('dispatches only the dedicated bounded source job and rechecks disabled execution', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22T00:12:00-06:00'));
    config(['nba.source_bridge.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    ScheduleFacade::swap(new Schedule('America/Chicago'));
    require base_path('routes/console.php');
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'NBA: Overnight Source Acquisition');
    $event->run(app());
    Queue::assertPushed(RefreshOvernightSources::class, fn ($job) => $job->connection === 'redis'
        && $job->queue === 'nba-sources' && $job->tries === 1 && $job->timeout === 600 && $job->uniqueFor === 720);
    Queue::assertPushed(RefreshOvernightSources::class, 1);
    config(['nba.source_bridge.enabled' => false]);
    (new RefreshOvernightSources)->handle(app(CommandHeartbeatService::class));
    expect(CommandHeartbeat::count())->toBe(0);
    Http::assertNothingSent();
    $this->travelBack();
});
