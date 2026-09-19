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
    $this->mock(SyncPlayerInjuries::class)->shouldReceive('execute')->andReturnUsing(function () use ($steps) {
        $steps[] = 'injury';

        return 0;
    });
    Artisan::command('cfb:import-fpi {--season=} {--week=} {--require-data} {--recalculate-metrics}', function () use ($steps) {
        $steps[] = 'ratings';

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
    expect($steps->getArrayCopy())->toBe(['ratings', 'roster', 'injury', 'roster', 'injury', 'odds', 'generation']);
});

it('stops after failed odds and releases its application lock', function () {
    $steps = $this->steps;
    Artisan::command('cfb:sync-odds {--days=}', function () use ($steps) {
        $steps[] = 'odds';

        return 1;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    expect($steps->getArrayCopy())->toBe(['ratings', 'roster', 'injury', 'roster', 'injury', 'odds']);
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
    Artisan::command('cfb:import-fpi {--season=} {--week=} {--require-data} {--recalculate-metrics}', function () use ($steps) {
        $steps[] = 'failed-ratings';

        return 1;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertFailed();
    expect($steps->getArrayCopy())->toBe(['failed-ratings', 'failed-ratings']);
});
