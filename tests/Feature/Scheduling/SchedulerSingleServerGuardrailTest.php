<?php

use App\Models\CommandHeartbeat;
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

it('staggers provider syncs instead of starting every sport together', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');

    expect($events->get('MLB: Live Scoreboard Sync')?->expression)->toBe('0-59/5 * * * *')
        ->and($events->get('WNBA: Live Scoreboard Sync')?->expression)->toBe('1-59/5 * * * *')
        ->and($events->get('NFL: Live Scoreboard Sync')?->expression)->toBe('3-59/5 * * * *')
        ->and($events->get('CFB: Live Scoreboard Sync')?->expression)->toBe('4-59/5 * * * *')
        ->and($events->get('MLB: Sync Odds')?->expression)->toBe('0 8,12,16,20 * * *')
        ->and($events->get('NFL: Sync Odds')?->expression)->toBe('10 8,12,16,20 * * *')
        ->and($events->get('CFB: Sync Game Details')?->expression)->toBe('24,54 * * * *')
        ->and($events->get('CFB: Sync Injuries')?->expression)->toBe('26,56 * * * *')
        ->and($events->get('MLB: Sync Game Details')?->expression)->toBe('12,42 * * * *')
        ->and($events->get('MLB: Sync Injuries')?->expression)->toBe('14,44 * * * *')
        ->and($events->get('MLB: Refresh Probable Pitchers')?->expression)->toBe('17,47 * * * *');
});

it('keeps routine sentinels observational and bounds expensive prediction work', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');

    expect((string) $events->get('MLB: Operations Sentinel')?->command)
        ->toContain('--validate-only')
        ->toContain('--skip-ai-review')
        ->and((string) $events->get('NFL: Sync Current Week')?->command)->toContain('--skip-teams')
        ->and((string) $events->get('CFB: Sync Current Week')?->command)->toContain('--skip-teams')
        ->and((string) $events->get('NFL: Generate Predictions')?->command)
        ->toContain('--from-date=')
        ->toContain('--to-date=')
        ->and((string) $events->get('NFL: Generate Canonical Predictions')?->command)->toContain('--days-forward=8')
        ->and((string) $events->get('CFB: Generate Canonical Predictions')?->command)
        ->toContain('--week=')
        ->toContain('--days-forward=8');
});

it('runs incremental epa and stale ai reconciliation in bounded foreground passes', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    $nflEpa = $events->get('NFL: Calculate Incremental Play EPA');
    $aiReconciliation = $events->get('Maintenance: Reconcile Stale AI Generations');

    expect((string) $nflEpa?->command)
        ->toContain('nfl:calculate-play-epa')
        ->toContain('--limit=4')
        ->and($nflEpa?->expression)->toBe('22,52 * * * *')
        ->and($nflEpa?->expiresAt)->toBe(20)
        ->and($nflEpa?->runInBackground)->toBeFalse()
        ->and((string) $aiReconciliation?->command)->toContain('--minutes=15')
        ->and($aiReconciliation?->expression)->toBe('42 * * * *')
        ->and($aiReconciliation?->runInBackground)->toBeFalse();
});

it('records scheduled command duration and exit code for production profiling', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => $event->description === 'Maintenance: Reconcile Stale AI Generations'
    );

    $event->callBeforeCallbacks(app());
    $event->exitCode = 0;
    $event->callAfterCallbacks(app());

    $heartbeat = CommandHeartbeat::query()
        ->where('source', 'schedule')
        ->where('command', 'like', 'ai:reconcile-stale-generations%')
        ->latest('id')
        ->firstOrFail();

    expect($heartbeat->metadata)
        ->toHaveKey('duration_ms')
        ->toHaveKey('exit_code', 0)
        ->and($heartbeat->metadata['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('keeps queue reservations longer than the longest configured sync job', function () {
    $longestSyncJob = max(
        1800,
        (int) config('nfl.sync.job_timeout'),
        (int) config('cfb.sync.job_timeout'),
        (int) config('nba.sync.job_timeout'),
        (int) config('cbb.sync.job_timeout'),
        (int) config('wcbb.sync.job_timeout'),
        (int) config('mlb.sync.job_timeout'),
        (int) config('wnba.sync.job_timeout'),
    );

    expect((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan($longestSyncJob)
        ->and((int) config('queue.connections.database.retry_after'))->toBeGreaterThan($longestSyncJob);
});

it('runs immutable epa baseline rebuilds weekly and bounds the nfl readiness audit', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    $baseline = $events->get('NFL: Build EPA State Baseline');
    $readiness = $events->get('NFL: Weekly Readiness Pass');

    expect($baseline?->expression)->toBe('20 1 * * 1')
        ->and((string) $readiness?->command)
        ->toContain('--from-season=2021')
        ->toContain('--spread-backtest-limit=2500')
        ->not->toContain('--spread-backtest-limit=0');
});
