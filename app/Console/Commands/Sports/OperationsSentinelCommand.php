<?php

namespace App\Console\Commands\Sports;

use App\Actions\ESPN\NBA\SyncGamesFromScoreboard;
use App\Models\MLB\Game as MlbGame;
use App\Models\ValidationRun;
use App\Services\Api\V2\SportContextResolver;
use App\Services\CommandHeartbeatService;
use App\Services\ESPN\NBA\EspnService;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Sports\SportsDateWindowService;
use App\Services\Sports\SportsPipelineRegistry;
use App\Support\MlbRegularSeasonWindow;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationsSentinelCommand extends Command
{
    private const LOCK_SECONDS = 7200;

    protected $signature = 'sports:operations-sentinel
        {--sport= : Sport to run: nba, nfl, mlb, cbb, cfb, wcbb, wnba}
        {--from-date= : Start date in YYYY-MM-DD format}
        {--to-date= : End date in YYYY-MM-DD format}
        {--season= : Season to repair/grade}
        {--repair : Run the canonical repair pipeline; kept as an explicit operator alias because repair is the default behavior}
        {--ai : Run AI prediction analysis and operations review; kept as an explicit operator alias unless skip flags are provided}
        {--skip-sync-pipeline : Skip registry sync dependencies such as injuries, weather, odds, player props, and futures}
        {--skip-stats : Skip player/team stat refresh}
        {--skip-model-pipeline : Skip grading, Elo, team metrics, and prediction generation}
        {--stat-lookback-days=3 : Days back to refresh player/team stats from game details}
        {--stat-limit=300 : Limit game-detail stat refresh dispatches per sentinel pass}
        {--skip-queue-drain : Skip draining queued sync jobs before model generation and validation}
        {--queue-drain-queue=sync : Queue to use for sentinel-dispatched sync jobs}
        {--queue-drain-max-time=600 : Max seconds to drain queued sync jobs before continuing}
        {--auto-repair-validation : Run one targeted repair pass from validation findings before returning the final status}
        {--ai-rate-limit-retries=1 : Number of AI daily prediction retries when the provider rate limits}
        {--ai-rate-limit-delay=120 : Seconds to wait between AI rate-limit retries}
        {--skip-ai-analysis : Skip daily prediction AI analysis before validation}
        {--skip-ai-review : Skip the operations AI review after validation}
        {--skip-validation : Skip the final validation run}';

    protected $description = 'Run a sport operations sentinel without duplicating sport-specific repair logic';

    /**
     * @var array<string, class-string>
     */
    private array $scoreboardSyncActions = [
        'nba' => SyncGamesFromScoreboard::class,
        'nfl' => \App\Actions\ESPN\NFL\SyncGamesFromScoreboard::class,
        'mlb' => \App\Actions\ESPN\MLB\SyncGamesFromScoreboard::class,
        'cbb' => \App\Actions\ESPN\CBB\SyncGamesFromScoreboard::class,
        'cfb' => \App\Actions\ESPN\CFB\SyncGamesFromScoreboard::class,
        'wcbb' => \App\Actions\ESPN\WCBB\SyncGamesFromScoreboard::class,
        'wnba' => \App\Actions\ESPN\WNBA\SyncGamesFromScoreboard::class,
    ];

    /**
     * @var array<string, class-string>
     */
    private array $scoreboardEspnServices = [
        'nba' => EspnService::class,
        'nfl' => \App\Services\ESPN\NFL\EspnService::class,
        'mlb' => \App\Services\ESPN\MLB\EspnService::class,
        'cbb' => \App\Services\ESPN\CBB\EspnService::class,
        'cfb' => \App\Services\ESPN\CFB\EspnService::class,
        'wcbb' => \App\Services\ESPN\WCBB\EspnService::class,
        'wnba' => \App\Services\ESPN\WNBA\EspnService::class,
    ];

    /**
     * @var array<string, string>
     */
    private array $gameDetailsCommands = [
        'nba' => 'espn:sync-nba-game-details',
        'nfl' => 'espn:sync-nfl-game-details',
        'mlb' => 'espn:sync-mlb-game-details',
        'cbb' => 'espn:sync-cbb-game-details',
        'cfb' => 'espn:sync-cfb-game-details',
        'wcbb' => 'espn:sync-wcbb-game-details',
        'wnba' => 'espn:sync-wnba-game-details',
    ];

    /**
     * These detail commands only sweep completed games when no event ID is provided,
     * so they are safe to use as season-to-date stat backfills before metrics run.
     *
     * @var array<int, string>
     */
    private array $seasonToDateStatBackfillSports = ['nba', 'nfl', 'mlb'];

    public function handle(SportsPipelineRegistry $registry): int
    {
        $lock = Cache::lock('sports:operations-sentinel', self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->warn('Another sports operations sentinel is already running. Skipping this run to avoid overlapping stat and metric repairs.');

            return self::FAILURE;
        }

        try {
            return $this->runSentinel($registry);
        } finally {
            $lock->release();
        }
    }

    private function runSentinel(SportsPipelineRegistry $registry): int
    {
        $sport = strtolower((string) $this->option('sport'));

        if ($sport === '') {
            $this->error('The --sport option is required.');

            return self::FAILURE;
        }

        if (! $registry->supportsSport($sport)) {
            $this->error("Unsupported sport: {$sport}.");

            return self::FAILURE;
        }

        $syncClass = $this->scoreboardSyncActions[$sport] ?? null;

        if (! $syncClass) {
            $this->error("No operations sentinel is configured for {$sport} yet.");

            return self::FAILURE;
        }

        $dateWindows = app(SportsDateWindowService::class);
        $pastStatusLookbackDays = (int) config('validation.thresholds.past_scheduled_game_status.lookback_days', 7);
        $season = (int) ($this->option('season') ?: $this->defaultSeason($sport));
        $fromDate = $dateWindows->parseLocalDate($this->option('from-date') ?: $this->defaultScoreboardFromDate($sport, $season, $pastStatusLookbackDays));
        $toDate = $dateWindows->parseLocalDate($this->option('to-date') ?: now()->addDays(7)->toDateString());
        $referenceDate = $dateWindows->parseLocalDate(now()->toDateString());
        $statLookbackDays = max(1, (int) $this->option('stat-lookback-days'));
        $statLimit = max(0, (int) $this->option('stat-limit'));
        $repairRequested = (bool) $this->option('repair');
        $aiRequested = (bool) $this->option('ai');

        if ($toDate->lt($fromDate)) {
            $this->error('--to-date must be on or after --from-date.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Running %s operations sentinel for %s through %s (%s).',
            strtoupper($sport),
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $dateWindows->timezone(),
        ));

        if ($repairRequested) {
            $this->line('Repair mode requested; running the canonical repair pipeline.');
        }

        if ($aiRequested) {
            $this->line('AI mode requested; daily prediction analysis and operations review will run unless explicitly skipped.');
        }

        $stageContext = app(SeasonStageService::class)->context($sport, $season, $referenceDate);
        $this->line(sprintf(
            'Season stage: %s (%s); active window %s through %s.',
            $stageContext->stage,
            $stageContext->stageGroup,
            $stageContext->activeWindow->localStartDate(),
            $stageContext->activeWindow->localEndDate(),
        ));

        $sync = $this->scoreboardSyncAction($sport, $syncClass);
        $synced = 0;

        for ($date = $fromDate; $date->lte($toDate); $date = $date->addDay()) {
            $scoreboardDate = $date->format('Ymd');
            $this->line('Syncing '.strtoupper($sport)." scoreboard {$scoreboardDate}...");
            $synced += (int) $sync->execute($scoreboardDate);
        }

        $this->info('Synced '.$synced.' '.strtoupper($sport).' game row update(s).');
        app(CommandHeartbeatService::class)->recordSuccess(
            $this->renderCommand("espn:sync-{$sport}-games-scoreboard", [
                '--from-date' => $fromDate->toDateString(),
                '--to-date' => $toDate->toDateString(),
            ]),
            $sport,
            'operations-sentinel',
            [
                'parent_command' => 'sports:operations-sentinel',
                'synced_rows' => $synced,
            ],
        );

        if (! $this->option('skip-stats')) {
            $this->refreshStats($sport, $statLookbackDays, $statLimit);
        }

        if (! $this->option('skip-sync-pipeline')) {
            $this->runSyncDependencyPipeline($registry, $sport, $season, $referenceDate);
        }

        if (! $this->option('skip-queue-drain')) {
            $this->drainQueuedSyncJobs($sport);
        }

        if (! $this->option('skip-model-pipeline')) {
            $this->runModelPipeline($registry, $sport, $season, $referenceDate);
        }

        if (! $this->option('skip-ai-analysis')) {
            $this->runAiPredictionAnalysisPipeline($registry, $sport, $season, $referenceDate);
        }

        if ($this->option('skip-validation')) {
            return self::SUCCESS;
        }

        [$validationExitCode, $validationRun] = $this->runValidationPass($sport, 'after sentinel repair pass');

        if ($this->shouldAutoRepairValidation() && $validationRun && $this->repairValidationFindings($registry, $validationRun, $sport, $season, $referenceDate)) {
            if (! $this->option('skip-queue-drain')) {
                $this->drainQueuedSyncJobs($sport);
            }

            if (! $this->option('skip-model-pipeline')) {
                $this->runModelPipeline($registry, $sport, $season, $referenceDate);
            }

            if (! $this->option('skip-ai-analysis')) {
                $this->runAiPredictionAnalysisPipeline($registry, $sport, $season, $referenceDate);
            }

            [$validationExitCode] = $this->runValidationPass($sport, 'after targeted validation repair');
        }

        if (! $this->option('skip-ai-review')) {
            $this->info('Running '.strtoupper($sport).' operations AI review...');
            $this->call('operations:ai-review', [
                '--sport' => $sport,
                '--season' => $season,
                '--date' => $referenceDate->toDateString(),
            ]);
        }

        return $validationExitCode;
    }

    /**
     * @return array{0: int, 1: ValidationRun|null}
     */
    private function runValidationPass(string $sport, string $label): array
    {
        $this->info('Running '.strtoupper($sport)." validation {$label}...");
        $lastRunId = (int) (ValidationRun::query()
            ->where('command_name', 'healthcheck:validate-data')
            ->max('id') ?? 0);

        $exitCode = $this->call('healthcheck:validate-data', [
            '--sport' => $sport,
        ]);

        $run = ValidationRun::query()
            ->where('command_name', 'healthcheck:validate-data')
            ->where('id', '>', $lastRunId)
            ->latest('id')
            ->first();

        return [$exitCode, $run];
    }

    private function shouldAutoRepairValidation(): bool
    {
        return (bool) $this->option('auto-repair-validation')
            || ! $this->option('skip-stats')
            || ! $this->option('skip-sync-pipeline')
            || ! $this->option('skip-model-pipeline');
    }

    private function repairValidationFindings(SportsPipelineRegistry $registry, ValidationRun $run, string $sport, int $season, CarbonInterface $referenceDate): bool
    {
        $findings = $run->findings()
            ->where('sport', $sport)
            ->whereIn('status', ['warning', 'failing'])
            ->get();

        if ($findings->isEmpty()) {
            $this->line('Validation produced no warning/failing findings that require a sentinel repair pass.');

            return false;
        }

        $repairableGameDetailChecks = [
            'validation_current_day_game_data_freshness',
            'validation_upcoming_game_readiness',
            'validation_past_scheduled_game_status',
            'validation_finalized_data_completeness',
        ];

        $syncDependencyChecks = [
            'validation_odds_completeness',
            'validation_player_prop_freshness',
            'validation_weather_completeness',
            'validation_injury_freshness',
            'validation_futures_odds_freshness',
        ];

        $modelPipelineChecks = [
            'validation_prediction_completeness',
            'validation_pipeline_order',
            'validation_upcoming_game_readiness',
            'validation_finalized_data_completeness',
        ];

        $handled = false;
        $checkTypes = $findings->pluck('check_type')->unique()->values();
        $this->warn(sprintf(
            'Validation run %d produced %d warning/failing finding(s): %s',
            $run->id,
            $findings->count(),
            $checkTypes->implode(', '),
        ));
        $gameDetailEventIds = $findings
            ->whereIn('check_type', $repairableGameDetailChecks)
            ->flatMap(fn ($finding) => $this->eventIdsFromFinding($sport, (array) $finding->facts))
            ->unique()
            ->values();

        if ($gameDetailEventIds->isNotEmpty()) {
            $command = $this->gameDetailsCommands[$sport] ?? null;

            if ($command) {
                $this->warn(sprintf(
                    'Validation found %d stale/incomplete %s game(s); running targeted game-detail repair.',
                    $gameDetailEventIds->count(),
                    strtoupper($sport),
                ));

                foreach ($gameDetailEventIds as $eventId) {
                    $this->callAndRecord($command, [
                        'eventId' => (string) $eventId,
                        '--sync' => true,
                    ], $sport, 'sentinel-repair');
                    $this->output->write(Artisan::output());
                }

                $handled = true;
            }
        }

        if ($checkTypes->intersect($syncDependencyChecks)->isNotEmpty() && ! $this->option('skip-sync-pipeline')) {
            $this->warn('Validation found stale market/dependency data; rerunning sync dependency pipeline once.');
            $this->runSyncDependencyPipeline($registry, $sport, $season, $referenceDate);
            $handled = true;
        }

        if ($checkTypes->intersect($modelPipelineChecks)->isNotEmpty() && ! $this->option('skip-model-pipeline')) {
            $this->warn('Validation found downstream model or grading drift; model pipeline will rerun after queued repairs drain.');
            $handled = true;
        }

        if (! $handled) {
            $this->warn('Validation findings were not repairable by the sentinel; leaving final status to validation and AI review.');
        }

        return $handled;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return Collection<int, string>
     */
    private function eventIdsFromFinding(string $sport, array $facts): Collection
    {
        $eventIds = collect((array) data_get($facts, 'sample_games', []))
            ->map(fn (mixed $game): ?string => is_array($game) ? data_get($game, 'espn_event_id') : null)
            ->filter()
            ->map(fn (mixed $eventId): string => (string) $eventId);

        $gameIds = collect((array) data_get($facts, 'sample_game_ids', []))
            ->merge(collect((array) data_get($facts, 'sample_games', []))
                ->map(fn (mixed $game): mixed => is_array($game) ? data_get($game, 'game_id') : null))
            ->filter()
            ->map(fn (mixed $gameId): int => (int) $gameId)
            ->unique()
            ->values();

        if ($gameIds->isEmpty()) {
            return $eventIds->values();
        }

        $gameModel = app(SportContextResolver::class)->find($sport)?->models['game'] ?? null;

        if (! is_string($gameModel) || ! is_subclass_of($gameModel, Model::class)) {
            return $eventIds->values();
        }

        $gameTable = (new $gameModel)->getTable();
        $eventColumns = collect(['espn_event_id', 'espn_id'])
            ->filter(fn (string $column): bool => Schema::hasColumn($gameTable, $column))
            ->values();

        if ($eventColumns->isEmpty()) {
            return $eventIds->values();
        }

        $gameEventIds = $gameModel::query()
            ->whereIn('id', $gameIds->all())
            ->get($eventColumns->all())
            ->map(function (Model $game) use ($eventColumns): ?string {
                foreach ($eventColumns as $column) {
                    $value = $game->getAttribute($column);

                    if ($value !== null && $value !== '') {
                        return (string) $value;
                    }
                }

                return null;
            })
            ->filter()
            ->map(fn (mixed $eventId): string => (string) $eventId);

        return $eventIds->merge($gameEventIds)->unique()->values();
    }

    /**
     * @param  class-string  $syncClass
     */
    private function scoreboardSyncAction(string $sport, string $syncClass): object
    {
        try {
            return app($syncClass);
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'ESPN sport key must be provided.') {
                throw $exception;
            }

            $serviceClass = $this->scoreboardEspnServices[$sport] ?? null;

            if (! $serviceClass) {
                throw $exception;
            }

            return new $syncClass(new $serviceClass);
        }
    }

    private function refreshStats(string $sport, int $lookbackDays, int $limit): void
    {
        $gameDetailsCommand = $this->gameDetailsCommands[$sport] ?? null;

        if (! $gameDetailsCommand) {
            $this->warn('Player/team stat refresh is not configured for '.strtoupper($sport).'.');

            return;
        }

        if (in_array($sport, $this->seasonToDateStatBackfillSports, true)) {
            $this->info(sprintf(
                'Backfilling %s completed games still missing player/team stats across the season.',
                strtoupper($sport),
            ));

            $this->callAndRecord($gameDetailsCommand, [
                '--latest' => true,
                '--limit' => $limit,
                '--queue' => $this->queueDrainQueue(),
            ], $sport, 'operations-sentinel');
            $this->output->write(Artisan::output());
        }

        $this->info(sprintf(
            'Refreshing %s recent player/team stats from game details for the last %d day(s).',
            strtoupper($sport),
            $lookbackDays,
        ));

        $this->callAndRecord($gameDetailsCommand, [
            '--refresh-existing' => true,
            '--lookback-days' => $lookbackDays,
            '--latest' => true,
            '--limit' => $limit,
            '--queue' => $this->queueDrainQueue(),
        ], $sport, 'operations-sentinel');
        $this->output->write(Artisan::output());
    }

    private function runModelPipeline(SportsPipelineRegistry $registry, string $sport, int $season, CarbonInterface $referenceDate): void
    {
        $this->info('Running '.strtoupper($sport).' canonical model pipeline...');

        foreach ($this->registrySteps($registry, $sport, 'predict', $season, $referenceDate) as $step) {
            $this->callRegistryStep($step);
        }
    }

    private function runSyncDependencyPipeline(SportsPipelineRegistry $registry, string $sport, int $season, CarbonInterface $referenceDate): void
    {
        $this->info('Running '.strtoupper($sport).' canonical sync dependencies...');

        foreach ($this->registrySteps($registry, $sport, 'sync', $season, $referenceDate) as $step) {
            if ($this->isAlreadyHandledSyncStep($step['label'])) {
                continue;
            }

            $this->callRegistryStep($step);
        }
    }

    private function runAiPredictionAnalysisPipeline(SportsPipelineRegistry $registry, string $sport, int $season, CarbonInterface $referenceDate): void
    {
        $steps = array_values(array_filter(
            $this->registrySteps($registry, $sport, 'ai', $season, $referenceDate),
            fn (array $step): bool => $step['command'] === 'sports:ai-daily-predictions'
        ));

        if ($steps === []) {
            $this->warn('Daily prediction AI analysis is not configured for '.strtoupper($sport).'.');

            return;
        }

        $this->info('Running '.strtoupper($sport).' daily prediction AI analysis...');

        foreach ($steps as $step) {
            if ($step['command'] === 'sports:ai-daily-predictions') {
                $step['arguments']['--retry-rate-limit'] = max(0, (int) $this->option('ai-rate-limit-retries'));
                $step['arguments']['--retry-rate-limit-delay'] = max(1, (int) $this->option('ai-rate-limit-delay'));
            }

            $this->callRegistryStep($step);
        }
    }

    private function drainQueuedSyncJobs(string $sport): void
    {
        $maxTime = max(60, (int) $this->option('queue-drain-max-time'));
        $queue = $this->queueDrainQueue();
        $pendingJobs = $this->pendingQueueJobs($queue);

        $this->info(sprintf(
            'Draining queued %s sync jobs before model generation and validation%s (max %d seconds)...',
            strtoupper($sport),
            $pendingJobs === null ? " on [{$queue}]" : " ({$pendingJobs} pending [{$queue}] job(s))",
            $maxTime,
        ));

        $this->callAndRecord('queue:work', [
            '--stop-when-empty' => true,
            '--queue' => $queue,
            '--timeout' => 120,
            '--max-time' => $maxTime,
        ], $sport, 'operations-sentinel');
        $this->output->write(Artisan::output());

        $remainingJobs = $this->pendingQueueJobs($queue);
        if ($remainingJobs !== null && $remainingJobs > 0) {
            $this->warn("Queue drain stopped with {$remainingJobs} [{$queue}] job(s) still pending.");
        }
    }

    private function queueDrainQueue(): string
    {
        $queue = $this->option('queue-drain-queue');

        return is_string($queue) && trim($queue) !== '' ? trim($queue) : 'sync';
    }

    private function pendingQueueJobs(string $queue): ?int
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        return (int) DB::table('jobs')
            ->where('queue', $queue)
            ->count();
    }

    /**
     * @return array<int, array{label: string, command: string, arguments: array<string, mixed>}>
     */
    private function registrySteps(SportsPipelineRegistry $registry, string $sport, string $mode, int $season, CarbonInterface $referenceDate): array
    {
        return $registry->pipelineSteps($sport, $mode, $registry->context(
            $referenceDate->toDateString(),
            $season,
        ));
    }

    /**
     * @param  array{label: string, command: string, arguments: array<string, mixed>}  $step
     */
    private function callRegistryStep(array $step): void
    {
        $this->info("Running {$step['label']}...");
        $this->callAndRecord($step['command'], $step['arguments'], null, 'operations-sentinel');
        $this->output->write(Artisan::output());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callAndRecord(string $command, array $arguments, ?string $sport = null, string $source = 'operations-sentinel'): int
    {
        $heartbeat = app(CommandHeartbeatService::class);
        $renderedCommand = $this->renderCommand($command, $arguments);

        try {
            $exitCode = Artisan::call($command, $arguments);

            if ($exitCode === self::SUCCESS) {
                $heartbeat->recordSuccess($renderedCommand, $sport, $source, [
                    'parent_command' => 'sports:operations-sentinel',
                    'arguments' => $arguments,
                ]);
            } else {
                $heartbeat->recordFailure($renderedCommand, $sport, $source, 'Command exited with code '.$exitCode, [
                    'parent_command' => 'sports:operations-sentinel',
                    'arguments' => $arguments,
                ]);
            }

            return $exitCode;
        } catch (\Throwable $exception) {
            $heartbeat->recordFailure($renderedCommand, $sport, $source, $exception->getMessage(), [
                'parent_command' => 'sports:operations-sentinel',
                'arguments' => $arguments,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function renderCommand(string $command, array $arguments): string
    {
        $renderedArguments = collect($arguments)
            ->flatMap(function (mixed $value, string $key): array {
                if (is_bool($value)) {
                    return $value ? [$key] : [];
                }

                if (is_array($value)) {
                    return collect($value)
                        ->map(fn (mixed $item): string => $key.'='.$item)
                        ->all();
                }

                if ($value === null || $value === '') {
                    return [];
                }

                return [$key.'='.$value];
            })
            ->values()
            ->all();

        return trim($command.' '.implode(' ', $renderedArguments));
    }

    private function isAlreadyHandledSyncStep(string $label): bool
    {
        return str_contains(strtolower($label), 'scoreboard')
            || str_contains(strtolower($label), 'current')
            || str_contains(strtolower($label), 'game details');
    }

    private function defaultScoreboardFromDate(string $sport, int $season, int $pastStatusLookbackDays): string
    {
        if ($sport === 'mlb') {
            $openerDate = MlbRegularSeasonWindow::openerDate($season) ?: sprintf('%d-03-01', $season);

            if ($this->mlbSeasonToDateScoreboardIsNeeded($season, $openerDate)) {
                return $openerDate;
            }
        }

        return now()->subDays($pastStatusLookbackDays)->toDateString();
    }

    private function mlbSeasonToDateScoreboardIsNeeded(int $season, string $openerDate): bool
    {
        $today = now()->toDateString();
        $daysSinceOpener = max(0, Carbon::parse($openerDate)->diffInDays(Carbon::parse($today)));

        if ($daysSinceOpener < 14) {
            return false;
        }

        $completedGames = MlbGame::query()
            ->where('season', $season)
            ->where('status', config('mlb.statuses.final'))
            ->whereIn('season_type', config('mlb.season.analytics_types', [2, 3]))
            ->whereDate('game_date', '>=', $openerDate)
            ->whereDate('game_date', '<=', $today)
            ->count();

        $stalePastGames = MlbGame::query()
            ->where('season', $season)
            ->whereIn('status', [
                config('mlb.statuses.scheduled'),
                config('mlb.statuses.delayed'),
                config('mlb.statuses.in_progress'),
            ])
            ->whereDate('game_date', '>=', $openerDate)
            ->whereDate('game_date', '<', $today)
            ->exists();

        $minimumExpectedCompletedGames = max(30, (int) floor(($daysSinceOpener - 7) * 6));

        return $stalePastGames || $completedGames < $minimumExpectedCompletedGames;
    }

    private function defaultSeason(string $sport): int
    {
        $currentYear = (int) now()->year;

        return match ($sport) {
            'nfl', 'cfb', 'cbb', 'wcbb' => now()->month <= 2 ? $currentYear - 1 : $currentYear,
            default => $currentYear,
        };
    }
}
