<?php

use App\Actions\ESPN\CFB\SyncGameDetails;
use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Actions\ESPN\CFB\SyncPlayers;
use App\Jobs\CFB\RunPregamePipeline;
use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Models\CommandHeartbeat;
use App\Models\SportEvent;
use App\Services\CFB\CfbEpaBaselineEvidence;
use App\Services\CFB\Data\CfbCfbdTeamBoxscoreSync;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbPipelineStage;
use App\Services\CFB\Data\CfbSharedInputRunner;
use App\Services\Predictions\CalculationReleaseSelector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 18)->startOfDay());
    config()->set('prediction_lifecycle.canonical_pipeline.cfb', true);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(12)]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED']);
    $this->mock(SyncGameMetadata::class)->shouldReceive('execute')->andReturn(true)->byDefault();
    $this->steps = new ArrayObject;
    $this->mock(CfbSharedInputRunner::class)->shouldReceive('run')->andReturnUsing(function ($command, $arguments) {
        $code = Artisan::call($command, $arguments);

        return ['exit_code' => $code, 'output' => Artisan::output()];
    });
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
        Artisan::command($command.' {--season=}'.($step === 'metrics' ? ' {--team-ids=}' : ''), function () use ($steps, $step) {
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
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    Artisan::command('cfb:sync-game-weather {--season=} {--from-date=} {--days-forward=} {--game-id=} {--force}', fn () => 0);
    Artisan::command('cfb:generate-canonical-predictions {--season=} {--days-forward=} {--game=} {--isolate}', function () use ($steps) {
        $steps[] = 'generation';
        $game = Game::findOrFail($this->option('game'));
        $prediction = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $game->sport_event_id,
            'phase' => 'pregame', 'publication_state' => 'published', 'published_at' => now(), 'generated_at' => now(), 'revision' => 1]);
        $prediction->markets()->create(['market_type' => 'spread', 'selection' => 'home', 'projected_line' => -7]);

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
    expect($steps->getArrayCopy())->toBe(['membership', 'ratings', 'personnel', 'roster', 'injury', 'roster', 'injury', 'elo', 'metrics', 'odds', 'generation']);
});

it('continues forecasts after failed odds and releases its application lock', function () {
    $steps = $this->steps;
    Artisan::command('cfb:sync-odds {--days=}', function () use ($steps) {
        $steps[] = 'odds';

        return 1;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertSuccessful();
    expect($steps->getArrayCopy())->toBe(['membership', 'ratings', 'personnel', 'roster', 'injury', 'roster', 'injury', 'elo', 'metrics', 'odds', 'generation']);
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
        ->first(fn ($e) => $e->description === 'CFB: Refresh Pregame Inputs and Forecasts');
    expect($event)->not->toBeNull()->and($event->expression)->toBe('45 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue()
        ->and($event->command)->toContain('--days-forward=8', '--queued');
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
    expect($steps->getArrayCopy())->toBe(['membership', 'ratings', 'personnel', 'roster', 'injury', 'roster', 'injury', 'failed-elo']);
});

it('refreshes Elo and metrics even after the daily FPI refresh is cached', function () {
    Cache::put('cfb:pregame-ratings:v2:2026:2026-09-18', true, now()->addDay());
    $steps = $this->steps;
    Artisan::command('cfb:sync-odds {--days=}', function () use ($steps) {
        $steps[] = 'odds';

        return 0;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertSuccessful();
    expect($steps->getArrayCopy())->toBe(['personnel', 'roster', 'injury', 'roster', 'injury', 'elo', 'metrics', 'odds', 'generation']);
});

it('records affected games when the injury feed is unavailable', function () {
    app(SyncPlayerInjuries::class)->shouldReceive('lastSyncReliable')->andReturn(false);
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertExitCode(2);
    expect($this->steps->getArrayCopy())->not->toContain('generation');
    expect(DB::table('cfb_pipeline_steps')->value('state'))->toBe('blocked');
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

it('continues generation after an unavailable optional weather refresh', function () {
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    Artisan::command('cfb:sync-game-weather {--season=} {--from-date=} {--days-forward=} {--game-id=} {--force}', fn () => 1);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->expectsOutput('Weather refresh unavailable; missing weather remains unknown.')->assertSuccessful();
    expect($this->steps->getArrayCopy())->toContain('generation');
});

it('does not mark a skipped forecast complete merely because the command exits successfully', function () {
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    Artisan::command('cfb:generate-canonical-predictions {--game=} {--isolate}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertExitCode(2);
    expect(json_decode(DB::table('cfb_pipeline_steps')->where('stage', 'forecast')->value('result'), true)['reason'])->toBe('no_fresh_published_forecast');
});

it('shows stage evidence and routes daily canonical generation through validation', function () {
    $this->artisan('cfb:pipeline-status')->assertSuccessful();
    $daily = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'CFB: Generate Canonical Predictions');
    expect($daily->command)->toContain('cfb:run-pregame-pipeline');
});

it('leaves explicit non FBS only games outside the FBS forecast pipeline', function () {
    $game = Game::firstOrFail();
    foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
        TeamSeasonAffiliation::create(['team_id' => $teamId, 'season' => 2026,
            'subdivision' => 'NON_FBS', 'source' => 'cfbd_fbs_membership']);
    }
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertSuccessful();
    expect($this->steps->getArrayCopy())->toBe([]);
});

it('reuses completed historical normalization for unchanged accepted source revisions', function () {
    $game = Game::firstOrFail();
    $past = Game::factory()->create(['home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
        'season' => 2026, 'status' => 'STATUS_FINAL', 'game_date' => now()->subWeek()]);
    $quality = ['ready' => true, 'components' => ['plays' => ['source_hash' => 'accepted-source'],
        'boxscore' => ['state' => 'complete', 'accepted' => true]]];
    $this->mock(CfbDataReadiness::class)->makePartial()->shouldReceive('forGame')->andReturn($quality);
    $this->mock(CfbDriveBuilder::class)->shouldNotReceive('build');
    app(CfbPipelineStage::class)->run('game:'.$past->id, 'normalize', [...$quality, 'normalizer_version' => CfbDriveBuilder::VERSION, 'quality_schema' => 2,
        'epa_baseline_available_at' => app(CfbEpaBaselineEvidence::class)->availableAt((int) $past->season)],
        fn () => ['state' => 'complete', 'drives' => ['state' => 'complete']]);
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertSuccessful();
    expect(DB::table('cfb_pipeline_steps')->where('scope', 'game:'.$past->id)->where('stage', 'normalize')->value('attempts'))->toBe(1)
        ->and($this->steps->getArrayCopy())->toContain('generation');
});

it('publishes before optional weather and keeps a single game recovery scoped', function () {
    $game = Game::firstOrFail();
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    Artisan::command('cfb:sync-game-weather {--season=} {--from-date=} {--days-forward=} {--game-id=} {--force}', function () use ($game) {
        expect((int) $this->option('game-id'))->toBe($game->id)
            ->and(CanonicalPrediction::where('sport_event_id', $game->sport_event_id)->where('publication_state', 'published')->exists())->toBeTrue();

        return 1;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--game' => $game->id])->assertSuccessful();
});

it('holds a forecast when its kickoff metadata cannot be verified', function () {
    app(SyncGameMetadata::class)->shouldReceive('execute')->andThrow(new RuntimeException('Summary unavailable'));
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertExitCode(2);
    expect($this->steps->getArrayCopy())->not->toContain('generation');
    $result = json_decode(DB::table('cfb_pipeline_steps')->where('stage', 'forecast')->value('result'), true);
    expect($result['reason'])->toBe('unreliable_kickoff_metadata');
});

it('keeps multi-game recovery scoped to the selected games', function () {
    $game = Game::firstOrFail();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(13)]);
    Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id, 'status' => 'STATUS_SCHEDULED']);
    Artisan::call('cfb:run-pregame-pipeline', ['--season' => 2026, '--games' => (string) $game->id, '--dry-run' => true]);
    expect(json_decode(Artisan::output(), true)['game_ids'])->toBe([$game->id]);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--games' => '1,invalid'])->assertFailed();
});

it('recovers conflicting primary team yards only after secondary source validation succeeds', function (bool $validSecondary) {
    $game = Game::firstOrFail();
    Game::factory()->create(['home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
        'season' => 2026, 'status' => 'STATUS_FINAL', 'game_date' => now()->subWeek()]);
    $repaired = false;
    $readiness = $this->mock(CfbDataReadiness::class);
    $readiness->shouldReceive('forGame')->andReturn(['ready' => false,
        'components' => ['boxscore' => ['state' => 'partial', 'accepted' => false, 'source_hash' => 'primary',
            'discrepancies' => ['team_yards_do_not_reconcile']], 'plays' => ['state' => 'complete', 'accepted' => true]]]);
    // Use a reference so source acceptance changes the gate only after the work succeeds.
    $readiness->shouldReceive('coreBoxscoreReady')->andReturnUsing(function () use (&$repaired) {
        return $repaired;
    });
    $this->mock(SyncGameDetails::class)->shouldReceive('execute')->andReturn([]);
    $this->mock(CfbCfbdTeamBoxscoreSync::class)->shouldReceive('execute')->andReturnUsing(function () use (&$repaired, $validSecondary) {
        if (! $validSecondary) {
            throw new RuntimeException('Secondary yards disagree');
        }
        $repaired = true;

        return ['state' => 'complete'];
    });
    $release = new CalculationRelease(['configuration' => ['inputs' => ['validated_historical_play_metrics' => true]]]);
    $this->mock(CalculationReleaseSelector::class)->shouldReceive('select')->andReturn($release);
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--core-only' => true])->assertExitCode($validSecondary ? 0 : 2);
    expect(in_array('generation', $this->steps->getArrayCopy(), true))->toBe($validSecondary);
})->with([true, false]);

it('allows flagged core forecasts after failed optional play normalization only under the new release policy', function (bool $fallback) {
    $game = Game::firstOrFail();
    Game::factory()->create(['home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
        'season' => 2026, 'status' => 'STATUS_FINAL', 'game_date' => now()->subWeek()]);
    $this->mock(CfbDataReadiness::class)->makePartial()->shouldReceive('forGame')->andReturn(['ready' => true,
        'components' => ['boxscore' => ['state' => 'complete', 'accepted' => true], 'plays' => ['state' => 'complete', 'accepted' => true]]]);
    Process::fake(['*' => Process::result(output: json_encode(['state' => 'failed', 'drives' => ['state' => 'partial']]), exitCode: 2)]);
    $release = new CalculationRelease(['configuration' => ['inputs' => ['validated_historical_play_metrics' => $fallback]]]);
    $this->mock(CalculationReleaseSelector::class)->shouldReceive('select')->andReturn($release);
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertExitCode($fallback ? 0 : 2);
    expect(in_array('generation', $this->steps->getArrayCopy(), true))->toBe($fallback);
})->with([true, false]);

it('dispatches the weekly refresh to the bulk queue without claiming completed generation', function () {
    Bus::fake();
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--week' => 6, '--days-forward' => 8,
        '--queued' => true, '--core-only' => true])->assertSuccessful();
    Bus::assertDispatched(RunPregamePipeline::class, fn ($job) => $job->queue === 'sync'
        && $job->arguments['--week'] === 6 && $job->arguments['--core-only'] === true
        && ! isset($job->arguments['--queued']));
    expect($this->steps->getArrayCopy())->toBe([])
        ->and(CommandHeartbeat::where('command', 'like', 'cfb:run-pregame-pipeline%')->count())->toBe(0);
});

it('records actual worker completion and preserves partial outcomes', function (int $code, string $state) {
    Artisan::command('cfb:run-pregame-pipeline {--season=} {--days-forward=} {--run-id=}', function () use ($code) {
        $this->line('Worker result');

        return $code;
    });
    $job = new RunPregamePipeline(['--season' => 2026, '--days-forward' => 8]);
    $job->handle();
    expect(Cache::get('cfb:pregame:worker-status')['state'])->toBe($state)
        ->and(Cache::get('cfb:pregame:worker-status')['exit_code'])->toBe($code);
})->with([[0, 'complete'], [2, 'partial'], [1, 'failed']]);

it('keeps boxscore evidence required during urgent core-only recovery', function (bool $boxReady) {
    $game = Game::firstOrFail();
    Game::factory()->create(['home_team_id' => $game->home_team_id, 'away_team_id' => $game->away_team_id,
        'season' => 2026, 'status' => 'STATUS_FINAL', 'game_date' => now()->subWeek()]);
    $this->mock(CfbDataReadiness::class)->makePartial()->shouldReceive('forGame')->andReturn(['ready' => false,
        'components' => ['boxscore' => ['state' => $boxReady ? 'complete' : 'partial', 'accepted' => $boxReady],
            'plays' => ['state' => 'partial', 'accepted' => false]]]);
    $this->mock(SyncGameDetails::class)->shouldReceive('execute')->andReturn([]);
    $release = new CalculationRelease(['configuration' => ['inputs' => ['validated_historical_play_metrics' => true]]]);
    $this->mock(CalculationReleaseSelector::class)->shouldReceive('select')->andReturn($release);
    Process::fake();
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--core-only' => true])->assertExitCode($boxReady ? 0 : 2);
    expect(in_array('generation', $this->steps->getArrayCopy(), true))->toBe($boxReady);
    Process::assertNothingRan();
})->with([true, false]);

it('continues games with refreshed metrics and holds only the failed team cohort', function () {
    $first = Game::firstOrFail();
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(14)]);
    $second = Game::factory()->create(['season' => 2026, 'sport_event_id' => $event->id, 'home_team_id' => $home->id,
        'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED']);
    Artisan::command('cfb:calculate-team-metrics {--season=} {--team-ids=}', function () use ($first) {
        $ids = array_map('intval', explode(',', $this->option('team-ids')));
        $this->line('CFB_METRIC_BATCH_RESULT='.json_encode(['refreshed_team_ids' => array_values(array_diff($ids, [$first->home_team_id])), 'failed_team_ids' => [$first->home_team_id]]));

        return 2;
    });
    expect(Artisan::call('cfb:run-pregame-pipeline', ['--season' => 2026]))->toBe(2);
    expect(CanonicalPrediction::where('sport_event_id', $first->sport_event_id)->count())->toBe(0)
        ->and(CanonicalPrediction::where('sport_event_id', $second->sport_event_id)->count())->toBe(1);
    $results = DB::table('cfb_pipeline_steps')->where('stage', 'forecast')->get()->keyBy('scope');
    expect(json_decode($results['game:'.$first->id]->result, true)['reason'])->toBe('unreliable_team_metrics')
        ->and($results['game:'.$second->id]->state)->toBe('complete');
});

it('holds the whole failed batch when its per-team report is missing or contradictory', function (string $report) {
    Artisan::command('cfb:calculate-team-metrics {--season=} {--team-ids=}', function () use ($report) {
        $this->line($report);

        return 2;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertExitCode(2);
    expect(CanonicalPrediction::count())->toBe(0)
        ->and(json_decode(DB::table('cfb_pipeline_steps')->where('stage', 'forecast')->value('result'), true)['reason'])->toBe('unreliable_team_metrics');
})->with(['', 'CFB_METRIC_BATCH_RESULT={"refreshed_team_ids":[],"failed_team_ids":[]}']);

it('records real publications and does not count resumed work as newly published', function () {
    $id = (string) Str::uuid();
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--run-id' => $id, '--resume' => true])->assertSuccessful();
    expect(Cache::get('cfb:pregame:run:'.$id))->toMatchArray(['publication_count' => 1, 'reused_count' => 0, 'complete_count' => 1]);
    $next = (string) Str::uuid();
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--run-id' => $next, '--resume' => true])->assertSuccessful();
    expect(Cache::get('cfb:pregame:run:'.$next))->toMatchArray(['publication_count' => 0, 'reused_count' => 1, 'complete_count' => 1]);
});

it('rechecks metric holds rather than resuming a previously complete forecast', function () {
    expect(Artisan::call('cfb:run-pregame-pipeline', ['--season' => 2026, '--resume' => true]))->toBe(0);
    $this->mock(CfbSharedInputRunner::class)->shouldReceive('run')->andReturnUsing(function ($command, $arguments) {
        if ($command === 'cfb:calculate-team-metrics') {
            return ['exit_code' => 1, 'output' => 'Provider failure'];
        }
        $code = Artisan::call($command, $arguments);

        return ['exit_code' => $code, 'output' => Artisan::output()];
    });
    $id = (string) Str::uuid();
    expect(Artisan::call('cfb:run-pregame-pipeline', ['--season' => 2026, '--run-id' => $id, '--resume' => true]))->toBe(2);
    expect(Cache::get('cfb:pregame:run:'.$id))->toMatchArray(['publication_count' => 0, 'held_count' => 1, 'reused_count' => 0]);
});

it('persists terminal worker outcomes and counts without mistaking partial work for queue success', function (int $code, string $state) {
    Artisan::command('cfb:run-pregame-pipeline {--season=} {--days-forward=} {--run-id=}', function () use ($code) {
        Cache::put('cfb:pregame:run:'.$this->option('run-id'), ['publication_count' => 2, 'reused_count' => 1,
            'held_count' => 3, 'failed_count' => 0, 'pending_count' => 0, 'complete_count' => 3, 'games' => []]);

        return $code;
    });
    $job = (new RunPregamePipeline(['--season' => 2026, '--days-forward' => 8]))->withFakeQueueInteractions();
    $job->handle();
    $record = CommandHeartbeat::where('source', 'worker')->where('metadata->run_id', $job->runId)->sole();
    expect($record->status)->toBe($state === 'complete' ? 'success' : 'failure')
        ->and($record->metadata)->toMatchArray(['run_id' => $job->runId, 'state' => $state, 'publication_count' => 2, 'reused_count' => 1, 'held_count' => 3]);
    if ($state === 'complete') {
        $job->assertNotFailed();
    } else {
        $job->assertFailed();
    }
    $job->failed(new RuntimeException('Repeated failed callback'));
    expect(CommandHeartbeat::where('source', 'worker')->count())->toBe(1)
        ->and(Cache::get('cfb:pregame:worker-status')['state'])->toBe($state);
})->with([[0, 'complete'], [2, 'partial'], [1, 'failed']]);

it('records exceptions with already-published progress and marks the queue job failed', function () {
    Artisan::command('cfb:run-pregame-pipeline {--season=} {--run-id=}', function () {
        Cache::put('cfb:pregame:run:'.$this->option('run-id'), ['publication_count' => 1, 'pending_count' => 2, 'games' => []]);
        throw new RuntimeException('Worker interrupted');
    });
    $job = (new RunPregamePipeline(['--season' => 2026]))->withFakeQueueInteractions();
    $job->handle();
    $job->assertFailedWith(RuntimeException::class);
    expect(CommandHeartbeat::where('source', 'worker')->sole()->metadata)->toMatchArray(['state' => 'failed', 'publication_count' => 1, 'pending_count' => 2]);
});

it('pings the scheduled heartbeat only after successful worker completion', function (int $code) {
    Http::fake();
    config(['services.forge.heartbeats.cfb_refresh_pregame_inputs_and_forecasts' => 'https://heartbeat.test/complete']);
    Artisan::command('cfb:run-pregame-pipeline {--season=} {--run-id=} {--heartbeat-name=}', fn () => $code);
    (new RunPregamePipeline(['--season' => 2026, '--heartbeat-name' => 'CFB: Refresh Pregame Inputs and Forecasts']))->handle();
    Http::assertSentCount($code === 0 ? 1 : 0);
})->with([0, 1, 2]);

it('counts a publication even when generation subsequently raises an error', function () {
    Artisan::command('cfb:generate-canonical-predictions {--game=} {--isolate}', function () {
        $game = Game::findOrFail($this->option('game'));
        CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $game->sport_event_id,
            'phase' => 'pregame', 'publication_state' => 'published', 'published_at' => now(), 'generated_at' => now()]);
        throw new RuntimeException('Post-publication interruption');
    });
    $id = (string) Str::uuid();
    expect(Artisan::call('cfb:run-pregame-pipeline', ['--season' => 2026, '--run-id' => $id]))->toBe(2);
    expect(Cache::get('cfb:pregame:run:'.$id))->toMatchArray(['publication_count' => 1, 'failed_count' => 1]);
});

it('continues later metric batches after a subprocess crashes and conservatively holds the failed batch', function () {
    $first = Game::firstOrFail();
    foreach (range(1, 5) as $index) {
        $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(14)]);
        Game::factory()->create(['season' => 2026, 'sport_event_id' => $event->id, 'home_team_id' => Team::factory(),
            'away_team_id' => Team::factory(), 'status' => 'STATUS_SCHEDULED']);
    }
    $this->mock(CfbSharedInputRunner::class)->shouldReceive('run')->andReturnUsing(function ($command, $arguments) use ($first) {
        if ($command === 'cfb:calculate-team-metrics') {
            if (in_array($first->home_team_id, explode(',', $arguments['--team-ids']))) {
                throw new RuntimeException('Metrics subprocess timed out');
            }

            return ['exit_code' => 0, 'output' => 'Refreshed'];
        }
        $code = Artisan::call($command, $arguments);

        return ['exit_code' => $code, 'output' => Artisan::output()];
    });
    $id = (string) Str::uuid();
    expect(Artisan::call('cfb:run-pregame-pipeline', ['--season' => 2026, '--run-id' => $id]))->toBe(2);
    expect(Cache::get('cfb:pregame:run:'.$id))->toMatchArray(['selected_count' => 6, 'publication_count' => 1, 'held_count' => 5, 'pending_count' => 0]);
});

it('limits multi-game recovery weather calls to selected games and continues after optional weather failure', function () {
    $first = Game::firstOrFail();
    $second = Game::factory()->create(['sport_event_id' => SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(13)])->id,
        'season' => 2026, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_SCHEDULED']);
    $visited = new ArrayObject;
    Artisan::command('cfb:sync-game-weather {--season=} {--from-date=} {--days-forward=} {--game-id=} {--force}', function () use ($visited) {
        $visited[] = (int) $this->option('game-id');

        return count($visited) === 1 ? 1 : 0;
    });
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--games' => $first->id.','.$second->id])->assertSuccessful();
    expect($visited->getArrayCopy())->toBe([$first->id, $second->id]);
});
