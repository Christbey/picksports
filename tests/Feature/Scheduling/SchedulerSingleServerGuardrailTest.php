<?php

use App\Models\CommandHeartbeat;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\SportEvent;
use Illuminate\Console\Scheduling\Schedule;

uses()->group('scheduling');

it('refreshes NFL research markets within their freshness window independently of model runs', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    $odds = $events->get('NFL: Research Market Refresh');
    expect($odds)->not->toBeNull()
        ->and($odds->expression)->toBe('10,40 * * * *')
        ->and((string) $odds->command)->toContain('nfl:sync-odds --days=8')
        ->and($odds->expiresAt)->toBe(10)
        ->and($odds->onOneServer)->toBeTrue();
    $readiness = $events->get('NFL: Research Readiness');
    expect($readiness)->not->toBeNull()
        ->and($readiness->expression)->toBe('5,35 * * * *')
        ->and($readiness->onOneServer)->toBeTrue();
});

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

it('runs the infrastructure health check hourly with a bounded single-server lock', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => $event->description === 'Infrastructure: Health Check'
    );

    expect($event)->not->toBeNull()
        ->and((string) $event?->command)->toContain('infrastructure:health')
        ->and($event?->expression)->toBe('47 * * * *')
        ->and($event?->onOneServer)->toBeTrue()
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->expiresAt)->toBe(10)
        ->and($event?->runInBackground)->toBeFalse();
});

it('prunes expired redis tag references daily', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => $event->description === 'Cache: Prune Stale Tags'
    );

    expect($event)->not->toBeNull()
        ->and((string) $event?->command)->toContain('cache:prune-stale-tags')
        ->and($event?->expression)->toBe('15 3 * * *')
        ->and($event?->onOneServer)->toBeTrue()
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->expiresAt)->toBe(30)
        ->and($event?->runInBackground)->toBeFalse();
});

it('drains nfl signal grading in bounded quarter-hour batches', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => $event->description === 'NFL: Grade Signal Observations'
    );

    expect($event)->not->toBeNull()
        ->and((string) $event?->command)->toContain('nfl:grade-signal-observations', '--limit=1000', '--batch-size=250')
        ->and($event?->expression)->toBe('5,20,35,50 * * * *')
        ->and($event?->onOneServer)->toBeTrue()
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->expiresAt)->toBe(30)
        ->and($event?->runInBackground)->toBeFalse();
});

it('runs odds refreshes in the foreground with bounded overlap locks', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_ends_with((string) $event->description, 'Sync Odds'))
        ->values();

    expect($events)->toHaveCount(6);

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
        'NFL: Pregame Pipeline' => 120,
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

it('rotates official nfl research across the full seven-day slate in bounded batches', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    $ingestion = $events->get('NFL: Ingest Research Evidence');
    $revisions = $events->get('NFL: Build Research Revisions');
    $grading = $events->get('NFL: Grade Research Revisions');

    expect((string) $ingestion?->command)
        ->toContain('nfl:research-pipeline', '--days-forward=7', '--limit=8', '--ingest-only')
        ->and($ingestion?->expression)->toBe('*/15 * * * *')
        ->and($ingestion?->expiresAt)->toBe(20)
        ->and((string) $revisions?->command)
        ->toContain('nfl:research-pipeline', '--days-forward=7', '--no-ingest', '--limit=4')
        ->and($revisions?->expression)->toBe('7,22,37,52 * * * *')
        ->and($revisions?->expiresAt)->toBe(55)
        ->and((string) $grading?->command)->toContain(
            'nfl:research-pipeline',
            '--grade',
            '--grade-limit=250',
            '--grade-batch-size=50',
            '--grade-retry-after-minutes=55',
        )
        ->and($grading?->expression)->toBe('55 * * * *')
        ->and($grading?->expiresAt)->toBe(30);
});

it('staggers provider syncs instead of starting every sport together', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');

    expect($events->get('MLB: Live Scoreboard Sync')?->expression)->toBe('0-59/5 * * * *')
        ->and($events->get('WNBA: Live Scoreboard Sync')?->expression)->toBe('1-59/5 * * * *')
        ->and($events->get('NFL: Live Scoreboard Sync')?->expression)->toBe('3-59/5 * * * *')
        ->and($events->get('CFB: Live Scoreboard Sync')?->expression)->toBe('4-59/5 * * * *')
        ->and($events->get('MLB: Sync Odds')?->expression)->toBe('0 8,12,16,20 * * *')
        ->and($events->get('NFL: Pregame Pipeline')?->expression)->toBe('20 6-23 * * *')
        ->and($events->get('CFB: Sync Game Details')?->expression)->toBe('24,54 * * * *')
        ->and($events->get('CFB: Sync Injuries')?->expression)->toBe('26,56 * * * *')
        ->and($events->get('MLB: Sync Game Details')?->expression)->toBe('12,42 * * * *')
        ->and((string) $events->get('MLB: Sync Game Details')?->command)
        ->toContain('--lookback-days=7', '--days-forward=1', '--limit=50', '--latest')
        ->and($events->get('MLB: Sync Injuries')?->expression)->toBe('14,44 * * * *')
        ->and($events->get('MLB: Refresh Probable Pitchers')?->expression)->toBe('17,47 * * * *')
        ->and($events->get('NFL: Sync Game Details')?->expression)->toBe('19,49 * * * *')
        ->and((string) $events->get('NFL: Sync Game Details')?->command)
        ->toContain('--lookback-days=7', '--days-forward=1', '--limit=50', '--latest')
        ->and($events->get('NFL: Sync Injuries')?->expression)->toBe('23,53 * * * *');
});

it('keeps routine sentinels observational and bounds expensive prediction work', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');

    expect((string) $events->get('MLB: Operations Sentinel')?->command)
        ->toContain('--validate-only')
        ->toContain('--skip-ai-review')
        ->and((string) $events->get('NFL: Sync Current Week')?->command)->toContain('--skip-teams')
        ->and((string) $events->get('CFB: Sync Current Week')?->command)->toContain('--skip-teams')
        ->and((string) $events->get('NFL: Pregame Pipeline')?->command)
        ->toContain('nfl:run-pregame-pipeline')
        ->toContain('--days-forward=8')
        ->and((string) $events->get('MLB: Generate Predictions')?->command)->toContain('--days-forward=2')
        ->and((string) $events->get('CFB: Generate Canonical Predictions')?->command)
        ->toContain('--week=')
        ->toContain('--days-forward=8');
});

it('runs the nfl canonical readiness sentinel even when canonical generation is disabled', function () {
    config()->set('prediction_lifecycle.canonical_pipeline.nfl', false);
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    $event = $events->get('NFL: Canonical Cutover Readiness Sentinel');
    $pipeline = $events->get('NFL: Pregame Pipeline');

    expect($pipeline)->not->toBeNull()
        ->and((string) $pipeline?->command)->toContain('nfl:run-pregame-pipeline', '--days-forward=8')
        ->and($pipeline?->expression)->toBe('20 6-23 * * *')
        ->and($pipeline?->expiresAt)->toBe(120)
        ->and($pipeline?->runInBackground)->toBeFalse();

    expect($event)->not->toBeNull()
        ->and((string) $event?->command)
        ->toContain('nfl:report-canonical-cutover-readiness', '--days-forward=8', '--fail-on-not-ready')
        ->and($event?->expression)->toBe('50 10 * * *')
        ->and($event?->onOneServer)->toBeTrue()
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->expiresAt)->toBe(120)
        ->and($event?->runInBackground)->toBeFalse()
        ->and($event?->mutexName())->toBe('nfl-canonical-generation-readiness')
        ->and($pipeline?->mutexName())->toBe($event?->mutexName());
});

it('lets the ordered nfl pipeline own odds refreshes and keeps weather inside its freshness window', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');

    expect($events->has('NFL: Sync Odds'))->toBeFalse()
        ->and($events->get('NFL: Sync Game Weather')?->expression)->toBe('5 6,10,14,18,22 * * *')
        ->and((string) $events->get('NFL: Sync Game Weather')?->command)->toContain('--days-forward=8', '--force')
        ->and($events->get('NFL: Sync Game Weather')?->runInBackground)->toBeFalse();
});

it('adds hourly nfl passes near kickoff while retaining bounded core passes on quieter days', function () {
    $pipeline = collect(app(Schedule::class)->events())->firstWhere('description', 'NFL: Pregame Pipeline');
    $this->travelTo('2026-09-16 11:20:00');
    expect($pipeline->filtersPass(app()))->toBeFalse();
    $this->travelTo('2026-09-16 12:20:00');
    expect($pipeline->filtersPass(app()))->toBeTrue();

    $this->travelTo('2027-01-17 11:20:00');
    $event = SportEvent::factory()->create([
        'sport' => 'nfl', 'season' => 2026, 'season_type' => '3',
        'starts_at' => now()->addHours(2), 'status' => 'STATUS_SCHEDULED',
    ]);
    Game::factory()->create([
        'sport_event_id' => $event->id,
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '3', 'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->addHours(2),
    ]);
    expect($pipeline->filtersPass(app()))->toBeTrue();
});

it('runs incremental epa and stale ai reconciliation in bounded foreground passes', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');
    $nflEpa = $events->get('NFL: Calculate Incremental Play EPA');
    $aiReconciliation = $events->get('Maintenance: Reconcile Stale AI Generations');

    expect((string) $nflEpa?->command)
        ->toContain('nfl:calculate-play-epa')
        ->toContain('--limit=4')
        ->and($nflEpa?->expression)->toBe('28,58 * * * *')
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
