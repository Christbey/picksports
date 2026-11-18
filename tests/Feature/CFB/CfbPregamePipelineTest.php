<?php

use App\Actions\ESPN\CFB\SyncGameDetails;
use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Actions\ESPN\CFB\SyncPlayers;
use App\Jobs\CFB\RunPregamePipeline;
use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
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
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 18)->startOfDay());
    config()->set('prediction_lifecycle.canonical_pipeline.cfb', true);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(12)]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED']);
    $this->mock(SyncGameMetadata::class)->shouldReceive('execute')->andReturn(true)->byDefault();
    $this->mock(CalculationReleaseSelector::class)->shouldReceive('select')->andReturn(new CalculationRelease([
        'configuration' => ['inputs' => ['validated_historical_play_metrics' => true]],
    ]))->byDefault();
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
    Artisan::command('cfb:sync-game-weather {--season=} {--from-date=} {--days-forward=} {--game-id=} {--force}', fn () => 0);
    Artisan::command('cfb:generate-canonical-predictions {--season=} {--days-forward=} {--game=} {--isolate}', function () use ($steps) {
        $steps[] = 'generation';
        $game = Game::findOrFail($this->option('game'));
        $prediction = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $game->sport_event_id,
            'phase' => 'pregame', 'publication_state' => 'published', 'published_at' => now(), 'generated_at' => now(), 'revision' => 1 + (int) CanonicalPrediction::where('sport_event_id', $game->sport_event_id)->max('revision')]);
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
    Artisan::command('cfb:run-pregame-pipeline {--season=} {--days-forward=}', function () use ($code) {
        $this->line('Worker result');

        return $code;
    });
    $job = new RunPregamePipeline(['--season' => 2026, '--days-forward' => 8]);
    if ($code === 1) {
        expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'exited with code 1');
        $job->failed(new RuntimeException('Worker failed'));
    } else {
        $job->handle();
    }
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

it('publishes unrelated games when one team metric refresh fails', function (bool $throws) {
    $bad = Game::firstOrFail();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(13)]);
    $good = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_SCHEDULED']);
    $badTeam = $bad->home_team_id;
    Artisan::command('cfb:calculate-team-metrics {--season=} {--team-ids=}', function () use ($badTeam, $throws) {
        if (in_array((string) $badTeam, explode(',', $this->option('team-ids')), true)) {
            if ($throws) {
                throw new RuntimeException('Metric subprocess timed out');
            }

            return 1;
        }

        return 0;
    });
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertExitCode(2);
    expect(CanonicalPrediction::where('sport_event_id', $good->sport_event_id)->count())->toBe(1)
        ->and(CanonicalPrediction::where('sport_event_id', $bad->sport_event_id)->count())->toBe(0);
    expect(json_decode(DB::table('cfb_pipeline_steps')->where('scope', 'game:'.$bad->id)
        ->where('stage', 'forecast')->value('result'), true)['reason'])->toBe('unreliable_team_metrics');
})->with([true, false]);

it('backs off unchanged failed repairs but retries changed inputs and expired failures', function () {
    $stage = app(CfbPipelineStage::class);
    $calls = 0;
    $work = function () use (&$calls) {
        $calls++;
        throw new RuntimeException('Unreconciled source');
    };
    $stage->run('game:1', 'repair', ['source' => 'a'], $work, false, 3600);
    $stage->run('game:1', 'repair', ['source' => 'a'], $work, false, 3600);
    expect($calls)->toBe(1);
    $stage->run('game:1', 'repair', ['source' => 'b'], $work, false, 3600);
    expect($calls)->toBe(2);
    $this->travel(1)->hours();
    $stage->run('game:1', 'repair', ['source' => 'a'], $work, false, 3600);
    expect($calls)->toBe(3);
});

it('holds only affected matchups after a historical boxscore repair fails', function () {
    $bad = Game::firstOrFail();
    $past = Game::factory()->create(['season' => 2026, 'status' => 'STATUS_FINAL',
        'home_team_id' => $bad->home_team_id, 'away_team_id' => $bad->away_team_id,
        'game_date' => now()->subWeek()]);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(13)]);
    $good = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_SCHEDULED']);
    $readiness = $this->mock(CfbDataReadiness::class);
    $readiness->shouldReceive('forGame')->andReturn(['ready' => false, 'components' => [
        'boxscore' => ['state' => 'partial', 'accepted' => false, 'source_hash' => 'bad-yards',
            'discrepancies' => ['team_yards_do_not_reconcile']],
        'plays' => ['state' => 'complete', 'accepted' => true]]]);
    $readiness->shouldReceive('coreBoxscoreReady')->andReturn(false);
    $this->mock(SyncGameDetails::class)->shouldReceive('execute')->once()->andReturn([]);
    $this->mock(CfbCfbdTeamBoxscoreSync::class)->shouldReceive('execute')->once()
        ->andThrow(new RuntimeException('Secondary yards disagree'));
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])->assertExitCode(2);
    expect(CanonicalPrediction::where('sport_event_id', $good->sport_event_id)->count())->toBe(1)
        ->and(CanonicalPrediction::where('sport_event_id', $bad->sport_event_id)->count())->toBe(0)
        ->and(DB::table('cfb_pipeline_steps')->where('scope', 'game:'.$past->id)
            ->where('stage', 'core-box-repair')->value('state'))->toBe('failed');
});

it('does not reuse a completed forecast after team input reliability changes', function (string $gate) {
    $unreliable = false;
    if ($gate === 'metrics') {
        Artisan::command('cfb:calculate-team-metrics {--season=} {--team-ids=}', function () use (&$unreliable) {
            return $unreliable ? 1 : 0;
        });
    } elseif ($gate === 'release') {
        $this->mock(CalculationReleaseSelector::class)->shouldReceive('select')->andReturnUsing(function () use (&$unreliable) {
            if ($unreliable) {
                throw new RuntimeException('Release no longer available');
            }

            return new CalculationRelease(['configuration' => []]);
        });
    } else {
        $this->mock(SyncPlayerInjuries::class)->shouldReceive('execute')->andReturn(0);
        app(SyncPlayerInjuries::class)->shouldReceive('lastSyncReliable')->andReturnUsing(function () use (&$unreliable) {
            return ! $unreliable;
        });
    }
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $arguments = ['--season' => 2026, '--resume' => true];
    $this->artisan('cfb:run-pregame-pipeline', $arguments)->assertSuccessful();
    $unreliable = true;
    $this->artisan('cfb:run-pregame-pipeline', $arguments)->assertExitCode(2);
    expect(CanonicalPrediction::count())->toBe(1)
        ->and(DB::table('cfb_pipeline_steps')->where('stage', 'forecast')->where('state', 'blocked')->count())->toBe(1);
})->with(['metrics', 'personnel', 'release']);

it('reuses an unaffected forecast when another matchup receives corrected historical evidence', function () {
    $unaffected = Game::firstOrFail();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(13)]);
    $affected = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_SCHEDULED']);
    $past = Game::factory()->create(['season' => 2026, 'status' => 'STATUS_FINAL',
        'home_team_id' => $affected->home_team_id, 'away_team_id' => $affected->away_team_id,
        'game_date' => now()->subWeek()]);
    $this->mock(CfbDataReadiness::class)->shouldReceive('forGame')->andReturn(['ready' => true,
        'components' => ['boxscore' => ['state' => 'complete', 'accepted' => true],
            'plays' => ['state' => 'complete', 'accepted' => true]]]);
    app(CfbDataReadiness::class)->shouldReceive('coreBoxscoreReady')->andReturn(true);
    $proof = GameDataCheck::create(['game_id' => $past->id, 'component' => 'boxscore',
        'source_hash' => 'old-source', 'validator_version' => 'test', 'state' => 'complete',
        'discrepancies' => [], 'evidence' => [], 'source_path' => 'test', 'accepted_at' => now()]);
    Artisan::command('cfb:sync-odds {--days=}', fn () => 0);
    $arguments = ['--season' => 2026, '--resume' => true, '--core-only' => true];
    $this->artisan('cfb:run-pregame-pipeline', $arguments)->assertSuccessful();
    $proof->update(['source_hash' => 'corrected-source']);
    $this->artisan('cfb:run-pregame-pipeline', $arguments)->assertSuccessful();
    expect(CanonicalPrediction::where('sport_event_id', $unaffected->sport_event_id)->count())->toBe(1)
        ->and(CanonicalPrediction::where('sport_event_id', $affected->sport_event_id)->count())->toBe(2)
        ->and(DB::table('cfb_pipeline_steps')->where('scope', 'game:'.$unaffected->id)
            ->where('stage', 'forecast')->count())->toBe(1);
});

it('does not let an older worker failure overwrite a newer run with identical arguments', function () {
    Artisan::command('cfb:run-pregame-pipeline {--season=}', fn () => 0);
    $old = new RunPregamePipeline(['--season' => 2026]);
    $old->handle();
    $new = new RunPregamePipeline(['--season' => 2026]);
    $new->handle();
    $before = Cache::get('cfb:pregame:worker-status');
    unserialize(serialize($old))->failed(new RuntimeException('Delayed failure callback'));
    expect(Cache::get('cfb:pregame:worker-status'))->toBe($before)
        ->and($before['run_id'])->toBe($new->runId)
        ->and($old->runId)->not->toBe($new->runId);
});
