<?php

use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Actions\ESPN\CFB\SyncPlayers;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 18)->startOfDay());
    config()->set('prediction_lifecycle.canonical_pipeline.cfb', true);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(12)]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED']);
    $this->steps = new ArrayObject;
    $steps = $this->steps;
    $this->mock(SyncPlayers::class)->shouldReceive('execute')->andReturnUsing(function () use ($steps) {
        $steps[] = 'roster';

        return 100;
    });
    $injuryMock = $this->mock(SyncPlayerInjuries::class);
    $injuryMock->shouldReceive('lastSyncReliable')->andReturn(true)->byDefault();
    $injuryMock->shouldReceive('execute')->andReturnUsing(function () use ($steps) {
        $steps[] = 'injury';

        return 0;
    });
    foreach (['cfb:sync-season-affiliations' => 'membership', 'cfb:calculate-elo' => 'elo', 'cfb:calculate-team-metrics' => 'metrics'] as $command => $step) {
        Artisan::command($command.' {--season=}', function () use ($steps, $step) {
            $steps[] = $step;

            return 0;
        });
    }
    Artisan::command('cfb:import-fpi {--season=} {--week=} {--require-data} {--require-complete}', function () use ($steps) {
        $steps[] = 'ratings';

        return 0;
    });
    Artisan::command('cfb:sync-preseason-team-signals {--season=} {--include-coaches} {--include-quarterbacks} {--require-data}', function () use ($steps) {
        $steps[] = 'personnel';

        return 0;
    });
    Artisan::command('cfb:generate-canonical-predictions {--season=} {--days-forward=}', function () use ($steps) {
        $steps[] = 'generation';

        return 0;
    });
});

it('completes synchronous injury and odds work before generation', function () {
    $steps = $this->steps;
    Artisan::command('cfb:sync-odds {--days=}', function () use ($steps) {
        $steps[] = 'odds';

        return 0;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertSuccessful();
    expect($steps->getArrayCopy())->toBe(['membership', 'ratings', 'elo', 'personnel', 'roster', 'injury', 'roster', 'injury', 'metrics', 'odds', 'generation']);
});

it('stops after failed odds and releases its application lock', function () {
    $steps = $this->steps;
    Artisan::command('cfb:sync-odds {--days=}', function () use ($steps) {
        $steps[] = 'odds';

        return 1;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    expect($steps->getArrayCopy())->toBe(['membership', 'ratings', 'elo', 'personnel', 'roster', 'injury', 'roster', 'injury', 'metrics', 'odds']);
    $lock = Cache::lock('cfb:pregame-pipeline');
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('refuses concurrent runs and invalid horizons without doing work', function () {
    $lock = Cache::lock('cfb:pregame-pipeline', 3600);
    $lock->get();
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    $lock->release();
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--days-forward' => 0])->assertFailed();
    expect($this->steps->getArrayCopy())->toBe([]);
});

it('registers the guarded hourly refresh during the college football season', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'cfb:run-pregame-pipeline'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('45 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});

it('resolves the real roster action with the college football ESPN service', function () {
    app()->forgetInstance(SyncPlayers::class);
    expect(app(SyncPlayers::class))->toBeInstanceOf(SyncPlayers::class);
});

it('stops generation when the rating refresh fails and retries on the next run', function () {
    $steps = $this->steps;
    Artisan::command('cfb:import-fpi {--season=} {--week=} {--require-data} {--require-complete}', function () use ($steps) {
        $steps[] = 'failed-ratings';

        return 1;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    expect($steps->getArrayCopy())->toBe(['membership', 'failed-ratings', 'membership', 'failed-ratings']);
});

it('stops before forecasts when Elo integrity fails', function () {
    $steps = $this->steps;
    Artisan::command('cfb:calculate-elo {--season=}', function () use ($steps) {
        $steps[] = 'failed-elo';

        return 1;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    expect($steps->getArrayCopy())->toBe(['membership', 'ratings', 'failed-elo']);
});

it('refreshes Elo and metrics even after the daily FPI refresh is cached', function () {
    Cache::put('cfb:pregame-ratings:v2:2026:2026-09-18', true, now()->addDay());
    $steps = $this->steps;
    Artisan::command('cfb:sync-odds {--days=}', function () use ($steps) {
        $steps[] = 'odds';

        return 0;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertSuccessful();
    expect($steps->getArrayCopy())->toBe(['elo', 'personnel', 'roster', 'injury', 'roster', 'injury', 'metrics', 'odds', 'generation']);
});

it('stops when zero injuries actually means an unavailable feed', function () {
    app(SyncPlayerInjuries::class)->shouldReceive('lastSyncReliable')->andReturn(false);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    expect($this->steps->getArrayCopy())->toBe(['membership', 'ratings', 'elo', 'personnel', 'roster', 'injury']);
});

it('requires personnel data and retries an empty refresh without caching success', function () {
    $attempts = 0;
    Artisan::command('cfb:sync-preseason-team-signals {--season=} {--include-coaches} {--include-quarterbacks} {--require-data}', function () use (&$attempts) {
        expect($this->option('require-data'))->toBeTrue()->and($this->option('include-quarterbacks'))->toBeTrue();
        $attempts++;

        return $attempts === 1 ? 1 : 0;
    });
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    expect(Cache::has('cfb:personnel:v3:2026:2026-09-18'))->toBeFalse();
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertSuccessful();
    expect($attempts)->toBe(2)->and(Cache::has('cfb:personnel:v3:2026:2026-09-18'))->toBeTrue();
});
