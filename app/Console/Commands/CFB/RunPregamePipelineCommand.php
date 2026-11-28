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
use App\Services\CFB\Availability\AvailabilityEvidence;
use App\Services\CFB\CfbEpaBaselineEvidence;
use App\Services\CFB\Data\CfbCfbdTeamBoxscoreSync;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbOfficialBoxscoreRepair;
use App\Services\CFB\Data\CfbOfficialPlayTailRepair;
use App\Services\CFB\Data\CfbPipelineStage;
use App\Services\CFB\Data\CfbSharedInputRunner;
use App\Services\CFB\Data\CfbStoredPregameInputs;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Services\Predictions\CalculationReleaseSelector;
use App\Services\Sports\CfbGameCalendarRead;
use App\Services\Validation\CfbValidationCoverage;
use App\Support\CFB\CfbGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RunPregamePipelineCommand extends Command
{
    protected $signature = 'cfb:run-pregame-pipeline {--heartbeat-name= : Scheduled completion heartbeat name} {--run-id= : Internal worker execution identifier} {--batch-forecasts : Internal queued forecast mode} {--batch-offset= : Internal forecast batch cursor} {--season=} {--week=} {--days-forward=2} {--game=} {--games= : Comma-separated game IDs for scoped recovery} {--resume} {--dry-run} {--queued : Run on the bulk sync worker} {--core-only : Skip optional play reconstruction; approved release quality checks still apply}';

    protected $description = 'Refresh upcoming CFB injuries and odds before generating immutable forecasts';

    private string $runId;

    private int $selectedGames = 0;

    private array $metricFailedTeams = [];

    public function handle(SyncPlayerInjuries $injuries, SyncPlayers $players): int
    {
        $season = $this->option('season') ?? config('cfb.season.default');
        $days = $this->option('days-forward');
        $gameId = $this->option('game');
        if ($gameId !== null && ((! is_string($gameId) && ! is_int($gameId)) || preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $gameId) !== 1)) {
            $this->error('CFB pipeline requires a valid positive game ID.');

            return self::FAILURE;
        }
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
                '--heartbeat-name' => $this->option('heartbeat-name'), '--resume' => (bool) $this->option('resume'), '--core-only' => (bool) $this->option('core-only')],
                fn ($value) => $value !== null && $value !== false));
            $this->info('CFB pregame pipeline dispatched to the bulk sync worker; publication still requires worker completion.');

            return self::SUCCESS;
        }
        $this->runId = (string) ($this->option('run-id') ?: Str::uuid());
        if (! Str::isUuid($this->runId)) {
            $this->error('Invalid worker run identifier.');

            return self::FAILURE;
        }
        $offset = $this->option('batch-offset');
        if ($offset !== null && (! $this->option('run-id') || ! ctype_digit((string) $offset))) {
            $this->error('Invalid worker forecast batch cursor.');

            return self::FAILURE;
        }
        $this->selectedGames = 0;
        $this->metricFailedTeams = [];
        // A killed queue worker cannot release its lock. Let it expire after
        // the worker deadline and before Redis can redeliver the reservation.
        $lockSeconds = $this->option('run-id') ? RunPregamePipeline::TIMEOUT_SECONDS + 30 : 3600;
        $lock = Cache::lock('cfb:pregame-pipeline', $lockSeconds);
        if (! $lock->get()) {
            $this->warn('CFB pregame pipeline is already running.');

            return self::FAILURE;
        }
        try {
            if ($offset !== null) {
                return $this->runForecastBatch((int) $offset, (int) $season, (int) $days);
            }
            if ($this->option('batch-forecasts')) {
                $active = Cache::get('cfb:pregame:active-batch');
                if ($active !== null) {
                    return $active === $this->runId ? RunPregamePipeline::BATCH_PENDING : RunPregamePipeline::BATCH_BUSY;
                }
            }
            $this->recordProgress([]);
            $gameColumns = array_merge(CalculateElo::RESULT_COLUMNS, ['sport_event_id', 'updated_at', 'espn_event_id']);
            $candidateQuery = Game::query()->where('season', $season)
                ->when($this->option('game'), fn ($q, $v) => $q->whereKey($v))
                ->when($this->option('games'), fn ($q, $v) => $q->whereKey(array_map('intval', explode(',', $v))))
                ->when($this->option('week'), fn ($q, $v) => $q->where('week', $v))
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED']);
            $candidateQuery = app(CfbGameCalendarRead::class)->metadataCandidates(
                $candidateQuery, CarbonImmutable::now(), CarbonImmutable::now()->addDays((int) $days));
            if ((clone $candidateQuery)->count() > 200) {
                $this->error('CFB metadata candidate capacity exceeded; scope recovery to at most 200 games.');

                return self::FAILURE;
            }
            $games = app(CfbGameCalendarRead::class)->withBoundedObservation(
                $candidateQuery->with(['homeTeam', 'awayTeam', 'sportEvent'])->select($gameColumns))
                ->orderBy('game_date')->orderBy('game_time')->get();
            $coverage = app(CfbValidationCoverage::class);
            $games = $games->filter(fn ($game) => $coverage->requiredTeamIds($game) !== [])->values();
            $this->selectedGames = $games->count();
            $this->recordProgress([]);
            if ($games->isEmpty()) {
                $this->info('No upcoming CFB games in the pipeline horizon.');

                return self::SUCCESS;
            }
            if ($this->option('dry-run')) {
                $this->line(json_encode(['game_ids' => $games->modelKeys(), 'stages' => ['repair', 'ratings', 'metrics', 'forecast', 'price']]));

                return self::SUCCESS;
            }
            $metadataFailures = [];
            $metadataResults = [];
            foreach ($games as $game) {
                try {
                    app(SyncGameMetadata::class)->execute($game);
                } catch (\Throwable $error) {
                    report($error);
                    $metadataFailures[] = $game->id;
                    $metadataResults[$game->id] = $this->recordMetadataHold($game);
                    $this->warn("Game {$game->id}: unverified kickoff metadata; its forecast remains held.");
                }
                $game->unsetRelations();
                gc_collect_cycles();
                gc_mem_caches();
            }
            // Reapply the future horizon using confirmed kickoff times, not imported placeholders.
            $games = app(CfbGameCalendarRead::class)->withBoundedObservation(
                Game::select($gameColumns)->with(['homeTeam', 'awayTeam', 'sportEvent']))
                ->whereIn('id', $games->modelKeys())
                ->whereNotIn('id', $metadataFailures)
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED'])
                ->get()->filter(function (Game $game) use ($days, &$metadataResults): bool {
                    $kickoff = CfbGameStart::for($game);

                    if ($kickoff === null || ! $game->sportEvent?->starts_at?->equalTo($kickoff)) {
                        $metadataResults[$game->id] = $this->recordMetadataHold($game);

                        return false;
                    }

                    if (! $kickoff->gt(now()) || ! $kickoff->lte(now()->addDays((int) $days))) {
                        $this->info("Game {$game->id}: verified kickoff excluded after verification (outside future horizon).");

                        return false;
                    }

                    return true;
                })->values();
            $this->selectedGames = $games->count() + count($metadataResults);
            $this->recordProgress($metadataResults);
            if ($games->isEmpty()) {
                return $metadataResults === [] ? self::SUCCESS : 2;
            }
            $provider = app(CollegeFootballDataService::class);
            $provider->monitorQuota();
            $ratingsKey = 'cfb:pregame-ratings:v2:'.$season.':'.now('America/Chicago')->toDateString();
            if (! $provider->monthlyQuotaExhausted() && ! Cache::has($ratingsKey)) {
                $ratingsRefreshed = $this->refreshShared('cfb:sync-season-affiliations', ['--season' => (int) $season]) === self::SUCCESS
                    && $this->refreshShared('cfb:import-fpi', ['--season' => (int) $season,
                        '--week' => (int) $games->min('week'), '--require-data' => true,
                        '--require-complete' => true]) === self::SUCCESS;
                if ($ratingsRefreshed) {
                    Cache::put($ratingsKey, true, now()->addHours(24));
                } elseif (! $provider->monthlyQuotaExhausted()) {
                    $this->error('Season membership or rating refresh failed; generation stopped.');

                    return self::FAILURE;
                }
            }
            $personnelRefreshes = [
                ['cfb:personnel-season:v1:'.$season, now()->addDays(5), []],
                ['cfb:personnel-current:v1:'.$season, now()->addDay(), [
                    '--skip-returning-production' => true, '--skip-transfers' => true,
                    '--skip-talent' => true, '--skip-recruiting' => true,
                    '--include-coaches' => true, '--include-quarterbacks' => true,
                ]],
            ];
            $personnelRefreshFailed = false;
            foreach ($personnelRefreshes as [$key, $expiresAt, $options]) {
                if ($provider->monthlyQuotaExhausted() || Cache::has($key)) {
                    continue;
                }
                if ($this->refreshShared('cfb:sync-preseason-team-signals', [
                    '--season' => (int) $season, '--require-data' => true, ...$options,
                ]) === self::SUCCESS) {
                    Cache::put($key, true, $expiresAt);
                } elseif (! $provider->monthlyQuotaExhausted()) {
                    $personnelRefreshFailed = true;
                }
            }
            if ($personnelRefreshFailed) {
                $this->error('Personnel source refresh failed; generation stopped.');

                return self::FAILURE;
            }
            if ($this->call('cfb:sync-official-availability', ['--season' => (int) $season, '--days-forward' => (int) $days,
                ...($this->option('game') ? ['--game' => $this->option('game')] : [])]) !== self::SUCCESS) {
                $this->warn('Official availability coverage is incomplete; missing reports remain unknown in forecasts.');
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
                        $official = app(AvailabilityEvidence::class);
                        $covered = $games->filter(fn ($game) => in_array($team->id, [$game->home_team_id, $game->away_team_id]))
                            ->every(fn ($game) => $official->forGame($game, $team->id)['coverage']['complete']);
                        if (! $covered) {
                            $teamFailures[] = $team->id;
                        }
                        $this->warn("ESPN injury coverage unavailable for team {$team->id}; only games with complete official reports can continue.");
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
                    }, false, 21600);
                }
                $quality = $readiness->forGame($past);
                foreach (['boxscore' => CfbOfficialBoxscoreRepair::class, 'plays' => CfbOfficialPlayTailRepair::class] as $component => $repairClass) {
                    $repair = app($repairClass);
                    if (! data_get($quality, 'components.'.$component.'.accepted') && $repair->hasReference($past)) {
                        $fixed = app(CfbPipelineStage::class)->run('game:'.$past->id, 'official-'.$component,
                            ['quality' => $quality, 'policy' => $repairClass::VERSION], fn () => $repair->execute($past), false, 21600);
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
                        fn () => app(CfbCfbdTeamBoxscoreSync::class)->execute($past), false, 21600);
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
            $epaBaselines = [];
            foreach ($history as $past) {
                if ($this->option('core-only')) {
                    $playFailures[] = $past->home_team_id;
                    $playFailures[] = $past->away_team_id;
                } elseif (app(CfbDataReadiness::class)->forGame($past)['ready']
                    && ! in_array($past->home_team_id, $historyFailures, true) && ! in_array($past->away_team_id, $historyFailures, true)) {
                    $pastSeason = (int) $past->season;
                    if (! array_key_exists($pastSeason, $epaBaselines)) {
                        $epaBaselines[$pastSeason] = app(CfbEpaBaselineEvidence::class)->availableAt($pastSeason);
                    }
                    $processed = app(CfbPipelineStage::class)->run('game:'.$past->id, 'normalize', [...app(CfbDataReadiness::class)->forGame($past), 'normalizer_version' => CfbDriveBuilder::VERSION, 'quality_schema' => 2, 'epa_baseline_available_at' => $epaBaselines[$pastSeason]], function () use ($past) {
                        $worker = Process::path(base_path())->timeout(120)->run([
                            PHP_BINARY, '-d', 'memory_limit=192M', base_path('artisan'), 'cfb:normalize-game', '--game='.$past->id,
                        ]);
                        $result = json_decode(trim($worker->output()), true);

                        return is_array($result) && isset($result['state']) && ($result['state'] !== 'complete' || $worker->successful()) ? $result
                            : ['state' => 'failed', 'error' => substr(trim($worker->errorOutput().' '.$worker->output()), 0, 2000), 'exit_code' => $worker->exitCode()];
                    }, true, 21600);
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
            $metricFailures = [];
            foreach ($metricTeamIds->chunk(10) as $batch) {
                if ($provider->monthlyQuotaExhausted()) {
                    break;
                }
                try {
                    $result = app(CfbSharedInputRunner::class)->run('cfb:calculate-team-metrics', ['--season' => (int) $season, '--team-ids' => $batch->implode(',')]);
                } catch (\Throwable $error) {
                    report($error);
                    $result = ['exit_code' => 1, 'output' => 'Metrics subprocess failed; its required teams remain held.'];
                }
                $this->line($result['output']);
                if ($provider->monthlyQuotaExhausted()) {
                    break;
                }
                if (($result['exit_code'] ?? 1) !== self::SUCCESS) {
                    $failed = $batch->all();
                    if (preg_match('/^CFB_METRIC_BATCH_RESULT=(.+)$/m', $result['output'], $match)) {
                        $report = json_decode($match[1], true);
                        $good = $report['refreshed_team_ids'] ?? [];
                        $bad = $report['failed_team_ids'] ?? [];
                        if (($result['exit_code'] ?? null) === 2 && is_array($good) && is_array($bad) && $good !== [] && $bad !== [] && $batch->diff(array_merge($good, $bad))->isEmpty()
                            && count(array_unique(array_merge($good, $bad))) === $batch->count() && array_intersect($good, $bad) === []) {
                            $failed = $bad;
                        }
                    }
                    $metricFailures = array_merge($metricFailures, $failed);
                    $this->warn('Metric refresh failed for '.count($failed).' teams; their games remain held.');
                    $this->metricFailedTeams = array_values(array_unique($metricFailures));
                    $this->recordProgress([]);
                }
            }
            if ($this->refreshShared('cfb:sync-odds', ['--days' => (int) $days]) !== self::SUCCESS) {
                $this->warn('Partial odds coverage; valid forecasts continue and unpriced markets remain unavailable.');
            }

            $historyFingerprint = hash('sha256', GameDataCheck::whereIn('game_id', $history->modelKeys())
                ->whereNotNull('accepted_at')->pluck('source_hash')->sort()->values()->toJson());
            $proofsByGame = GameDataCheck::whereIn('game_id', $history->modelKeys())->whereNotNull('accepted_at')
                ->get(['game_id', 'source_hash'])->groupBy('game_id');
            $historyFingerprintByTeam = [];
            foreach ($history as $past) {
                foreach ([$past->home_team_id, $past->away_team_id] as $teamId) {
                    $historyFingerprintByTeam[$teamId] = array_merge($historyFingerprintByTeam[$teamId] ?? [], $proofsByGame->get($past->id, collect())->pluck('source_hash')->all());
                }
            }
            foreach ($historyFingerprintByTeam as &$proofs) {
                sort($proofs);
                $proofs = hash('sha256', json_encode($proofs, JSON_THROW_ON_ERROR));
            }
            unset($proofs);
            $refreshWindow = now()->format('Y-m-d-H');
            $context = compact('historyFingerprint', 'historyFingerprintByTeam', 'refreshWindow', 'metricFailures', 'teamFailures', 'historyFailures', 'playFailures', 'metadataFailures');
            $games = $games->sortBy([
                fn (Game $a, Game $b): int => [$a->sportEvent?->starts_at?->timestamp, $a->id] <=> [$b->sportEvent?->starts_at?->timestamp, $b->id],
            ])->values();
            if ($this->option('batch-forecasts')) {
                Cache::put('cfb:pregame:inputs:'.$this->runId, [
                    ...$context, 'gameIds' => $games->modelKeys(), 'selected_count' => $this->selectedGames, 'season' => (int) $season, 'days' => (int) $days,
                ], now()->addHours(2));

                Cache::put('cfb:pregame:active-batch', $this->runId, now()->addHours(2));

                return RunPregamePipeline::BATCH_PENDING;
            }

            return $this->forecastGames($games, $metadataResults, $context, (int) $season, (int) $days);
        } finally {
            $lock->release();
        }
    }

    private function recordMetadataHold(Game $game): array
    {
        return app(CfbPipelineStage::class)->run('game:'.$game->id, 'forecast', [
            'metadata_reliable' => false, 'game_updated_at' => $game->updated_at?->toISOString(),
            'current_kickoff_observation' => $game->getRawOriginal('kickoff_observation'),
            'sport_event_id' => $game->sport_event_id,
        ], fn (): array => ['state' => 'blocked', 'reason' => 'unreliable_kickoff_metadata'], false);
    }

    private function runForecastBatch(int $offset, int $season, int $days): int
    {
        $context = Cache::get('cfb:pregame:inputs:'.$this->runId);
        if (Cache::get('cfb:pregame:active-batch') !== $this->runId || ! is_array($context) || $context['season'] !== $season || $context['days'] !== $days
            || $offset >= count($context['gameIds']) || $offset % RunPregamePipeline::BATCH_SIZE !== 0) {
            $this->error('CFB forecast batch checkpoint missing, expired, or invalid.');

            return self::FAILURE;
        }

        $this->selectedGames = (int) ($context['selected_count'] ?? count($context['gameIds']));
        $this->metricFailedTeams = array_values(array_unique($context['metricFailures']));
        $ids = array_slice($context['gameIds'], $offset, RunPregamePipeline::BATCH_SIZE);
        $games = Game::with('sportEvent')->whereIn('id', $ids)->get()->keyBy('id');
        $results = Cache::get('cfb:pregame:run:'.$this->runId, [])['games'] ?? [];
        foreach (array_diff($ids, $games->keys()->all()) as $missingId) {
            $results[$missingId] = ['state' => 'failed', 'reason' => 'missing_scheduled_game'];
        }

        $code = $this->forecastGames(collect($ids)->map(fn (int $id) => $games->get($id))->filter(), $results, $context, $season, $days);

        return $offset + RunPregamePipeline::BATCH_SIZE < count($context['gameIds']) ? RunPregamePipeline::BATCH_PENDING : $code;
    }

    private function forecastGames(Collection $games, array $results, array $context, int $season, int $days): int
    {
        ['historyFingerprint' => $historyFingerprint, 'refreshWindow' => $refreshWindow, 'metricFailures' => $metricFailures,
            'teamFailures' => $teamFailures, 'historyFailures' => $historyFailures, 'playFailures' => $playFailures,
            'metadataFailures' => $metadataFailures] = $context;
        $provider = app(CollegeFootballDataService::class);
        $coverage = app(CfbValidationCoverage::class);
        foreach ($games as $game) {
            if (isset($results[$game->id])) {
                continue;
            }
            $quotaExhausted = $provider->monthlyQuotaExhausted();
            $stored = $quotaExhausted || filled(config('services.collegefootballdata.api_key'))
                ? app(CfbStoredPregameInputs::class)->assess($game, now()->toImmutable())
                : null;
            if ($stored !== null && ($quotaExhausted || $stored['holds'] !== [])) {
                $this->warn("Game {$game->id}: CFBD pregame input check ".json_encode($stored, JSON_THROW_ON_ERROR));
            }
            $requiredTeams = $coverage->requiredTeamIds($game);
            $release = null;
            try {
                $release = app(CalculationReleaseSelector::class)->select($game->sportEvent, 'pregame');
            } catch (\Throwable $error) {
                report($error);
            }
            $executed = false;
            $generationAttempted = false;
            $previousId = (int) CanonicalPrediction::where('sport_event_id', $game->sport_event_id)->max('id');
            $inputs = ['release' => $release?->only(['id', 'configuration_hash', 'configuration', 'code_revision', 'input_schema_version']), 'stored_cfbd_inputs' => $stored, 'metric_failures' => array_values(array_intersect($requiredTeams, $metricFailures)),
                'team_failures' => array_values(array_intersect($requiredTeams, $teamFailures)),
                'history_failures' => array_values(array_intersect($requiredTeams, $historyFailures)),
                'play_failures' => array_values(array_intersect($requiredTeams, $playFailures)), 'metadata_reliable' => ! in_array($game->id, $metadataFailures, true), 'game_updated_at' => $game->updated_at?->toISOString(),
                'history' => isset($context['historyFingerprintByTeam'])
                    ? array_intersect_key($context['historyFingerprintByTeam'], array_flip($requiredTeams)) : $historyFingerprint,
                // A new hourly source refresh needs a new immutable forecast; --resume only skips completed work within that refresh window.
                'refresh_window' => $refreshWindow,
                'team_revisions' => TeamMetric::whereIn('team_id', [$game->home_team_id, $game->away_team_id])->max('updated_at'),
                'quote_revision' => MarketQuote::where('sport', 'cfb')->where('game_id', $game->id)->max('id'),
                'injury_revision' => PlayerInjury::whereIn('team_id', [$game->home_team_id, $game->away_team_id])->max('updated_at')];
            $results[$game->id] = app(CfbPipelineStage::class)->run('game:'.$game->id, 'forecast', $inputs, function () use ($game, $release, $stored, $historyFailures, $playFailures, $teamFailures, $metadataFailures, $metricFailures, $requiredTeams, $previousId, &$executed, &$generationAttempted) {
                $executed = true;
                if (! $release) {
                    return ['state' => 'blocked', 'reason' => 'unavailable_calculation_release'];
                }
                if ($stored !== null && $stored['holds'] !== []) {
                    return ['state' => 'blocked', 'reason' => 'unverified_or_stale_stored_cfbd_inputs'];
                }
                if (array_intersect($requiredTeams, $metricFailures)) {
                    return ['state' => 'blocked', 'reason' => 'unreliable_team_metrics'];
                }
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
                    && ! data_get($release->configuration,
                        'inputs.validated_historical_play_metrics', false)) {
                    return ['state' => 'blocked', 'reason' => 'release_requires_complete_play_history'];
                }
                if (! $game->sportEvent?->starts_at?->isFuture()) {
                    return ['state' => 'blocked', 'reason' => 'kickoff_passed'];
                }
                $started = now();
                $generationAttempted = true;
                $code = $this->call('cfb:generate-canonical-predictions', ['--game' => $game->id, '--isolate' => true]);

                $output = CanonicalPrediction::where('sport_event_id', $game->sport_event_id)->where('sport', 'cfb')->where('phase', 'pregame')
                    ->where('publication_state', 'published')->where('generated_at', '>=', $started)
                    ->where('published_at', '<', $game->sportEvent->starts_at)->whereHas('markets')->latest('revision')->first();
                $game->unsetRelations();
                gc_collect_cycles();
                gc_mem_caches();

                return ['state' => $code === self::SUCCESS && $output ? 'complete' : 'failed', 'code' => $code,
                    'prediction_id' => $output?->public_id, 'published_count' => $output && $output->id > $previousId ? 1 : 0, 'reused_count' => $output && $output->id <= $previousId ? 1 : 0, 'reason' => $output ? null : 'no_fresh_published_forecast'];
            }, (bool) $this->option('resume'));
            $results[$game->id]['published_count'] = $generationAttempted ? CanonicalPrediction::where('sport_event_id', $game->sport_event_id)
                ->where('sport', 'cfb')->where('phase', 'pregame')->where('id', '>', $previousId)->whereNotNull('published_at')->count() : 0;
            $results[$game->id]['reused_count'] = $executed ? (int) ($results[$game->id]['reused_count'] ?? 0) : ($results[$game->id]['state'] === 'complete' ? 1 : 0);
            $this->recordProgress($results);
        }
        // Publish first: optional weather requests must not delay otherwise valid forecasts.
        foreach ($games->pluck('id') as $weatherGameId) {
            try {
                if ($this->call('cfb:sync-game-weather', ['--season' => (int) $season,
                    '--from-date' => now()->toDateString(), '--days-forward' => (int) $days, '--force' => true,
                    '--game-id' => $weatherGameId]) !== self::SUCCESS) {
                    $this->warn('Weather refresh unavailable; missing weather remains unknown.');
                }
            } catch (\Throwable $error) {
                report($error);
                $this->warn('Weather refresh unavailable; missing weather remains unknown.');
            }
        }

        $this->line(json_encode($this->recordProgress($results), JSON_THROW_ON_ERROR));

        return collect($results)->every(fn ($r) => $r['state'] === 'complete') ? self::SUCCESS : 2;
    }

    private function recordProgress(array $results): array
    {
        $rows = collect($results);
        $summary = ['run_id' => $this->runId, 'selected_count' => $this->selectedGames, 'pending_count' => max(0, $this->selectedGames - $rows->count()),
            'metric_failed_team_ids' => $this->metricFailedTeams, 'games' => $results, 'publication_count' => $rows->sum('published_count'),
            'reused_count' => $rows->sum('reused_count'), 'complete_count' => $rows->where('state', 'complete')->count(),
            'held_count' => $rows->where('state', 'blocked')->count(), 'failed_count' => $rows->whereNotIn('state', ['complete', 'blocked'])->count()];
        Cache::put('cfb:pregame:run:'.$this->runId, $summary, now()->addDay());

        return $summary;
    }

    private function refreshShared(string $command, array $arguments): int
    {
        try {
            $result = app(CfbSharedInputRunner::class)->run($command, $arguments);
        } catch (\Throwable $error) {
            report($error);
            $this->warn("Shared refresh {$command} failed: {$error->getMessage()}");

            return self::FAILURE;
        }
        if ($result['output'] !== '') {
            $this->line($result['output']);
        }

        return $result['exit_code'] ?? self::FAILURE;
    }
}
