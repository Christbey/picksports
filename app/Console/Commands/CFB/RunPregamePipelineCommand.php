<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\CalculateElo;
use App\Actions\ESPN\CFB\SyncGameDetails;
use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Actions\ESPN\CFB\SyncPlayers;
use App\Jobs\CFB\RunPregamePipeline;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\TeamMetric;
use App\Models\MarketQuote;
use App\Services\CFB\Data\CfbCfbdTeamBoxscoreSync;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbOfficialBoxscoreRepair;
use App\Services\CFB\Data\CfbOfficialPlayTailRepair;
use App\Services\CFB\Data\CfbPipelineStage;
use App\Services\CFB\Data\CfbSharedInputRunner;
use App\Services\Predictions\CalculationReleaseSelector;
use App\Services\Validation\CfbValidationCoverage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;

class RunPregamePipelineCommand extends Command
{
    protected $signature = 'cfb:run-pregame-pipeline {--season=} {--week=} {--days-forward=2} {--game=} {--games= : Comma-separated game IDs for scoped recovery} {--resume} {--dry-run} {--queued : Run on the bulk sync worker} {--core-only : Skip optional play reconstruction; approved release quality checks still apply}';

    protected $description = 'Refresh upcoming CFB injuries and odds before generating immutable forecasts';

    public function handle(SyncPlayerInjuries $injuries, SyncPlayers $players): int
    {
        $season = $this->option('season') ?? config('cfb.season.default');
        $days = $this->option('days-forward');
        if (Validator::make(['season' => $season, 'days' => $days], [
            'season' => 'required|integer|min:2000|max:2100', 'days' => 'required|integer|min:1|max:8',
        ])->fails() || ($this->option('games') && (! preg_match('/^[1-9]\d*(,[1-9]\d*)*$/', (string) $this->option('games')) || $this->option('game')))
            || ! config('prediction_lifecycle.canonical_pipeline.cfb', false)) {
            $this->error('CFB pipeline requires valid season/horizon and enabled canonical generation.');

            return self::FAILURE;
        }
        if ($this->option('queued') && ! $this->option('dry-run')) {
            RunPregamePipeline::dispatch(array_filter(['--season' => (int) $season, '--days-forward' => (int) $days,
                '--week' => $this->option('week'), '--game' => $this->option('game'),
                '--games' => $this->option('games'),
                '--resume' => (bool) $this->option('resume'), '--core-only' => (bool) $this->option('core-only')],
                fn ($value) => $value !== null && $value !== false));
            $this->info('CFB pregame pipeline dispatched to the bulk sync worker; publication still requires worker completion.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('cfb:pregame-pipeline', 3600);
        if (! $lock->get()) {
            $this->warn('CFB pregame pipeline is already running.');

            return self::FAILURE;
        }
        try {
            $gameColumns = array_merge(CalculateElo::RESULT_COLUMNS, ['sport_event_id', 'updated_at', 'espn_event_id']);
            $games = Game::with(['homeTeam', 'awayTeam'])->select($gameColumns)->where('season', $season)
                ->when($this->option('game'), fn ($q, $v) => $q->whereKey($v))
                ->when($this->option('games'), fn ($q, $v) => $q->whereKey(array_map('intval', explode(',', $v))))
                ->when($this->option('week'), fn ($q, $v) => $q->where('week', $v))
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED'])
                ->where(function ($q) use ($days) {
                    $q->whereHas('sportEvent', fn ($events) => $events->where('starts_at', '>', now())->where('starts_at', '<=', now()->addDays((int) $days)))
                        ->orWhere(fn ($dates) => $dates->whereDate('game_date', '>=', now()->subDay()->toDateString())
                            ->whereDate('game_date', '<=', now()->addDays((int) $days)->toDateString()));
                })
                ->orderBy('game_date')->orderBy('game_time')->get();
            $coverage = app(CfbValidationCoverage::class);
            $games = $games->filter(fn ($game) => $coverage->requiredTeamIds($game) !== [])->values();
            if ($games->isEmpty()) {
                $this->info('No upcoming CFB games in the pipeline horizon.');

                return self::SUCCESS;
            }
            if ($this->option('dry-run')) {
                $this->line(json_encode(['game_ids' => $games->modelKeys(), 'stages' => ['repair', 'ratings', 'metrics', 'forecast', 'price']]));

                return self::SUCCESS;
            }
            $metadataFailures = [];
            foreach ($games as $game) {
                try {
                    app(SyncGameMetadata::class)->execute($game);
                } catch (\Throwable $error) {
                    report($error);
                    $metadataFailures[] = $game->id;
                    $this->warn("Game {$game->id}: unverified kickoff metadata; its forecast remains held.");
                }
                $game->unsetRelations();
                gc_collect_cycles();
                gc_mem_caches();
            }
            // Reapply the future horizon using confirmed kickoff times, not imported placeholders.
            $games = Game::select($gameColumns)->with(['homeTeam', 'awayTeam'])
                ->whereIn('id', $games->modelKeys())
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED'])
                ->whereHas('sportEvent', fn ($q) => $q->where('starts_at', '>', now())->where('starts_at', '<=', now()->addDays((int) $days)))
                ->get();
            if ($games->isEmpty()) {
                return $metadataFailures === [] ? self::SUCCESS : self::FAILURE;
            }
            $ratingsKey = 'cfb:pregame-ratings:v2:'.$season.':'.now('America/Chicago')->toDateString();
            if (! Cache::has($ratingsKey)) {
                if ($this->refreshShared('cfb:sync-season-affiliations', ['--season' => (int) $season]) !== self::SUCCESS) {
                    $this->error('Season membership refresh failed; generation stopped.');

                    return self::FAILURE;
                }
                if ($this->refreshShared('cfb:import-fpi', ['--season' => (int) $season,
                    '--week' => (int) $games->min('week'), '--require-data' => true,
                    '--require-complete' => true]) !== self::SUCCESS) {
                    $this->error('Rating/metric refresh failed; generation stopped.');

                    return self::FAILURE;
                }
                Cache::put($ratingsKey, true, now()->addHours(24));
            }
            $personnelKey = 'cfb:personnel:v3:'.$season.':'.now('America/Chicago')->toDateString();
            if (! Cache::has($personnelKey)) {
                if ($this->refreshShared('cfb:sync-preseason-team-signals', ['--season' => (int) $season, '--include-coaches' => true, '--include-quarterbacks' => true, '--require-data' => true]) !== self::SUCCESS) {
                    $this->error('Personnel source refresh failed; generation stopped.');

                    return self::FAILURE;
                }
                Cache::put($personnelKey, true, now()->addHours(24));
            }
            $teams = $games->flatMap(fn ($g) => [$g->homeTeam, $g->awayTeam])->filter()->unique('id');
            $teamFailures = [];
            foreach ($teams as $team) {
                try {
                    $rosterKey = 'cfb:prop-roster:'.$team->id.':'.now('America/Chicago')->toDateString();
                    if (! Cache::has($rosterKey)) {
                        if ($players->execute((string) $team->espn_id) === 0) {
                            $this->warn("Roster refresh failed for team {$team->id}; affected games remain pending.");
                            $teamFailures[] = $team->id;

                            continue;
                        }
                        Cache::put($rosterKey, true, now()->addHours(24));
                    }
                    // Direct action: do not generate before queued injury work finishes.
                    $injuries->execute((string) $team->espn_id);
                    if (! $injuries->lastSyncReliable()) {
                        $this->warn("Injury feed unavailable for team {$team->id}; affected games remain pending.");
                        $teamFailures[] = $team->id;
                    }
                } catch (\Throwable $error) {
                    report($error);
                    $teamFailures[] = $team->id;
                }
            }
            $historyFailures = [];
            $playFailures = [];
            $history = Game::select($gameColumns)->where('season', $season)->where('status', 'STATUS_FINAL')
                ->where(fn ($q) => $q->whereIn('home_team_id', $teams->pluck('id')->all())->orWhereIn('away_team_id', $teams->pluck('id')->all()))->get();
            foreach ($history as $past) {
                $readiness = app(CfbDataReadiness::class);
                $quality = $readiness->forGame($past);
                $boxReady = $readiness->coreBoxscoreReady($past);
                if (! $quality['ready'] && (! $this->option('core-only') || ! $boxReady)) {
                    app(CfbPipelineStage::class)->run('game:'.$past->id, 'repair', ['quality' => $readiness->forGame($past)], function () use ($past, $readiness) {
                        app(SyncGameDetails::class)->execute((string) $past->espn_event_id);
                        $quality = $readiness->forGame($past);

                        return ['state' => $quality['ready'] ? 'complete' : 'partial', 'quality' => $quality];
                    }, false);
                }
                $quality = $readiness->forGame($past);
                foreach (['boxscore' => CfbOfficialBoxscoreRepair::class, 'plays' => CfbOfficialPlayTailRepair::class] as $component => $repairClass) {
                    $repair = app($repairClass);
                    if (! data_get($quality, 'components.'.$component.'.accepted') && $repair->hasReference($past)) {
                        $fixed = app(CfbPipelineStage::class)->run('game:'.$past->id, 'official-'.$component,
                            ['quality' => $quality, 'policy' => $repairClass::VERSION], fn () => $repair->execute($past), false);
                        if ($fixed['state'] !== 'complete') {
                            $this->warn("Game {$past->id}: official {$component} proof remains held.");
                        }
                        $quality = $readiness->forGame($past);
                    }
                }
                if (! $readiness->coreBoxscoreReady($past)
                    && collect(data_get($quality, 'components.boxscore.discrepancies', []))
                        ->contains(fn ($reason) => str_ends_with($reason, 'team_yards_do_not_reconcile'))) {
                    app(CfbPipelineStage::class)->run('game:'.$past->id, 'core-box-repair',
                        ['primary_boxscore' => data_get($quality, 'components.boxscore.source_hash')],
                        fn () => app(CfbCfbdTeamBoxscoreSync::class)->execute($past), false);
                }
                if (! $quality['ready']) {
                    $playFailures[] = $past->home_team_id;
                    $playFailures[] = $past->away_team_id;
                }
                // Verified boxscores are indispensable to the core forecast. Incomplete play
                // reconstruction holds EPA and the possession challenger, not current core scores.
                if (! $readiness->coreBoxscoreReady($past)) {
                    $historyFailures[] = $past->home_team_id;
                    $historyFailures[] = $past->away_team_id;
                }
            }
            foreach ($history as $past) {
                if ($this->option('core-only')) {
                    $playFailures[] = $past->home_team_id;
                    $playFailures[] = $past->away_team_id;
                } elseif (app(CfbDataReadiness::class)->forGame($past)['ready']
                    && ! in_array($past->home_team_id, $historyFailures, true) && ! in_array($past->away_team_id, $historyFailures, true)) {
                    $processed = app(CfbPipelineStage::class)->run('game:'.$past->id, 'normalize', [...app(CfbDataReadiness::class)->forGame($past), 'normalizer_version' => CfbDriveBuilder::VERSION, 'quality_schema' => 2], function () use ($past) {
                        $worker = Process::path(base_path())->timeout(120)->run([
                            PHP_BINARY, '-d', 'memory_limit=192M', base_path('artisan'), 'cfb:normalize-game', '--game='.$past->id,
                        ]);
                        $result = json_decode(trim($worker->output()), true);

                        return is_array($result) && isset($result['state']) && ($result['state'] !== 'complete' || $worker->successful()) ? $result
                            : ['state' => 'failed', 'error' => substr(trim($worker->errorOutput().' '.$worker->output()), 0, 2000), 'exit_code' => $worker->exitCode()];
                    });
                    if ($processed['state'] !== 'complete') {
                        $playFailures[] = $past->home_team_id;
                        $playFailures[] = $past->away_team_id;
                        $this->warn("Game {$past->id}: play-derived evidence remains held; verified core forecasts can continue.");
                    }
                }
                $past->unsetRelations();
                gc_collect_cycles();
                gc_mem_caches();
            }
            if ($this->refreshShared('cfb:calculate-elo', ['--season' => (int) $season]) !== self::SUCCESS) {
                $this->error('Elo integrity/update failed; generation stopped.');

                return self::FAILURE;
            }
            // Recompute after Elo and injury updates, including games finalized since the daily refresh.
            $metricTeamIds = $games->flatMap(fn ($game) => $coverage->requiredTeamIds($game))->unique()->values();
            foreach ($metricTeamIds->chunk(10) as $batch) {
                if ($this->refreshShared('cfb:calculate-team-metrics', ['--season' => (int) $season, '--team-ids' => $batch->implode(',')]) !== self::SUCCESS) {
                    $this->error('Team metric refresh failed; generation stopped.');

                    return self::FAILURE;
                }
            }
            if ($this->refreshShared('cfb:sync-odds', ['--days' => (int) $days]) !== self::SUCCESS) {
                $this->warn('Partial odds coverage; valid forecasts continue and unpriced markets remain unavailable.');
            }

            $results = [];
            foreach ($games as $game) {
                $inputs = ['metadata_reliable' => ! in_array($game->id, $metadataFailures, true), 'game_updated_at' => $game->updated_at?->toISOString(),
                    'history' => GameDataCheck::whereIn('game_id', $history->modelKeys())->whereNotNull('accepted_at')->pluck('source_hash')->sort()->values()->all(),
                    // A new hourly source refresh needs a new immutable forecast; --resume only skips completed work within that refresh window.
                    'refresh_window' => now()->format('Y-m-d-H'),
                    'team_revisions' => TeamMetric::whereIn('team_id', [$game->home_team_id, $game->away_team_id])->max('updated_at'),
                    'quote_revision' => MarketQuote::where('sport', 'cfb')->where('game_id', $game->id)->max('id'),
                    'injury_revision' => PlayerInjury::whereIn('team_id', [$game->home_team_id, $game->away_team_id])->max('updated_at')];
                $results[$game->id] = app(CfbPipelineStage::class)->run('game:'.$game->id, 'forecast', $inputs, function () use ($game, $historyFailures, $playFailures, $teamFailures, $metadataFailures) {
                    if (in_array($game->id, $metadataFailures, true)) {
                        return ['state' => 'blocked', 'reason' => 'unreliable_kickoff_metadata'];
                    }
                    if (array_intersect([$game->home_team_id, $game->away_team_id], $teamFailures)) {
                        return ['state' => 'blocked', 'reason' => 'unreliable_team_personnel'];
                    }
                    if (array_intersect([$game->home_team_id, $game->away_team_id], $historyFailures)) {
                        return ['state' => 'blocked', 'reason' => 'incomplete_historical_sources'];
                    }
                    if (array_intersect([$game->home_team_id, $game->away_team_id], $playFailures)
                        && ! data_get(app(CalculationReleaseSelector::class)->select($game->sportEvent, 'pregame')->configuration,
                            'inputs.validated_historical_play_metrics', false)) {
                        return ['state' => 'blocked', 'reason' => 'release_requires_complete_play_history'];
                    }
                    if (! $game->sportEvent?->starts_at?->isFuture()) {
                        return ['state' => 'blocked', 'reason' => 'kickoff_passed'];
                    }
                    $started = now();
                    $code = $this->call('cfb:generate-canonical-predictions', ['--game' => $game->id, '--isolate' => true]);

                    $output = CanonicalPrediction::where('sport_event_id', $game->sport_event_id)->where('sport', 'cfb')->where('phase', 'pregame')
                        ->where('publication_state', 'published')->where('generated_at', '>=', $started)
                        ->where('published_at', '<', $game->sportEvent->starts_at)->whereHas('markets')->latest('revision')->first();
                    $game->unsetRelations();
                    gc_collect_cycles();
                    gc_mem_caches();

                    return ['state' => $code === self::SUCCESS && $output ? 'complete' : 'failed', 'code' => $code,
                        'prediction_id' => $output?->public_id, 'reason' => $output ? null : 'no_fresh_published_forecast'];
                }, (bool) $this->option('resume'));
            }
            // Publish first: optional weather requests must not delay otherwise valid forecasts.
            try {
                if ($this->call('cfb:sync-game-weather', ['--season' => (int) $season,
                    '--from-date' => now()->toDateString(), '--days-forward' => (int) $days, '--force' => true,
                    ...($this->option('game') ? ['--game-id' => $this->option('game')] : [])]) !== self::SUCCESS) {
                    $this->warn('Weather refresh unavailable; missing weather remains unknown.');
                }
            } catch (\Throwable $error) {
                report($error);
                $this->warn('Weather refresh unavailable; missing weather remains unknown.');
            }

            $this->line(json_encode(['games' => $results], JSON_THROW_ON_ERROR));

            return collect($results)->every(fn ($r) => $r['state'] === 'complete') ? self::SUCCESS : 2;
        } finally {
            $lock->release();
        }
    }

    private function refreshShared(string $command, array $arguments): int
    {
        $result = app(CfbSharedInputRunner::class)->run($command, $arguments);
        if ($result['output'] !== '') {
            $this->line($result['output']);
        }

        return $result['exit_code'] ?? self::FAILURE;
    }
}
